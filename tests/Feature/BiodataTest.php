<?php

namespace Tests\Feature;

use App\Models\Classroom;
use Tests\TestCase;

/** Biodata peserta didik: siswa mengisi sendiri, wali/admin melengkapi, cetak halaman identitas rapor. */
class BiodataTest extends TestCase
{
    public function test_siswa_mengisi_biodata_dan_mencetaknya(): void
    {
        $s = $this->makeSchool(['subdomain' => 'smkn1']);
        $api = fn ($p) => $this->at($s['school'], 'api/'.$p);
        $siswa = fn () => $this->fromDevice($s['deviceToken'])->actingAs($s['student']->user);

        $siswa()->putJson($api('me/biodata'), ['birth_place' => 'Banyumas', 'birth_date' => '2010-05-12', 'gender' => 'L', 'religion' => 'Islam',
            'father_name' => 'Slamet', 'accepted_date' => '2026-07-14', 'name' => 'Nama Diganti'])
            ->assertOk()->assertJsonPath('profile.birth_place', 'Banyumas')->assertJsonPath('student.birth_date', '2010-05-12');
        $siswa()->getJson($api('me/biodata'))->assertJsonPath('student.name', $s['student']->name); // siswa tidak bisa mengganti nama
        $siswa()->putJson($api('me/biodata'), ['accepted_date' => '14-07-2026'])->assertStatus(422);

        $siswa()->get($this->at($s['school'], 'biodata/cetak'))->assertOk()->assertSee('KETERANGAN TENTANG DIRI PESERTA DIDIK')->assertSee('Banyumas, 12 Mei 2010')->assertSee('Slamet');
        $siswa()->get($this->at($s['school'], 'biodata/cetak?kelas='.$s['classroom']->id))->assertForbidden();
    }

    public function test_hanya_wali_kelas_dan_admin_yang_bisa_membuka_biodata_siswa_lain(): void
    {
        $s = $this->makeSchool(['subdomain' => 'smkn1']);
        $api = fn ($p) => $this->at($s['school'], 'api/'.$p);
        $id = $s['student']->id;

        $this->actingAs($s['teacher']->user)->getJson($api("students/$id/biodata"))->assertForbidden(); // bukan wali kelasnya
        $this->tenant()->run($s['school'], fn () => Classroom::query()->whereKey($s['classroom']->id)->update(['homeroom_teacher_id' => $s['teacher']->id]));
        $this->tenant()->forget();
        $this->actingAs($s['teacher']->user)->putJson($api("students/$id/biodata"), ['mother_name' => 'Aminah', 'nisn' => '0101234567'])
            ->assertOk()->assertJsonPath('profile.mother_name', 'Aminah')->assertJsonPath('student.nisn', '0101234567');
        $this->actingAs($s['admin'])->get($this->at($s['school'], 'biodata/cetak?kelas='.$s['classroom']->id))->assertOk()->assertSee('Aminah');

        $other = $this->makeSchool(['subdomain' => 'smkn2']);
        $this->actingAs($other['admin'])->getJson($this->at($other['school'], "api/students/$id/biodata"))->assertNotFound(); // sekolah lain
    }
}
