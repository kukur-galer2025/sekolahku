<?php

namespace Tests\Feature\Platform;

use App\Enums\Role;
use App\Models\User;
use App\Services\Platform\Totp;
use Tests\TestCase;

class TwoFactorTest extends TestCase
{
    private function panel(string $p = ''): string
    {
        return 'http://'.config('tenancy.central_domain').'/panel'.($p ? '/'.$p : '');
    }

    public function test_super_admin_baru_wajib_mengaktifkan_2fa_lalu_login_memakai_kode(): void
    {
        $u = User::factory()->create(['role' => Role::SuperAdmin, 'username' => 'ops', 'password' => 'SuperRahasia1']);
        $totp = app(Totp::class);

        $this->post($this->panel('masuk'), ['username' => 'ops', 'password' => 'SuperRahasia1'])->assertRedirect();
        $this->get($this->panel())->assertRedirect($this->panel('2fa/aktifkan'));
        $this->get($this->panel('2fa/aktifkan'))->assertOk()->assertSee('<svg', false);

        $secret = session('panel.2fa.secret');
        $this->post($this->panel('2fa/aktifkan'), ['code' => '123456'])->assertSessionHasErrors('code');
        $this->post($this->panel('2fa/aktifkan'), ['code' => $totp->code($secret, $totp->step())])->assertOk()->assertSee('Kode cadangan');

        $u->refresh();
        $this->assertNotNull($u->two_factor_confirmed_at);
        $this->assertNotSame($secret, $u->getRawOriginal('two_factor_secret'), 'rahasia 2FA disimpan terenkripsi');
        $this->get($this->panel())->assertOk();

        // logout lalu login lagi: kode yang sama tidak boleh dipakai ulang
        $this->post($this->panel('keluar'));
        $this->post($this->panel('masuk'), ['username' => 'ops', 'password' => 'SuperRahasia1'])->assertRedirect($this->panel('2fa'));
        $this->post($this->panel('2fa'), ['code' => $totp->code($secret, $totp->step())])->assertSessionHasErrors('code');

        // kode cadangan sekali pakai
        $rc = $u->two_factor_recovery_codes[0];
        $this->post($this->panel('2fa'), ['code' => $rc])->assertRedirect($this->panel());
        $this->assertCount(7, $u->refresh()->two_factor_recovery_codes);
    }

    public function test_halaman_2fa_tanpa_password_dulu_dikembalikan_ke_login(): void
    {
        $this->get($this->panel('2fa'))->assertRedirect($this->panel('masuk'));
        $this->post($this->panel('2fa'), ['code' => '123456'])->assertRedirect($this->panel('masuk'));
    }

    public function test_header_keamanan_terpasang(): void
    {
        $this->get('http://'.config('tenancy.central_domain').'/')
            ->assertHeader('X-Frame-Options', 'SAMEORIGIN')->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('Content-Security-Policy');
        $this->assertStringContainsString("frame-ancestors 'self'", $this->get('http://'.config('tenancy.central_domain').'/daftar')->headers->get('Content-Security-Policy'));
    }
}
