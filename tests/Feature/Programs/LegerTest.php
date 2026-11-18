<?php

namespace Tests\Feature\Programs;

use App\Enums\Role;
use App\Models\Classroom;
use App\Models\Student;
use App\Models\Teacher;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;
use Tests\TestCase;

class LegerTest extends TestCase
{
    private array $s;

    private Student $b;

    private Student $c;

    private Classroom $noWali;

    protected function setUp(): void
    {
        parent::setUp();
        $this->s = $this->makeSchool(['subdomain' => 'smkn1', 'name' => 'SMK Contoh', 'address' => 'Jl. Merdeka 1']);
        $this->tenant()->run($this->s['school'], function () {
            $this->s['classroom']->update(['homeroom_teacher_id' => $this->s['teacher']->id, 'grade' => 'XII']);
            foreach ([['1002', 'Bima'], ['1003', 'Citra']] as [$nis, $nm]) {
                $u = User::factory()->create(['role' => Role::Student, 'username' => $nis]);
                $this->{$nm === 'Bima' ? 'b' : 'c'} = Student::create(['user_id' => $u->id, 'nis' => $nis, 'name' => $nm, 'classroom_id' => $this->s['classroom']->id]);
            }
            $this->noWali = Classroom::create(['name' => 'X-FKK 9']);
        });
        $this->tenant()->forget();
    }

    private function api(string $p): string
    {
        return $this->at($this->s['school'], 'api/'.$p);
    }

    private function admin()
    {
        return $this->actingAs($this->s['admin']);
    }

    private function wali()
    {
        return $this->actingAs($this->s['teacher']->user);
    }

    private function kelas(): string
    {
        return 'leger/classes/'.$this->s['classroom']->id;
    }

    public function test_admin_atur_periode_dan_distribusi_hanya_ke_kelas_berwali(): void
    {
        $this->admin()->putJson($this->api('leger/settings'), ['semester' => 'Gasal', 'year' => '2026-2027'])->assertStatus(422);
        $this->admin()->putJson($this->api('leger/settings'), ['semester' => 'Gasal', 'year' => '2026/2027', 'principal_name' => 'Dra. Kepala Sekolah', 'city' => 'Banyumas'])->assertOk();
        $this->admin()->postJson($this->api('leger/distribute'), ['classroom_id' => $this->noWali->id])->assertStatus(422);

        // Sebelum distribusi: wali belum bisa membuka lembar.
        $this->wali()->getJson($this->api($this->kelas()))->assertForbidden();
        $this->admin()->postJson($this->api('leger/distribute'))->assertOk()->assertJsonPath('message', 'Leger didistribusikan ke 1 wali kelas.');

        $r = $this->admin()->getJson($this->api('leger'))->assertOk()->json();
        $this->assertSame('Gasal 2026/2027', $r['term']);
        $this->assertSame(11, count($r['structure']['subjects']));
        $this->assertSame(['distributed', 'no_homeroom'], array_column($r['classes'], 'state'));

        // Wali melihat kelasnya; guru lain tidak.
        $this->wali()->getJson($this->api('leger'))->assertJsonPath('classes.0.active', true);
        $this->wali()->getJson($this->api($this->kelas()))->assertOk()->assertJsonPath('editable', true)->assertJsonPath('classroom.phase', 'F');
        // Admin hanya pantau (baca saja).
        $this->admin()->getJson($this->api($this->kelas()))->assertOk()->assertJsonPath('editable', false);
        $this->admin()->putJson($this->api($this->kelas().'/students/'.$this->b->id), ['scores' => [90]])->assertForbidden();
    }

    public function test_wali_mengisi_nilai_capaian_ekstra_hadir_dan_ranking_otomatis(): void
    {
        $this->admin()->postJson($this->api('leger/distribute'))->assertOk();
        $put = fn (Student $s, array $d) => $this->wali()->putJson($this->api($this->kelas().'/students/'.$s->id), $d);

        $put($this->b, ['scores' => [120]])->assertStatus(422);
        // Jumlah sama (170) → pengurai seri: B.Indonesia (indeks 2) lebih tinggi menang.
        $put($this->b, ['scores' => [80, null, 90]])->assertOk();
        $put($this->c, ['scores' => [90, null, 80]])->assertOk();
        $put($this->s['student'], ['scores' => [70, 70], 'extras' => ['A', '', 'B'], 'absences' => [1, 2, 0]])->assertOk();

        $rows = collect($this->wali()->getJson($this->api($this->kelas()))->json('rows'))->keyBy('student.name');
        $this->assertSame([1, 2, 3], [$rows['Bima']['rank'], $rows['Citra']['rank'], $rows['Aulia Rahma']['rank']]);
        $this->assertEquals(85, $rows['Bima']['average']);
        $this->assertEquals(170, $rows['Bima']['sum']);
        $this->assertSame([1, 2, 0], $rows['Aulia Rahma']['absences']);

        // Capaian bawaan dari master; terapkan teks siswa pertama ke semua.
        $this->assertStringContainsString('sabar', $rows['Bima']['competencies'][0][0]);
        $first = collect($this->wali()->getJson($this->api($this->kelas()))->json('rows'))->first();
        $comp = $first['competencies']; $comp[6] = ['statistika dasar', 'peluang'];
        $put(Student::query()->withoutGlobalScopes()->find($first['student']['id']), ['competencies' => $comp])->assertOk();
        $this->wali()->postJson($this->api($this->kelas().'/apply-competency'), ['subject' => 6])->assertOk()->assertJsonPath('message', 'Teks siswa pertama diterapkan ke 3 siswa.');
        $this->assertSame(['statistika dasar', 'peluang'], $this->wali()->getJson($this->api($this->kelas()))->json('rows.2.competencies.6'));

        // Rapor cetak
        $this->admin()->putJson($this->api('leger/settings'), ['semester' => 'Gasal', 'year' => '2026/2027', 'principal_name' => 'Dra. Kepala Sekolah'])->assertOk();
        $this->admin()->postJson($this->api('leger/distribute'))->assertOk(); // periode baru → distribusi ulang
        $this->wali()->get($this->at($this->s['school'], 'rapor/'.$this->s['classroom']->id.'?siswa='.$this->s['student']->id))->assertOk()
            ->assertSee('LAPORAN HASIL BELAJAR')->assertSee('Aulia Rahma')->assertSee('Dra. Kepala Sekolah')->assertSee('SMK Contoh');
    }

    public function test_ganti_wali_menunggu_distribusi_ulang_dan_guru_lain_ditolak(): void
    {
        $this->admin()->postJson($this->api('leger/distribute'))->assertOk();
        $other = $this->tenant()->run($this->s['school'], function () {
            $u = User::factory()->create(['role' => Role::Teacher, 'username' => '77']);
            $t = Teacher::create(['user_id' => $u->id, 'nik' => '77', 'name' => 'Wali Baru']);

            return [$u, $t];
        });
        $this->tenant()->forget();
        $this->actingAs($other[0])->getJson($this->api($this->kelas()))->assertForbidden();
        $this->actingAs($other[0])->get($this->at($this->s['school'], 'rapor/'.$this->s['classroom']->id))->assertForbidden();

        $this->tenant()->run($this->s['school'], fn () => $this->s['classroom']->update(['homeroom_teacher_id' => $other[1]->id]));
        $this->tenant()->forget();
        $this->admin()->getJson($this->api('leger'))->assertJsonPath('classes.0.state', 'redistribute');
        $this->actingAs($other[0])->getJson($this->api($this->kelas()))->assertForbidden();
    }

    public function test_unggah_master_xlsx_mengganti_mapel_dan_ekstra(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'lm').'.xlsx';
        $w = new Writer; $w->openToFile($tmp);
        $w->getCurrentSheet()->setName('DATA'); $w->addRow(Row::fromValues(['SEKOLAH', 'X']));
        $w->addNewSheetAndMakeItCurrent()->setName('CAPAIAN MATERI');
        $w->addRow(Row::fromValues(['', 'NAMA', 'NISN', 'Matematika', '', 'Informatika']));
        $w->addRow(Row::fromValues(['', '', '', 'SANGAT DIPAHAMI', 'BELUM DIPAHAMI', 'SANGAT DIPAHAMI', 'BELUM DIPAHAMI']));
        $w->addRow(Row::fromValues(['1', '', '', 'aljabar', 'geometri', 'algoritma', 'basis data']));
        $w->addNewSheetAndMakeItCurrent()->setName('nilai');
        $w->addRow(Row::fromValues(['', 'NAMA', 'NISN', 'Matematika', 'Informatika', 'rata rata', 'jumlah', 'ranking', 'EKTRAKULIKULER', 'Pramuka', 'Robotik', 'KEHADIRAN', 'Sakit']));
        $w->addNewSheetAndMakeItCurrent()->setName('RAPORT'); $w->addRow(Row::fromValues(['']));
        $w->close();

        $this->admin()->post($this->api('leger/master'), ['file' => new UploadedFile($tmp, 'LEGER_RAPOR.xlsx', null, null, true)], ['Accept' => 'application/json'])
            ->assertOk()->assertJsonPath('message', 'Master leger diganti: 2 mapel, 2 ekstrakurikuler.');
        $st = $this->admin()->getJson($this->api('leger'))->json('structure');
        $this->assertSame(['Matematika', 'Informatika'], array_column($st['subjects'], 'name'));
        $this->assertSame('basis data', $st['subjects'][1]['needs']);
        $this->assertSame(['Pramuka', 'Robotik'], $st['extracurriculars']);

        // Master tanpa sheet wajib ditolak; reset kembali ke bawaan.
        $bad = tempnam(sys_get_temp_dir(), 'lm').'.xlsx';
        $w = new Writer; $w->openToFile($bad); $w->addRow(Row::fromValues(['x'])); $w->close();
        $this->admin()->post($this->api('leger/master'), ['file' => new UploadedFile($bad, 'x.xlsx', null, null, true)], ['Accept' => 'application/json'])->assertStatus(422);
        $this->admin()->deleteJson($this->api('leger/master'))->assertOk();
        $this->assertSame(11, count($this->admin()->getJson($this->api('leger'))->json('structure.subjects')));

        // Guru tidak bisa mengganti master.
        $this->wali()->deleteJson($this->api('leger/master'))->assertForbidden();
    }
}
