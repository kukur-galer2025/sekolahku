<?php

namespace Tests\Feature;

use App\Models\Classroom;
use App\Models\StudentEnrollment;
use Tests\TestCase;

/** Tahap 3: jenjang, kelas per tingkat, dan riwayat kelas per tahun ajaran. */
class ClassStructureTest extends TestCase
{
    public function test_smp_membuat_kelas_otomatis_7_sampai_9(): void
    {
        $s = $this->makeSchool(['subdomain' => 'smpn3', 'name' => 'SMP Negeri 3 Contoh']);
        $this->assertSame(['7', '8', '9'], $s['school']->grades());

        $this->actingAs($s['admin'])->post($this->at($s['school'], 'kelas/otomatis'), [
            'count' => 2, 'style' => 'letter', 'grades' => ['7', '8', '9'],
        ])->assertSessionHas('ok');

        $this->tenant()->run($s['school'], function () {
            foreach (['7A' => '7', '7B' => '7', '8A' => '8', '8B' => '8', '9A' => '9', '9B' => '9'] as $name => $g) {
                $this->assertSame($g, Classroom::where('name', $name)->value('grade'), $name);
            }
        });

        // Dijalankan ulang: nama yang sudah ada dilewati.
        $this->actingAs($s['admin'])->post($this->at($s['school'], 'kelas/otomatis'), ['count' => 2, 'style' => 'letter', 'grades' => ['7']]);
        $this->tenant()->run($s['school'], fn () => $this->assertSame(1, Classroom::where('name', '7A')->count()));
    }

    public function test_smk_dengan_jurusan_dan_tingkat_tidak_sesuai_jenjang_ditolak(): void
    {
        $s = $this->makeSchool(['subdomain' => 'smkn5', 'name' => 'SMK Negeri 5 Contoh']);

        $this->actingAs($s['admin'])->post($this->at($s['school'], 'kelas/otomatis'), [
            'count' => 1, 'style' => 'number', 'program' => 'TKJ', 'grades' => ['X', 'XI'],
        ])->assertSessionHas('ok');
        $this->tenant()->run($s['school'], fn () => $this->assertSame('XI', Classroom::where('name', 'XI-TKJ 1')->value('grade')));

        $this->actingAs($s['admin'])->post($this->at($s['school'], 'kelas'), ['grade' => '7', 'name' => '7A'])->assertSessionHasErrors('grade');
    }

    public function test_riwayat_kelas_tercatat_dan_mengikuti_perpindahan_kelas(): void
    {
        $s = $this->makeSchool(['subdomain' => 'smkn6']);
        $year = $s['school']->currentAcademicYear();
        $kelas2 = $this->tenant()->run($s['school'], fn () => Classroom::create(['name' => 'X-FKK 2']));

        $id = $this->actingAs($s['admin'])->postJson($this->at($s['school'], 'api/students'), [
            'nis' => '1002', 'name' => 'Bima', 'classroom_id' => $s['classroom']->id,
        ])->assertCreated()->json('id');

        $this->tenant()->run($s['school'], function () use ($id, $year) {
            $e = StudentEnrollment::where('student_id', $id)->sole();
            $this->assertSame([$year, 'X-FKK 1', 'X', 'aktif'], [$e->academic_year, $e->classroom_name, $e->grade, $e->status]);
        });

        $this->actingAs($s['admin'])->putJson($this->at($s['school'], "api/students/$id"), [
            'nis' => '1002', 'name' => 'Bima', 'classroom_id' => $kelas2->id,
        ])->assertOk();

        $this->actingAs($s['admin'])->getJson($this->at($s['school'], "api/students/$id/history"))
            ->assertOk()->assertJsonCount(1)->assertJsonPath('0.classroom_name', 'X-FKK 2');
    }

    public function test_kelas_yang_masih_punya_siswa_tidak_bisa_dihapus(): void
    {
        $s = $this->makeSchool(['subdomain' => 'smkn7']);

        $this->actingAs($s['admin'])->post($this->at($s['school'], 'kelas/'.$s['classroom']->id.'/hapus'))->assertSessionHasErrors('kelas');
        $this->tenant()->run($s['school'], fn () => $this->assertNotNull(Classroom::find($s['classroom']->id)));
    }

    /** Akun berperan guru tanpa data guru + kelas tanpa wali: null tidak boleh dianggap sama dengan null. */
    public function test_akun_guru_tanpa_data_guru_tidak_dianggap_wali_kelas_tanpa_wali(): void
    {
        $s = $this->makeSchool(['subdomain' => 'smkn9', 'plan' => 'all_service']);
        $ghost = $this->tenant()->run($s['school'], fn () => \App\Models\User::factory()->create(['role' => \App\Enums\Role::Teacher, 'username' => '3309999999']));
        $this->tenant()->forget();
        $this->assertNull($s['classroom']->homeroom_teacher_id);

        $this->actingAs($ghost)->putJson($this->at($s['school'], 'api/students/'.$s['student']->id.'/class-role'), ['class_role' => 'ketua'])->assertForbidden();
        $this->actingAs($ghost)->getJson($this->at($s['school'], 'api/class-cash?classroom_id='.$s['classroom']->id))->assertForbidden();
        $this->actingAs($ghost)->putJson($this->at($s['school'], 'api/students/'.$s['student']->id.'/attendance'), ['status' => 'S'])->assertForbidden();
    }
}
