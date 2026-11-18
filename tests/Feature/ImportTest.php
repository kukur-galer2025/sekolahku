<?php

namespace Tests\Feature;

use App\Models\Classroom;
use App\Models\DataImport;
use App\Models\LessonPeriod;
use App\Models\Student;
use App\Models\Teacher;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;
use Tests\TestCase;

class ImportTest extends TestCase
{
    private array $s;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->s = $this->makeSchool(['subdomain' => 'smkn1', 'student_quota' => null]);
    }

    /** Buat .xlsx nyata: [nama sheet => baris[]] */
    private function xlsx(array $sheets, string $name = 'data.xlsx'): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'xl').'.xlsx';
        $w = new Writer;
        $w->openToFile($path);
        $first = true;
        foreach ($sheets as $title => $rows) {
            $sheet = $first ? $w->getCurrentSheet() : $w->addNewSheetAndMakeItCurrent();
            $sheet->setName($title);
            $first = false;
            foreach ($rows as $r) {
                $w->addRow(Row::fromValues($r));
            }
        }
        $w->close();

        return new UploadedFile($path, $name, 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
    }

    private function upload(string $type, UploadedFile $file)
    {
        return $this->actingAs($this->s['admin'])->post($this->at($this->s['school'], "impor/$type"), ['file' => $file]);
    }

    private function confirm(DataImport $i, string $mode = 'add')
    {
        return $this->actingAs($this->s['admin'])->post($this->at($this->s['school'], "impor/{$i->id}/konfirmasi"), ['mode' => $mode]);
    }

    private function lastImport(): DataImport
    {
        return $this->tenant()->run($this->s['school'], fn () => DataImport::latest('id')->firstOrFail());
    }

    private function inSchool(callable $fn): mixed
    {
        return $this->tenant()->run($this->s['school'], $fn);
    }

    // ---------- siswa ----------

    private function studentFile(): UploadedFile
    {
        return $this->xlsx([
            'X - FKK 1' => [[], ['NISN', 'NIS', 'NAMA', 'L/P'], ['0091', '1001', 'Aulia Rahma (diperbarui)', 'P'], ['0092', '1002', 'Bima Pratama', 'L'], ['', '', '', '']],
            'XI - ASKEP 1' => [[], ['NISN', 'NIS', 'NAMA', 'GENDER', 'TANGGAL LAHIR'], ['0093', '2001', 'Gita Maharani', 'P', '14/02/2008'], ['0094', '', 'Hadi Santoso', 'L', '2008-09-25']],
            'XII -' => [[], ['NISN', 'NIS', 'NAMA', 'GENDER']], // sheet template kosong
        ]);
    }

    public function test_pratinjau_siswa_tidak_mengubah_data(): void
    {
        $this->upload('students', $this->studentFile())->assertRedirect();
        $i = $this->lastImport();

        $this->assertSame('preview', $i->status);
        $this->assertTrue($i->preview['ok']);
        $this->assertSame(4, $i->preview['stats']['Total siswa terbaca']);
        $this->assertSame(3, $i->preview['stats']['Siswa baru (dibuatkan akun)']);
        $this->assertSame(['XI-ASKEP 1'], $i->preview['new_classes']);
        $this->assertSame(1, $this->inSchool(fn () => Student::count()), 'pratinjau tidak boleh menulis data');
    }

    public function test_impor_siswa_membuat_akun_kelas_dan_bisa_login(): void
    {
        $this->upload('students', $this->studentFile());
        $this->confirm($this->lastImport())->assertRedirect();
        $i = $this->lastImport();

        $this->assertSame('done', $i->status, (string) $i->error);
        $this->assertSame(3, $i->result['Siswa baru']);
        $this->assertSame(1, $i->result['Siswa diperbarui']);

        $this->inSchool(function () {
            $this->assertSame('Aulia Rahma (diperbarui)', Student::where('nis', '1001')->value('name'));
            $this->assertSame('XI-ASKEP 1', Student::where('nis', '2001')->first()->classroom->name);
            $this->assertSame('0094', Student::where('nis', '0094')->value('nisn'), 'NIS kosong → NISN jadi username');
            $this->assertSame('2008-02-14', Student::where('nis', '2001')->first()->birth_date->toDateString());
            $this->assertFalse(Hash::check('2008-02-14', User::where('username', '2001')->value('password')), 'password awal tidak boleh tanggal lahir');
        });

        // Semua siswa mendapat password acak yang tercantum di daftar akun
        $cred = collect($i->credentials)->firstWhere(1, '1002');
        $this->assertNotNull($cred);
        $this->assertMatchesRegularExpression('/^[a-z2-9]{8}$/', $cred[4]);
        $this->inSchool(fn () => $this->assertTrue(Hash::check($cred[4], User::where('username', '1002')->value('password'))));
    }

    public function test_mode_ganti_menonaktifkan_siswa_yang_tidak_ada_di_file_tanpa_menghapus(): void
    {
        $this->inSchool(fn () => Student::create(['user_id' => User::factory()->create(['role' => 'student', 'username' => '9999'])->id, 'nis' => '9999', 'name' => 'Sudah Lulus', 'classroom_id' => $this->s['classroom']->id]));

        $this->upload('students', $this->studentFile());
        $this->confirm($this->lastImport(), 'replace');

        $this->inSchool(function () {
            $gone = Student::where('nis', '9999')->first();
            $this->assertFalse($gone->is_active);
            $this->assertFalse($gone->user->is_active, 'akun ikut dinonaktifkan agar tidak bisa login');
            $this->assertTrue(Student::where('nis', '1001')->value('is_active'));
        });
        $this->assertSame(1, $this->lastImport()->result['Dinonaktifkan']);
    }

    public function test_kuota_paket_menahan_impor(): void
    {
        // Kuota keras berlaku untuk sekolah trial; sekolah aktif hanya dibatasi paket (siswa tambahan ditagih otomatis).
        $this->s['school']->update(['student_quota' => 3, 'status' => \App\Enums\SchoolStatus::Trial, 'trial_ends_at' => now()->addDays(7)]); // file berisi 4 siswa
        $this->upload('students', $this->studentFile());
        $i = $this->lastImport();

        $this->assertFalse($i->preview['ok']);
        $this->assertStringContainsString('melebihi kuota paket (3)', $i->preview['errors'][0]);
        $this->confirm($i)->assertStatus(422);
        $this->assertSame(1, $this->inSchool(fn () => Student::count()));
    }

    // ---------- guru ----------

    public function test_impor_guru_membuat_akun_memperbarui_dan_menetapkan_wali_kelas(): void
    {
        $file = $this->xlsx(['Sheet1' => [
            [], ['', 'DATABASE GURU'], [],
            ['', 'NIK', 'NAMA', 'MATA PELAJARAN', 'JABATAN', 'WALI KELAS'],
            ['', '3301010101', 'Guru Lama Ganti Nama', 'Biologi', 'Guru', ''],
            ['', '10.10.05.19', 'Bu Sari', 'Matematika', 'Wakasek Kurikulum', 'X - FKK 1'],
            ['', '', 'Tanpa NIK', 'IPA', 'Guru', ''],
        ]]);

        $this->upload('teachers', $file);
        $i = $this->lastImport();
        $this->assertSame(1, $i->preview['stats']['Guru baru (dibuatkan akun)']);
        $this->assertStringContainsString('1 guru tanpa NIK dilewati', implode(' ', $i->preview['warnings']));

        $this->confirm($i);
        $i = $this->lastImport();
        $this->assertSame('done', $i->status, (string) $i->error);

        $this->inSchool(function () {
            $sari = Teacher::where('nik', '10.10.05.19')->first();
            $this->assertSame('Wakasek Kurikulum', $sari->position);
            $this->assertSame($sari->id, Classroom::where('name', 'X-FKK 1')->value('homeroom_teacher_id'));
            $this->assertSame('Guru Lama Ganti Nama', Teacher::where('nik', '3301010101')->value('name'));
            $this->assertSame(2, Teacher::count(), 'guru tanpa NIK tidak dibuat');
        });

        $pw = collect($i->credentials)->firstWhere(1, '10.10.05.19')[4];
        auth()->logout();
        $this->postJson($this->at($this->s['school'], 'api/auth/login'), ['username' => '10.10.05.19', 'password' => $pw])->assertOk();

        // Login pertama menaikkan cost hash ke standar
        $this->inSchool(fn () => $this->assertFalse(Hash::needsRehash(User::where('username', '10.10.05.19')->value('password'))));
    }

    // ---------- Jam KBM ----------

    public function test_impor_jam_kbm_mengganti_tabel_dan_menolak_jam_bertabrakan(): void
    {
        $ok = $this->xlsx(['JAM' => [['SESI', 'MULAI', 'SELESAI', 'KETERANGAN'], ['1', '07.00', '07.40', ''], ['Istirahat', '07:40', '08:00', 'Istirahat'], ['2', '08:00', '08:40', '']]]);
        $this->upload('lesson_periods', $ok);
        $this->confirm($this->lastImport());

        $this->inSchool(function () {
            $this->assertSame(['07:00', '07:40', '08:00'], LessonPeriod::orderBy('sort')->pluck('starts_at')->map(fn ($t) => substr($t, 0, 5))->all());
            $this->assertTrue(LessonPeriod::where('sort', 2)->value('is_break'));
        });

        $bad = $this->xlsx(['JAM' => [['SESI', 'MULAI', 'SELESAI'], ['1', '07:00', '07:40'], ['2', '07:30', '08:10']]]);
        $this->upload('lesson_periods', $bad);
        $this->assertFalse($this->lastImport()->preview['ok']);
        $this->assertStringContainsString('bertabrakan', $this->lastImport()->preview['errors'][0]);
    }

    // ---------- keamanan ----------

    public function test_hanya_admin_yang_bisa_impor_dan_file_harus_excel(): void
    {
        $this->actingAs($this->s['teacher']->user)->post($this->at($this->s['school'], 'impor/students'), ['file' => $this->studentFile()])->assertForbidden();

        $this->actingAs($this->s['admin'])->post($this->at($this->s['school'], 'impor/students'), [
            'file' => UploadedFile::fake()->create('virus.exe', 10),
        ])->assertSessionHasErrors('file');
    }

    public function test_impor_sekolah_lain_tidak_bisa_dibuka_atau_dikonfirmasi(): void
    {
        $this->upload('students', $this->studentFile());
        $i = $this->lastImport();
        $b = $this->makeSchool(['subdomain' => 'smkn2']);

        $this->actingAs($b['admin'])->get($this->at($b['school'], "impor/{$i->id}"))->assertNotFound();
        $this->actingAs($b['admin'])->post($this->at($b['school'], "impor/{$i->id}/konfirmasi"), ['mode' => 'add'])->assertNotFound();
        $this->actingAs($b['admin'])->get($this->at($b['school'], "impor/{$i->id}/akun.csv"))->assertNotFound();
    }

    public function test_berkas_disimpan_privat_per_sekolah_dan_dibersihkan_setelah_7_hari(): void
    {
        $this->upload('students', $this->studentFile());
        $i = $this->lastImport();
        $path = $i->getRawOriginal('file_path');
        $this->assertStringStartsWith('school_'.$this->s['school']->id.'/imports/', $path);
        Storage::disk('local')->assertExists($path);
        $this->confirm($i);
        $this->assertNotNull($this->lastImport()->credentials);

        $this->travel(6)->days();
        \App\Services\Import\ImportManager::prune(7);
        Storage::disk('local')->assertExists($path); // belum 7 hari

        $this->travel(2)->days();
        \App\Services\Import\ImportManager::prune(7);
        Storage::disk('local')->assertMissing($path);
        $this->assertNull($this->lastImport()->credentials);
    }

    public function test_daftar_akun_csv_berisi_password_awal(): void
    {
        $this->upload('students', $this->studentFile());
        $this->confirm($this->lastImport());

        $csv = $this->actingAs($this->s['admin'])->get($this->at($this->s['school'], 'impor/'.$this->lastImport()->id.'/akun.csv'))
            ->assertOk()->assertHeader('content-type', 'text/csv; charset=UTF-8')->streamedContent();

        $this->assertStringContainsString('Peran;Username;Nama;Kelas;"Password awal"', $csv);
        // Password awal selalu acak (bukan tanggal lahir 2008-02-14).
        $this->assertMatchesRegularExpression('/^Siswa;2001;"Gita Maharani";"XI-ASKEP 1";[a-z2-9]{8}\r?$/m', $csv);
        $this->assertStringNotContainsString('2008-02-14', $csv);
    }
}
