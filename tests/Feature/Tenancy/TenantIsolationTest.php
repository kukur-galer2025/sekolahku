<?php

namespace Tests\Feature\Tenancy;

use App\Enums\Role;
use App\Models\Classroom;
use App\Models\Student;
use App\Models\User;
use App\Services\ClassQrCode;
use App\Tenancy\TenantViolation;
use Carbon\CarbonImmutable;
use Tests\TestCase;

/**
 * Tes terpenting di checklist: sekolah A tidak bisa membaca atau menulis data sekolah B.
 */
class TenantIsolationTest extends TestCase
{
    private array $a;

    private array $b;

    protected function setUp(): void
    {
        parent::setUp();
        $this->a = $this->makeSchool(['subdomain' => 'smkn1']);
        $this->b = $this->makeSchool(['subdomain' => 'smkn2']);
    }

    // ---------- lapisan model ----------

    public function test_query_hanya_melihat_data_sekolah_aktif(): void
    {
        $this->tenant()->run($this->a['school'], function () {
            $this->assertSame([$this->a['student']->id], Student::pluck('id')->all());
            $this->assertNull(Student::find($this->b['student']->id));
        });
    }

    public function test_tanpa_tenant_data_sekolah_tidak_terlihat_sama_sekali(): void
    {
        $this->tenant()->forget();
        $this->assertSame(0, Student::count());
        $this->assertSame(0, Classroom::count());
        $this->assertSame(0, User::where('role', Role::Admin->value)->count());
    }

    public function test_school_id_diisi_otomatis_dari_tenant(): void
    {
        $c = $this->tenant()->run($this->a['school'], fn () => Classroom::create(['name' => 'XI-TLM']));
        $this->assertSame($this->a['school']->id, $c->school_id);
    }

    public function test_menulis_untuk_sekolah_lain_ditolak(): void
    {
        $this->expectException(TenantViolation::class);
        $this->tenant()->run($this->a['school'], function () {
            $c = new Classroom(['name' => 'Penyusup']);
            $c->school_id = $this->b['school']->id;
            $c->save();
        });
    }

    public function test_memindahkan_baris_ke_sekolah_lain_ditolak(): void
    {
        $this->expectException(TenantViolation::class);
        $this->tenant()->run($this->a['school'], function () {
            $c = Classroom::first();
            $c->school_id = $this->b['school']->id;
            $c->save();
        });
    }

    public function test_membuat_data_tanpa_tenant_ditolak(): void
    {
        $this->tenant()->forget();
        $this->expectException(TenantViolation::class);
        Classroom::create(['name' => 'Yatim']);
    }

    public function test_nis_dan_nama_kelas_yang_sama_boleh_di_sekolah_berbeda(): void
    {
        // makeSchool sudah membuat NIS 1001 dan kelas X-FKK 1 di kedua sekolah
        $this->assertSame('1001', $this->a['student']->nis);
        $this->assertSame('1001', $this->b['student']->nis);
        $this->assertSame($this->a['classroom']->name, $this->b['classroom']->name);
    }

    // ---------- lapisan HTTP ----------

    public function test_admin_hanya_melihat_siswa_sekolahnya(): void
    {
        $res = $this->actingAs($this->a['admin'])->getJson($this->at($this->a['school'], 'api/students'))->assertOk();
        $this->assertSame([$this->a['student']->id], collect($res->json('data'))->pluck('id')->all());
    }

    public function test_admin_tidak_bisa_mengubah_atau_menghapus_siswa_sekolah_lain(): void
    {
        $id = $this->b['student']->id;
        $this->actingAs($this->a['admin']);

        $this->putJson($this->at($this->a['school'], "api/students/$id"), ['nis' => '9', 'name' => 'X'])->assertNotFound();
        $this->deleteJson($this->at($this->a['school'], "api/students/$id"))->assertNotFound();
        $this->putJson($this->at($this->a['school'], "api/students/$id/attendance"), ['status' => 'H'])->assertNotFound();
        $this->getJson($this->at($this->a['school'], 'api/classrooms/'.$this->b['classroom']->id.'/qr'))->assertNotFound();

        $this->tenant()->run($this->b['school'], fn () => $this->assertSame('Aulia Rahma', Student::find($id)->name));
    }

    public function test_tidak_bisa_memasukkan_siswa_ke_kelas_sekolah_lain(): void
    {
        $this->actingAs($this->a['admin'])->postJson($this->at($this->a['school'], 'api/students'), [
            'nis' => '2001', 'name' => 'Siswa Baru', 'classroom_id' => $this->b['classroom']->id,
        ])->assertStatus(422);
    }

    public function test_sesi_sekolah_a_tidak_berlaku_di_subdomain_sekolah_b(): void
    {
        $this->actingAs($this->a['admin'])->getJson($this->at($this->b['school'], 'api/students'))->assertStatus(401);
    }

    public function test_akun_sekolah_b_tidak_bisa_login_di_subdomain_sekolah_a(): void
    {
        $this->tenant()->run($this->b['school'], fn () => User::factory()->create(['role' => Role::Admin, 'username' => 'opb', 'password' => 'passwordB1']));

        $this->postJson($this->at($this->a['school'], 'api/auth/login'), ['username' => 'opb', 'password' => 'passwordB1'])->assertStatus(422);
        $this->postJson($this->at($this->b['school'], 'api/auth/login'), ['username' => 'opb', 'password' => 'passwordB1'])->assertOk();
    }

    public function test_username_sama_di_dua_sekolah_login_ke_akun_yang_benar(): void
    {
        // Kedua sekolah punya username "admin" dengan password sama.
        $res = $this->postJson($this->at($this->b['school'], 'api/auth/login'), ['username' => 'admin', 'password' => 'rahasia123'])->assertOk();
        $this->assertSame($this->b['admin']->id, $res->json('user.id'));
    }

    public function test_qr_kelas_sekolah_lain_ditolak_saat_scan(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-03 07:30', 'Asia/Jakarta')); // Selasa
        $payload = app(ClassQrCode::class)->current($this->b['classroom'])['payload'];

        $this->actingAs($this->a['student']->user)->fromDevice($this->a['deviceToken'])
            ->postJson($this->at($this->a['school'], 'api/attendance/scan'), ['code' => $payload])
            ->assertStatus(422)->assertJsonPath('errors.code.0', 'QR ini bukan untuk kelasmu.');
    }

    public function test_subdomain_tidak_dikenal_404_dan_sekolah_ditangguhkan_403(): void
    {
        $this->getJson('http://tidakada.'.config('tenancy.central_domain').'/api/school')->assertNotFound();

        $this->a['school']->update(['status' => 'suspended']);
        $this->getJson($this->at($this->a['school'], 'api/school'))->assertForbidden();
    }

    public function test_domain_pusat_tidak_membawa_tenant(): void
    {
        $this->getJson($this->at($this->a['school'], 'api/school'))->assertOk()->assertJsonPath('subdomain', 'smkn1');
        $this->get('http://'.config('tenancy.central_domain').'/')->assertOk();
        $this->assertFalse($this->tenant()->check());
    }
}
