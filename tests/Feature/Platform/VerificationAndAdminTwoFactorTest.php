<?php

namespace Tests\Feature\Platform;

use App\Enums\Role;
use App\Models\School;
use App\Models\User;
use App\Services\Platform\Totp;
use Tests\TestCase;

/** Tahap 2: verifikasi sekolah, alamat tiruan, dan 2FA wajib admin sekolah. */
class VerificationAndAdminTwoFactorTest extends TestCase
{
    private const SECRET = 'JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXP';

    private function central(string $path = ''): string
    {
        return 'http://'.config('tenancy.central_domain').'/'.ltrim($path, '/');
    }

    private function code(string $secret = self::SECRET): string
    {
        $t = app(Totp::class);

        return $t->code($secret, $t->step());
    }

    public function test_sekolah_belum_terverifikasi_hanya_admin_yang_bisa_masuk(): void
    {
        $s = $this->makeSchool(['subdomain' => 'smpbaru']);
        $s['school']->forceFill(['verified_at' => null])->save();

        $res = $this->fromDevice($s['deviceToken'])->postJson($this->at($s['school'], 'api/auth/login'), ['username' => '1001', 'password' => '2009-05-12'])
            ->assertStatus(422);
        $this->assertStringContainsString('menunggu verifikasi', $res->json('message'));
        $this->postJson($this->at($s['school'], 'api/auth/login'), ['username' => 'admin', 'password' => 'rahasia123'])
            ->assertOk()->assertJsonPath('user.role', 'admin');
    }

    public function test_kuota_dibatasi_sebelum_verifikasi(): void
    {
        config(['tenancy.unverified_student_quota' => 1]);
        $s = $this->makeSchool(['subdomain' => 'smpkuota', 'student_quota' => 300]); // sudah ada 1 siswa
        $s['school']->forceFill(['verified_at' => null])->save();

        $res = $this->actingAs($s['admin'])->postJson($this->at($s['school'], 'api/students'), ['nis' => '1002', 'name' => 'Bima'])
            ->assertStatus(422);
        $this->assertStringContainsString('belum terverifikasi', $res->json('message'));

        $s['school']->forceFill(['verified_at' => now()])->save();
        $this->actingAs($s['admin'])->postJson($this->at($s['school'], 'api/students'), ['nis' => '1002', 'name' => 'Bima'])->assertCreated();
    }

    public function test_alamat_tiruan_ditolak(): void
    {
        School::factory()->create(['subdomain' => 'smppemda1-kes']);

        foreach (['smppemda1kes', 'smp-pemda1-kes', 'smppemdalkes', 'adm1n', 'c-panel'] as $fake) {
            $this->getJson($this->central('daftar/cek?subdomain='.$fake))->assertJson(['available' => false]);
        }
        $this->getJson($this->central('daftar/cek?subdomain=smppemda2-kes'))->assertJson(['available' => true]);
    }

    public function test_admin_wajib_mengaktifkan_2fa_lalu_bisa_memakai_portal(): void
    {
        config(['tenancy.school_admin_2fa' => true]);
        $s = $this->makeSchool(['subdomain' => 'smk2fa']);

        $this->actingAs($s['admin'])->getJson($this->at($s['school'], 'api/dashboard'))
            ->assertStatus(403)->assertJsonPath('two_factor_setup_required', true);
        $this->actingAs($s['admin'])->getJson($this->at($s['school'], 'api/me'))
            ->assertOk()->assertJsonPath('user.two_factor_setup_required', true);

        $this->actingAs($s['admin'])->getJson($this->at($s['school'], 'api/me/2fa'))->assertOk()->assertJsonStructure(['secret', 'qr_svg']);
        $secret = session('portal.2fa.secret');

        $this->actingAs($s['admin'])->postJson($this->at($s['school'], 'api/me/2fa'), ['code' => '000000'])->assertStatus(422);
        $this->actingAs($s['admin'])->postJson($this->at($s['school'], 'api/me/2fa'), ['code' => $this->code($secret)])
            ->assertOk()->assertJsonCount(8, 'recovery_codes');

        $this->actingAs($s['admin'])->getJson($this->at($s['school'], 'api/dashboard'))->assertOk();
    }

    public function test_guru_tidak_dipaksa_2fa(): void
    {
        config(['tenancy.school_admin_2fa' => true]);
        $s = $this->makeSchool(['subdomain' => 'smkguru']);

        $this->actingAs($s['teacher']->user)->getJson($this->at($s['school'], 'api/dashboard'))->assertOk();
    }

    public function test_admin_dengan_2fa_harus_memasukkan_kode_saat_login(): void
    {
        config(['tenancy.school_admin_2fa' => true]);
        $s = $this->makeSchool(['subdomain' => 'smklogin']);
        $this->tenant()->run($s['school'], fn () => $s['admin']->forceFill([
            'two_factor_secret' => self::SECRET, 'two_factor_confirmed_at' => now(), 'two_factor_recovery_codes' => ['aaaaaa-bbbbbb'],
        ])->save());
        $this->tenant()->forget();

        $this->postJson($this->at($s['school'], 'api/auth/login'), ['username' => 'admin', 'password' => 'rahasia123'])
            ->assertOk()->assertJsonPath('two_factor', true)->assertJsonMissingPath('user');
        $this->getJson($this->at($s['school'], 'api/me'))->assertStatus(401); // password saja belum cukup

        $this->postJson($this->at($s['school'], 'api/auth/2fa'), ['code' => '123456'])->assertStatus(422);
        $this->postJson($this->at($s['school'], 'api/auth/2fa'), ['code' => 'aaaaaa-bbbbbb'])->assertOk()->assertJsonPath('user.role', 'admin');
        $this->getJson($this->at($s['school'], 'api/me'))->assertOk();
    }

    public function test_super_admin_memverifikasi_sekolah_dan_reset_menghapus_2fa_admin(): void
    {
        $this->tenant()->forget();
        $super = User::factory()->create(['role' => Role::SuperAdmin, 'username' => 'superadmin', 'password' => 'SuperRahasia1']);
        $super->forceFill(['two_factor_secret' => self::SECRET, 'two_factor_confirmed_at' => now()])->save();
        $s = $this->makeSchool(['subdomain' => 'smpverif', 'npsn' => '20301234']);
        $s['school']->forceFill(['verified_at' => null])->save();
        $this->tenant()->run($s['school'], fn () => $s['admin']->forceFill(['two_factor_secret' => self::SECRET, 'two_factor_confirmed_at' => now()])->save());
        $this->tenant()->forget();

        $panel = fn (string $p) => 'http://'.config('tenancy.central_domain').'/panel/'.$p;
        $this->actingAs($super)->post($panel('sekolah/'.$s['school']->id.'/verifikasi'))->assertRedirect();
        $this->assertTrue($s['school']->fresh()->isVerified());

        $this->actingAs($super)->post($panel('sekolah/'.$s['school']->id.'/reset-admin'), ['user_id' => $s['admin']->id])->assertRedirect();
        $this->tenant()->run($s['school'], fn () => $this->assertNull($s['admin']->fresh()->two_factor_confirmed_at));
    }
}
