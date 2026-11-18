<?php

namespace Tests\Feature\Platform;

use App\Enums\Role;
use App\Mail\ResetPasswordMail;
use App\Mail\VerifyEmailMail;
use App\Models\School;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class OnboardingTest extends TestCase
{
    private function central(string $path = ''): string
    {
        return 'http://'.config('tenancy.central_domain').'/'.ltrim($path, '/');
    }

    private function form(array $over = []): array
    {
        return array_merge([
            'name' => 'SMK Negeri 9 Contoh', 'npsn' => '20301234', 'subdomain' => 'SMKN9-Contoh', 'timezone' => 'Asia/Makassar',
            'admin_name' => 'Rina Operator', 'admin_email' => 'Rina@Sekolah.sch.id', 'contact_phone' => '081234567890',
            'admin_password' => 'Rahasia123', 'admin_password_confirmation' => 'Rahasia123', 'agree' => '1',
        ], $over);
    }

    private function adminOf(School $s): User
    {
        return $this->tenant()->run($s, fn () => User::where('role', Role::Admin->value)->firstOrFail());
    }

    public function test_halaman_depan_dan_daftar_tampil(): void
    {
        $this->get($this->central())->assertOk()->assertSee('Masuk ke portal sekolah');
        $this->get($this->central('daftar'))->assertOk()->assertSee('Daftarkan sekolahmu');
    }

    public function test_daftar_sekolah_mandiri_membuat_tenant_trial_dan_mengirim_verifikasi(): void
    {
        Mail::fake();
        $this->post($this->central('daftar'), $this->form())->assertRedirect($this->central('daftar/selesai'));

        $s = School::where('subdomain', 'smkn9-contoh')->firstOrFail();
        $this->assertSame('trial', $s->status->value);
        $this->assertSame('self', $s->registered_via);
        $this->assertSame('Asia/Makassar', $s->timezone);
        $this->assertSame(config('tenancy.trial_student_quota'), $s->student_quota);
        $this->assertTrue($s->trial_ends_at->between(now()->addDays(29), now()->addDays(31)));

        $admin = $this->adminOf($s);
        $this->assertSame('rina@sekolah.sch.id', $admin->email);
        $this->assertNull($admin->email_verified_at);
        $this->assertTrue(Hash::check('Rahasia123', $admin->password));

        Mail::assertSent(VerifyEmailMail::class, fn ($m) => $m->hasTo('rina@sekolah.sch.id') && str_contains($m->url, '//smkn9-contoh.'.config('tenancy.central_domain').'/verifikasi/'));
        $this->get($this->central('daftar/selesai'))->assertOk()->assertSee('SMK Negeri 9 Contoh')->assertSee('smkn9-contoh.');
        $this->get($this->central('daftar/selesai'))->assertRedirect(); // pesan sekali tampil
    }

    public function test_validasi_pendaftaran(): void
    {
        School::factory()->create(['subdomain' => 'sudahada']);
        $this->post($this->central('daftar'), $this->form(['subdomain' => 'sudahada']))->assertSessionHasErrors('subdomain');
        $this->post($this->central('daftar'), $this->form(['subdomain' => 'panel']))->assertSessionHasErrors('subdomain');
        $this->post($this->central('daftar'), $this->form(['agree' => null]))->assertSessionHasErrors('agree');
        $this->post($this->central('daftar'), $this->form(['admin_password' => 'pendek', 'admin_password_confirmation' => 'pendek']))->assertSessionHasErrors('admin_password');
        $this->post($this->central('daftar'), $this->form(['website' => 'http://spam']))->assertStatus(422);
        $this->assertSame(1, School::count());
    }

    public function test_cek_ketersediaan_subdomain(): void
    {
        School::factory()->create(['subdomain' => 'sudahada']);
        $this->getJson($this->central('daftar/cek?subdomain=baru-sekali'))->assertJson(['available' => true]);
        $this->getJson($this->central('daftar/cek?subdomain=sudahada'))->assertJson(['available' => false]);
        $this->getJson($this->central('daftar/cek?subdomain=www'))->assertJson(['available' => false]);
    }

    public function test_verifikasi_email_wajib_sebelum_admin_mengelola_data(): void
    {
        Mail::fake();
        $this->post($this->central('daftar'), $this->form());
        $s = School::firstWhere('subdomain', 'smkn9-contoh');
        $url = null;
        Mail::assertSent(VerifyEmailMail::class, function ($m) use (&$url) { $url = $m->url; return true; });

        $admin = $this->adminOf($s);
        $this->actingAs($admin)->get($this->at($s, 'dashboard'))->assertRedirect($this->at($s, 'verifikasi'));
        $this->actingAs($admin)->get($this->at($s, 'data-siswa'))->assertRedirect($this->at($s, 'verifikasi'));
        $this->actingAs($admin)->get($this->at($s, 'verifikasi'))->assertOk()->assertSee('rina@sekolah.sch.id');

        $this->get($url.'x')->assertForbidden(); // tanda tangan dirusak
        $this->get($url)->assertRedirect();
        $this->assertNotNull($this->adminOf($s)->email_verified_at);

        // Langkah berikutnya: setujui Perjanjian Pemrosesan Data
        $this->actingAs($this->adminOf($s))->get($this->at($s, 'dashboard'))->assertRedirect($this->at($s, 'persetujuan-dpa'));
        $this->actingAs($this->adminOf($s))->post($this->at($s, 'persetujuan-dpa'), ['signer_name' => 'Rina', 'signer_position' => 'Operator'])->assertSessionHasErrors('authorized');
        $this->actingAs($this->adminOf($s))->post($this->at($s, 'persetujuan-dpa'), ['signer_name' => 'Rina', 'signer_position' => 'Operator', 'authorized' => '1'])->assertRedirect();
        $this->actingAs($this->adminOf($s))->get($this->at($s, 'dashboard'))->assertOk()->assertSee('Siapkan sekolahmu');
    }

    public function test_tautan_verifikasi_sekolah_a_tidak_berlaku_di_sekolah_b(): void
    {
        Mail::fake();
        $this->post($this->central('daftar'), $this->form());
        $b = School::factory()->create(['subdomain' => 'sekolahb']);
        $url = null;
        Mail::assertSent(VerifyEmailMail::class, function ($m) use (&$url) { $url = $m->url; return true; });

        $this->get(str_replace('//smkn9-contoh.', '//sekolahb.', $url))->assertForbidden();
    }

    public function test_admin_bisa_masuk_dengan_email(): void
    {
        $this->post($this->central('daftar'), $this->form());
        $s = School::firstWhere('subdomain', 'smkn9-contoh');
        $this->post($this->at($s, 'masuk'), ['username' => 'RINA@sekolah.sch.id', 'password' => 'Rahasia123'])->assertRedirect();
        $this->assertAuthenticated();
    }

    public function test_tombol_masuk_ke_sekolah_mengarahkan_ke_subdomain(): void
    {
        $s = School::factory()->create(['subdomain' => 'smkn1']);
        $this->post($this->central('masuk-sekolah'), ['subdomain' => 'https://SMKN1.mypresence.id'])->assertRedirect($s->url('masuk'));
        $this->from($this->central())->post($this->central('masuk-sekolah'), ['subdomain' => 'tidakada'])->assertSessionHasErrors('subdomain');
    }

    // ---------- lupa password ----------

    public function test_lupa_password_per_sekolah_walau_email_sama(): void
    {
        Mail::fake();
        $a = $this->makeSchool(['subdomain' => 'smkn1']);
        $b = $this->makeSchool(['subdomain' => 'smkn2']);
        foreach ([$a, $b] as $x) {
            $this->tenant()->run($x['school'], fn () => $x['admin']->forceFill(['email' => 'ops@yayasan.id'])->save());
        }

        $this->post($this->at($a['school'], 'lupa-password'), ['email' => 'ops@yayasan.id'])->assertSessionHas('ok');
        Mail::assertSent(ResetPasswordMail::class, 1);
        $url = null;
        Mail::assertSent(ResetPasswordMail::class, function ($m) use (&$url) { $url = $m->url; return true; });
        $this->assertStringContainsString('//smkn1.', $url);

        $token = basename($url);
        $this->get($this->at($b['school'], "reset-password/$token"))->assertSee('kedaluwarsa'); // token sekolah A tak berlaku di B
        $this->post($url, ['password' => 'BaruSekali9', 'password_confirmation' => 'BaruSekali9'])->assertRedirect($this->at($a['school'], 'masuk'));

        $this->tenant()->run($a['school'], fn () => $this->assertTrue(Hash::check('BaruSekali9', User::find($a['admin']->id)->password)));
        $this->tenant()->run($b['school'], fn () => $this->assertTrue(Hash::check('rahasia123', User::find($b['admin']->id)->password)));

        // sekali pakai
        $this->post($url, ['password' => 'LagiLagi99', 'password_confirmation' => 'LagiLagi99'])->assertSessionHasErrors('password');
    }

    public function test_lupa_password_tidak_membocorkan_email_terdaftar_dan_token_kedaluwarsa(): void
    {
        Mail::fake();
        $a = $this->makeSchool(['subdomain' => 'smkn1']);
        $this->post($this->at($a['school'], 'lupa-password'), ['email' => 'tidak@ada.id'])->assertSessionHas('ok');
        Mail::assertNothingSent();

        $this->tenant()->run($a['school'], fn () => $a['admin']->forceFill(['email' => 'ops@sekolah.id'])->save());
        $this->post($this->at($a['school'], 'lupa-password'), ['email' => 'ops@sekolah.id']);
        $url = null;
        Mail::assertSent(ResetPasswordMail::class, function ($m) use (&$url) { $url = $m->url; return true; });

        $this->travel(61)->minutes();
        $this->post($url, ['password' => 'BaruSekali9', 'password_confirmation' => 'BaruSekali9'])->assertSessionHasErrors('password');
    }

    public function test_admin_membuat_password_sementara_untuk_guru(): void
    {
        $a = $this->makeSchool(['subdomain' => 'smkn1']);
        $res = $this->actingAs($a['admin'])->post($this->at($a['school'], 'guru/'.$a['teacher']->id.'/reset-password'))->assertSessionHas('temp_password');
        $pw = session('temp_password')['password'];
        auth()->logout();

        $this->postJson($this->at($a['school'], 'api/auth/login'), ['username' => '3301010101', 'password' => $pw])->assertOk()->assertJsonPath('user.must_change_password', true);
    }
}
