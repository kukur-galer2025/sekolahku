<?php

namespace Tests\Feature\Ops;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class SuperAdminCommandTest extends TestCase
{
    public function test_buat_dan_reset_super_admin_dari_cli(): void
    {
        $this->artisan('platform:superadmin', ['username' => 'superadmin', 'email' => 'ops@paramatech.id'])
            ->expectsQuestion('Password baru (min. 12 karakter, huruf besar/kecil + angka)', 'lemah')
            ->expectsQuestion('Ulangi password', 'lemah')->assertFailed();
        $this->assertSame(0, User::query()->whereNull('school_id')->count());

        $this->artisan('platform:superadmin', ['username' => 'superadmin', 'email' => 'ops@paramatech.id'])
            ->expectsQuestion('Password baru (min. 12 karakter, huruf besar/kecil + angka)', 'KuatSekali2026')
            ->expectsQuestion('Ulangi password', 'KuatSekali2026')->assertSuccessful();
        $u = User::query()->whereNull('school_id')->sole();
        $this->assertSame(Role::SuperAdmin, $u->role);
        $this->assertTrue(Hash::check('KuatSekali2026', $u->password));

        $u->forceFill(['two_factor_secret' => 'JBSWY3DPEHPK3PXP', 'two_factor_confirmed_at' => now()])->save();
        $this->artisan('platform:superadmin', ['username' => 'superadmin', 'email' => 'ops@paramatech.id', '--reset-2fa' => true])
            ->expectsQuestion('Password baru (min. 12 karakter, huruf besar/kecil + angka)', 'BaruLagi20261')
            ->expectsQuestion('Ulangi password', 'BaruLagi20261')->assertSuccessful();
        $this->assertNull($u->fresh()->two_factor_confirmed_at);
        $this->assertSame(1, User::query()->whereNull('school_id')->count());
    }

    public function test_super_admin_dari_env_untuk_cpanel_tanpa_terminal(): void
    {
        putenv('SUPERADMIN_PASSWORD=lemah'); $_ENV['SUPERADMIN_PASSWORD'] = $_SERVER['SUPERADMIN_PASSWORD'] = 'lemah';
        $this->artisan('platform:superadmin', ['username' => 'superadmin', 'email' => 'ops@paramatech.id', '--from-env' => true])->assertFailed();

        putenv('SUPERADMIN_PASSWORD=DariCpanel2026'); $_ENV['SUPERADMIN_PASSWORD'] = $_SERVER['SUPERADMIN_PASSWORD'] = 'DariCpanel2026';
        $this->artisan('platform:superadmin', ['username' => 'superadmin', 'email' => 'ops@paramatech.id', '--from-env' => true])
            ->expectsOutputToContain('HAPUS isi SUPERADMIN_PASSWORD')->assertSuccessful();
        $this->assertTrue(Hash::check('DariCpanel2026', User::query()->whereNull('school_id')->sole()->password));
        putenv('SUPERADMIN_PASSWORD'); unset($_ENV['SUPERADMIN_PASSWORD'], $_SERVER['SUPERADMIN_PASSWORD']);
    }

    public function test_cek_server_menulis_laporan(): void
    {
        $out = storage_path('framework/testing-check.txt');
        @unlink($out);
        $this->artisan('platform:check', ['--out' => $out])->expectsOutputToContain('koneksi database');
        $txt = (string) file_get_contents($out);
        $this->assertStringContainsString('[ OK ] koneksi database', $txt);
        $this->assertStringContainsString('SESSION_DOMAIN kosong', $txt);
        @unlink($out);
    }

    public function test_setup_cpanel_migrasi_superadmin_dan_laporan(): void
    {
        putenv('SUPERADMIN_PASSWORD=SetupCpanel2026'); $_ENV['SUPERADMIN_PASSWORD'] = $_SERVER['SUPERADMIN_PASSWORD'] = 'SetupCpanel2026';
        $this->withoutMockingConsoleOutput()->artisan('platform:setup', ['username' => 'superadmin', 'email' => 'ops@paramatech.id', '--skip-optimize' => true]);
        putenv('SUPERADMIN_PASSWORD'); unset($_ENV['SUPERADMIN_PASSWORD'], $_SERVER['SUPERADMIN_PASSWORD']);

        $txt = (string) file_get_contents(storage_path('logs/setup.txt'));
        $this->assertStringContainsString('1. Migrasi database', $txt);
        $this->assertStringContainsString('Super-admin dibuat: superadmin', $txt);
        $this->assertStringContainsString('4. Cek server', $txt);
        $this->assertTrue(Hash::check('SetupCpanel2026', User::query()->whereNull('school_id')->sole()->password));
        @unlink(storage_path('logs/setup.txt'));
    }
}
