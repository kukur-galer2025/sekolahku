<?php

namespace Tests\Feature\Platform;

use App\Models\DataImport;
use App\Models\LessonPeriod;
use App\Models\Schedule;
use App\Models\Teacher;
use App\Models\User;
use App\Services\Import\TimetablePdfReader;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Impor jadwal & Jam KBM dari PDF cetakan aSc Timetables.
 * tests/fixtures/jadwal-asc.pdf berisi data fiktif: hal. 1 jadwal kelas "X - FKK 1", hal. 2 jadwal guru "Sari Wulandari".
 */
class PdfScheduleImportTest extends TestCase
{
    /** jadwal-asc.pdf: hal. kelas + hal. guru; jadwal-asc-kelas.pdf / jadwal-asc-guru.pdf: satu jenis saja. */
    private function fixture(string $name = 'jadwal-asc'): string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'pdf').'.pdf';
        copy(base_path("tests/fixtures/$name.pdf"), $tmp);

        return $tmp;
    }

    private function upload(array $s, string $type, string $path, string $name = 'JADWAL.pdf'): DataImport
    {
        $this->actingAs($s['admin'])->post($this->at($s['school'], "impor/$type"), ['file' => new UploadedFile($path, $name, 'application/pdf', null, true)]);

        return $this->tenant()->run($s['school'], fn () => DataImport::latest('id')->first());
    }

    public function test_pembaca_pdf_mengikuti_isi_tabel_aSc(): void
    {
        $sheets = (new TimetablePdfReader)->read(base_path('tests/fixtures/jadwal-asc.pdf'));
        $rows = array_slice($sheets['Jadwal (PDF)'], 1);

        $this->assertContains(['SENIN', '1', '2', 'X - FKK 1', 'MATEMATIKA', 'Sari Wulandari, S.Pd., Gr.'], $rows); // nama guru 2 baris digabung
        $this->assertContains(['SENIN', '3', '1', 'X - FKK 1', 'INFORMATIKA', 'Apt.Budi Santoso, S.Farm.'], $rows); // "INFORMATIK" + "A" disambung
        $this->assertContains(['SELASA', '1', '2', 'X - FKK 1', 'BAHASA INGGRIS', 'Pak Entah'], $rows);
        $this->assertContains(['KAMIS', '3', '1', 'X - FKK 2', 'MATEMATIKA', 'Sari Wulandari, S.Pd., Gr.'], $rows); // dari halaman jadwal guru
        $this->assertCount(6, $rows); // blok yang muncul di jadwal kelas & jadwal guru hanya sekali
        $this->assertCount(5, array_slice($sheets[TimetablePdfReader::SHEET_CLASS], 1));
        $this->assertCount(3, array_slice($sheets[TimetablePdfReader::SHEET_TEACHER], 1));

        $periods = array_slice($sheets['Jam KBM (PDF)'], 1);
        $this->assertSame(['1', '07:00', '07:40', ''], $periods[0]);
        $this->assertSame(['Istirahat', '09:00', '09:15', 'Istirahat'], $periods[3]);
        $this->assertCount(12, $periods);
    }

    private function seedTeachers(array $s): void
    {
        $this->tenant()->run($s['school'], function () {
            foreach (['3301010102' => 'Sari Wulandari', '3301010103' => 'Budi Santoso'] as $nik => $name) {
                $u = User::factory()->create(['role' => 'teacher', 'username' => $nik]);
                Teacher::create(['user_id' => $u->id, 'nik' => $nik, 'name' => $name]);
            }
        });
    }

    public function test_kartu_jadwal_kelas_memakai_halaman_jadwal_kelas_dan_cocokkan_guru_tanpa_gelar(): void
    {
        Storage::fake('local');
        $s = $this->makeSchool(['subdomain' => 'smk-pdf']);
        $this->seedTeachers($s);
        $this->actingAs($s['admin'])->get($this->at($s['school'], 'impor'))->assertOk()->assertSee('Jadwal kelas')->assertSee('Jadwal mengajar');

        $i = $this->upload($s, 'class_schedules', $this->fixture());
        $this->assertSame('Jadwal Kelas', $i->typeLabel());
        $this->assertTrue($i->preview['ok'], implode(' | ', $i->preview['errors']));
        $this->assertSame(5, $i->preview['stats']['Blok jadwal']); // halaman guru diabaikan
        $this->assertSame([], $i->preview['new_classes']);
        $this->assertStringContainsString('Pak Entah', implode(' | ', $i->preview['warnings'])); // guru tak dikenal

        $this->actingAs($s['admin'])->post($this->at($s['school'], "impor/{$i->id}/konfirmasi"), ['mode' => 'add']);

        $this->tenant()->run($s['school'], function () {
            $mat = Schedule::where('subject', 'MATEMATIKA')->firstOrFail();
            $this->assertSame([1, 1, 2], [$mat->day, $mat->period_start, $mat->period_count]);
            $this->assertSame('Sari Wulandari', $mat->teacher->name);
            $this->assertSame('Budi Santoso', Schedule::where('subject', 'INFORMATIKA')->firstOrFail()->teacher->name); // "Apt.Budi Santoso, S.Farm."
            $this->assertSame(5, Schedule::where('subject', "Sholat Jum'at")->value('day'));
        });
    }

    public function test_kartu_jadwal_mengajar_memakai_halaman_jadwal_guru_dan_membuat_kelas_baru(): void
    {
        Storage::fake('local');
        $s = $this->makeSchool(['subdomain' => 'smk-pdf5']);
        $this->seedTeachers($s);

        $i = $this->upload($s, 'schedules', $this->fixture('jadwal-asc-guru'));
        $this->assertSame('Jadwal Mengajar', $i->typeLabel());
        $this->assertTrue($i->preview['ok'], implode(' | ', $i->preview['errors']));
        $this->assertSame(3, $i->preview['stats']['Blok jadwal']);
        $this->assertSame(['X-FKK 2'], $i->preview['new_classes']); // kelas dari PDF yang belum ada → dibuat otomatis
        $this->assertStringNotContainsString('Kelas belum ada', implode(' | ', $i->preview['warnings']));
        $this->actingAs($s['admin'])->get($this->at($s['school'], "impor/{$i->id}"))->assertOk()->assertSee('Kelas baru yang akan dibuat');

        $this->actingAs($s['admin'])->post($this->at($s['school'], "impor/{$i->id}/konfirmasi"), ['mode' => 'add']);
        $this->tenant()->run($s['school'], function () {
            $fkk2 = \App\Models\Classroom::where('name', 'X-FKK 2')->firstOrFail();
            $this->assertSame('X', $fkk2->grade);
            $this->assertSame('Sari Wulandari', Schedule::where('classroom_id', $fkk2->id)->firstOrFail()->teacher->name);
            $this->assertSame(2, \App\Models\Classroom::count()); // X-FKK 1 dipakai ulang, tidak dobel
            $this->assertSame(3, Schedule::count());
        });
    }

    public function test_pdf_salah_kartu_ditolak_dengan_petunjuk_kartu_yang_benar(): void
    {
        Storage::fake('local');
        $s = $this->makeSchool(['subdomain' => 'smk-pdf6']);

        $i = $this->upload($s, 'class_schedules', $this->fixture('jadwal-asc-guru'));
        $this->assertFalse($i->preview['ok']);
        $this->assertStringContainsString('kartu Jadwal mengajar', $i->preview['errors'][0]);

        $i = $this->upload($s, 'schedules', $this->fixture('jadwal-asc-kelas'));
        $this->assertFalse($i->preview['ok']);
        $this->assertStringContainsString('kartu Jadwal kelas', $i->preview['errors'][0]);
    }

    public function test_guru_dari_pdf_menjadi_excel_terisi_lalu_akun_dibuat_setelah_nik_diisi(): void
    {
        Storage::fake('local');
        $s = $this->makeSchool(['subdomain' => 'smk-pdf4']);
        $this->tenant()->run($s['school'], function () {
            $u = User::factory()->create(['role' => 'teacher', 'username' => '3301010103']);
            Teacher::create(['user_id' => $u->id, 'nik' => '3301010103', 'name' => 'Budi Santoso']);
        });

        $i = $this->upload($s, 'teachers', $this->fixture());
        $this->assertFalse($i->preview['ok']); // tanpa NIK tidak langsung disimpan
        $this->assertSame(['Guru di PDF' => 3, 'Sudah terdaftar' => 1, 'Baru (perlu NIK)' => 2], $i->preview['stats']);
        $this->actingAs($s['admin'])->get($this->at($s['school'], "impor/{$i->id}"))->assertOk()
            ->assertSee('Unduh Excel Data Guru')->assertDontSee('Simpan ke database sekolah');

        $res = $this->actingAs($s['admin'])->get($this->at($s['school'], "impor/{$i->id}/data-guru.xlsx"))->assertOk();
        $xlsx = $res->baseResponse->getFile()->getPathname();
        $sheet = (new \App\Services\Import\SpreadsheetReader)->read($xlsx, 'xlsx');
        $rows = array_values($sheet)[0];
        $this->assertSame(['NIK', 'NAMA', 'MATA PELAJARAN', 'JABATAN', 'WALI KELAS'], array_slice($rows[0], 0, 5));
        $byName = collect(array_slice($rows, 1))->keyBy(1);
        $this->assertSame('', $byName['Sari Wulandari, S.Pd., Gr.'][0] ?? '');
        $this->assertSame('MATEMATIKA, PENDIDIKAN PANCASILA', $byName['Sari Wulandari, S.Pd., Gr.'][2]);
        $this->assertSame('3301010103', $byName['Budi Santoso'][0]); // guru lama: NIK sudah terisi

        // Admin mengisi NIK lalu mengunggah Excel itu di kartu Data guru.
        $filled = tempnam(sys_get_temp_dir(), 'g').'.xlsx';
        $w = new \OpenSpout\Writer\XLSX\Writer;
        $w->openToFile($filled);
        foreach ($rows as $n => $r) {
            if ($n > 0 && ($r[0] ?? '') === '') {
                $r[0] = $r[1] === 'Pak Entah' ? '3301010199' : '3301010188';
            }
            $w->addRow(\OpenSpout\Common\Entity\Row::fromValues(array_map(fn ($v) => (string) $v, $r)));
        }
        $w->close();
        $j = $this->upload($s, 'teachers', $filled, 'Data_Guru_dari_PDF.xlsx');
        $this->assertTrue($j->preview['ok'], implode(' | ', $j->preview['errors']));
        $this->actingAs($s['admin'])->post($this->at($s['school'], "impor/{$j->id}/konfirmasi"), ['mode' => 'add']);
        $this->tenant()->run($s['school'], function () {
            $this->assertSame('Sari Wulandari, S.Pd., Gr.', Teacher::where('nik', '3301010188')->value('name'));
            $this->assertTrue(User::where('username', '3301010188')->exists());
            $this->assertSame(4, Teacher::count()); // guru pabrik + Budi + 2 baru, Budi tidak dobel
        });
    }

    public function test_impor_jam_kbm_dari_pdf_yang_sama(): void
    {
        Storage::fake('local');
        $s = $this->makeSchool(['subdomain' => 'smk-pdf2']);

        $i = $this->upload($s, 'lesson_periods', $this->fixture());
        $this->assertTrue($i->preview['ok'], implode(' | ', $i->preview['errors']));
        $this->assertEquals(['Jam pelajaran' => 10, 'Istirahat' => 2], $i->preview['stats']); // urutan kunci JSON MySQL bisa berbeda

        $this->actingAs($s['admin'])->post($this->at($s['school'], "impor/{$i->id}/konfirmasi"), ['mode' => 'add']);
        $this->tenant()->run($s['school'], function () {
            $this->assertSame(12, LessonPeriod::count());
            $this->assertTrue((bool) LessonPeriod::where('starts_at', 'like', '11:55%')->value('is_break'));
        });
    }

    public function test_pdf_hanya_untuk_jadwal_dan_jam_kbm_dan_pdf_tanpa_tabel_ditolak_dengan_jelas(): void
    {
        Storage::fake('local');
        $s = $this->makeSchool(['subdomain' => 'smk-pdf3']);

        $this->actingAs($s['admin'])->post($this->at($s['school'], 'impor/students'), ['file' => new UploadedFile($this->fixture(), 'siswa.pdf', 'application/pdf', null, true)])
            ->assertSessionHasErrors('file');

        $other = tempnam(sys_get_temp_dir(), 'pdf').'.pdf';
        copy(base_path('tests/fixtures/bukan-jadwal.pdf'), $other);
        $i = $this->upload($s, 'schedules', $other, 'surat.pdf');
        $this->assertFalse($i->preview['ok']);
        $this->assertStringContainsString('Tidak ada tabel jadwal', $i->preview['errors'][0]);

        $broken = tempnam(sys_get_temp_dir(), 'pdf').'.pdf';
        file_put_contents($broken, 'bukan pdf');
        $i = $this->upload($s, 'schedules', $broken, 'rusak.pdf');
        $this->assertStringContainsString('PDF tidak bisa dibuka', $i->preview['errors'][0]);
    }
}
