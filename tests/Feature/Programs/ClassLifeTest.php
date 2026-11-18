<?php

namespace Tests\Feature\Programs;

use App\Enums\Role;
use App\Models\Assignment;
use App\Models\Schedule;
use App\Models\Student;
use App\Models\Teacher;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;
use Tests\TestCase;

class ClassLifeTest extends TestCase
{
    private array $s;

    private array $kids = [];

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->s = $this->makeSchool(['subdomain' => 'smkn1']);
        $this->tenant()->run($this->s['school'], function () {
            $this->s['classroom']->update(['homeroom_teacher_id' => $this->s['teacher']->id]);
            $this->s['student']->update(['class_role' => 'sekretaris_1']);
            foreach (['Bima Saputra', 'Citra Lestari', 'Dimas Pratama', 'Eka Wulandari', 'Fajar Nugroho', 'Gita Permata'] as $i => $n) {
                $u = User::factory()->create(['role' => Role::Student, 'username' => '10'.($i + 10)]);
                $this->kids[] = Student::create(['user_id' => $u->id, 'nis' => '10'.($i + 10), 'name' => $n, 'classroom_id' => $this->s['classroom']->id])->setRelation('user', $u);
            }
            Schedule::create(['classroom_id' => $this->s['classroom']->id, 'teacher_id' => $this->s['teacher']->id, 'subject' => 'Farmakologi', 'day' => 1, 'period_start' => 1, 'period_count' => 2]);
        });
        $this->tenant()->forget();
    }

    private function api(string $p): string
    {
        return $this->at($this->s['school'], 'api/'.$p);
    }

    public function test_struktur_kelas_siswa_menampilkan_wali_pengurus_dan_anggota(): void
    {
        $r = $this->actingAs($this->kids[0]->user)->getJson($this->api('my-class'))->assertOk()->json();
        $this->assertSame($this->s['teacher']->name, $r['homeroom']['name']);
        $this->assertSame('Aulia Rahma', collect($r['officers'])->firstWhere('role', 'sekretaris_1')['name']);
        $this->assertNull(collect($r['officers'])->firstWhere('role', 'ketua')['name']);
        $this->assertCount(7, $r['members']);
        $this->assertSame('Anggota', $r['my_role']);
        $this->assertFalse($r['can_write_notes']);
        $this->assertSame([1, 2, 3, 4, 5], array_keys($r['roster']['days']));
    }

    public function test_jadwal_piket_hanya_wali_kelas_acak_simpan_ekspor_impor(): void
    {
        $id = $this->s['classroom']->id;
        // Guru lain (bukan wali) ditolak; siswa ditolak.
        $otherUser = $this->tenant()->run($this->s['school'], function () {
            $u = User::factory()->create(['role' => Role::Teacher, 'username' => '9']);
            Teacher::create(['user_id' => $u->id, 'nik' => '9', 'name' => 'Guru Lain']);

            return $u;
        });
        $this->tenant()->forget();
        $this->actingAs($otherUser)->postJson($this->api("rosters/$id/random"))->assertForbidden();
        $this->actingAs($this->kids[0]->user)->getJson($this->api("rosters/$id"))->assertForbidden();

        $w = fn () => $this->actingAs($this->s['teacher']->user);
        $w()->postJson($this->api("rosters/$id/random"))->assertOk()->assertJsonPath('message', 'Jadwal piket disusun acak dari 7 siswa.');
        $g = $w()->getJson($this->api("rosters/$id"))->json('grid');
        $this->assertSame(7, array_sum(array_map('count', $g)));

        // Simpan manual: Aulia di Senin & Rabu; id asing (siswa kelas lain) dibuang.
        $w()->putJson($this->api("rosters/$id"), ['grid' => ['1' => [$this->s['student']->id, 999999], '3' => [$this->s['student']->id]]])->assertOk();
        $mine = $this->actingAs($this->s['student']->user)->getJson($this->api('my-class'))->json('roster');
        $this->assertSame(['Senin', 'Rabu'], $mine['mine']);
        $this->assertSame([['id' => $this->s['student']->id, 'name' => 'Aulia Rahma']], $mine['grid'][1]);

        // Ekspor format .xlsx
        $res = $w()->get($this->api("rosters/$id/export"))->assertOk();
        $this->assertStringContainsString('JADWAL_PIKET_X_FKK_1.xlsx', $res->headers->get('content-disposition'));

        // Impor: nama boleh tidak persis (huruf kecil, nama depan+tengah), nama asing dilewati.
        $tmp = tempnam(sys_get_temp_dir(), 'pk').'.xlsx';
        $x = new Writer; $x->openToFile($tmp);
        foreach ([[], ['', 'JADWAL PIKET'], ['', 'SENIN', 'SELASA', 'RABU', 'KAMIS', 'JUMAT'], ['', 'bima saputra', 'Citra', 'Dimas Pratama', 'Orang Asing', 'Gita Permata']] as $row) {
            $x->addRow(Row::fromValues($row));
        }
        $x->close();
        $w()->post($this->api("rosters/$id/import"), ['file' => new UploadedFile($tmp, 'FORMAT_JADWAL_PIKET.xlsx', null, null, true)], ['Accept' => 'application/json'])
            ->assertOk()->assertJsonPath('message', 'Jadwal piket diimpor: 4 petugas, 1 nama tidak cocok dan dilewati.');
        $g = $w()->getJson($this->api("rosters/$id"))->json('grid');
        $this->assertSame('Bima Saputra', $g[1][0]['name']);
        $this->assertSame('Citra Lestari', $g[2][0]['name']);
        $this->assertSame([], $g[4]);

        // Format kosong → acak
        $tmp2 = tempnam(sys_get_temp_dir(), 'pk').'.xlsx';
        $x = new Writer; $x->openToFile($tmp2); $x->addRow(Row::fromValues(['SENIN', 'SELASA', 'RABU', 'KAMIS', 'JUMAT'])); $x->close();
        $w()->post($this->api("rosters/$id/import"), ['file' => new UploadedFile($tmp2, 'f.xlsx', null, null, true)], ['Accept' => 'application/json'])
            ->assertOk()->assertJsonPath('message', 'Format masih kosong: jadwal piket diisi acak dari daftar siswa.');
    }

    public function test_jurnal_kelas_diisi_pengurus_dibaca_kelas_dan_guru(): void
    {
        $this->actingAs($this->kids[0]->user)->postJson($this->api('class-notes'), ['date' => now()->toDateString(), 'note' => 'Kelas bersih.'])->assertForbidden();
        $this->actingAs($this->s['student']->user)->postJson($this->api('class-notes'), ['date' => now()->addDays(3)->toDateString(), 'note' => 'x'])->assertStatus(422);
        $this->actingAs($this->s['student']->user)->postJson($this->api('class-notes'), ['date' => now()->toDateString(), 'note' => "Kerja bakti.\nSemua hadir."])->assertCreated();

        $this->actingAs($this->kids[0]->user)->getJson($this->api('class-notes'))->assertJsonCount(1)->assertJsonPath('0.role', 'Sekretaris 1')->assertJsonPath('0.author', 'Aulia Rahma');
        $this->actingAs($this->s['teacher']->user)->getJson($this->api('class-notes?classroom_id='.$this->s['classroom']->id))->assertJsonCount(1);
    }

    public function test_tugasku_folder_mapel_unggah_menandai_selesai_dan_terlihat_guru(): void
    {
        $task = $this->tenant()->run($this->s['school'], fn () => Assignment::create(['teacher_id' => $this->s['teacher']->id, 'classroom_id' => $this->s['classroom']->id,
            'subject' => 'Farmakologi', 'title' => 'Rangkum bab 2', 'due_date' => now()->addDays(3)->toDateString()]));
        $this->tenant()->forget();

        $f = $this->actingAs($this->s['student']->user)->getJson($this->api('my-folders'))->assertOk()->json();
        $this->assertSame(['Farmakologi'], array_column($f['folders'], 'name'));
        $this->assertSame(1, $f['pending']);

        $this->actingAs($this->s['student']->user)->post($this->api('assignment-files'), ['folder' => 'Farmakologi', 'assignment_id' => $task->id,
            'file' => UploadedFile::fake()->image('rangkuman.jpg', 900, 1200)], ['Accept' => 'application/json'])->assertCreated()->assertJsonPath('message', 'Berkas terkirim, tugas ditandai selesai.');
        $f = $this->actingAs($this->s['student']->user)->getJson($this->api('my-folders'))->json();
        $this->assertSame(0, $f['pending']);
        $this->assertSame('Rangkum bab 2', $f['folders'][0]['files'][0]['task']);

        // Guru melihat berkas siswa di detail tugas.
        $d = $this->actingAs($this->s['teacher']->user)->getJson($this->api('assignments/'.$task->id))->json('students');
        $aulia = collect($d)->firstWhere('nis', '1001');
        $this->assertTrue($aulia['done']);
        $this->assertSame('rangkuman.webp', $aulia['files'][0]['name']);

        // Siswa lain tidak bisa menghapus berkas Aulia; tugas kelas lain ditolak.
        $fid = $f['folders'][0]['files'][0]['id'];
        $this->actingAs($this->kids[0]->user)->deleteJson($this->api('assignment-files/'.$fid))->assertForbidden();
        $this->actingAs($this->s['student']->user)->deleteJson($this->api('assignment-files/'.$fid))->assertOk();
    }
}
