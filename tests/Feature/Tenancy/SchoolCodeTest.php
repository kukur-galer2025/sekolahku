<?php

namespace Tests\Feature\Tenancy;

use App\Models\AuditLog;
use App\Models\School;
use App\Support\SchoolCode;
use Tests\TestCase;

/**
 * Kode sekolah: satu pintu masuk {domain pusat}/app|/siswa|/guru → kode → aplikasi sekolah itu → NIK/NIS + password.
 * NIK/NIS yang sama di sekolah lain tidak pernah bisa dipakai untuk masuk ke sekolah yang salah.
 */
class SchoolCodeTest extends TestCase
{
    private array $a;

    private array $b;

    protected function setUp(): void
    {
        parent::setUp();
        // makeSchool() memberi kedua sekolah NIS 1001 dan NIK 3301010101 yang sama.
        $this->a = $this->makeSchool(['subdomain' => 'sekolah-a', 'name' => 'SMP A']);
        $this->b = $this->makeSchool(['subdomain' => 'sekolah-b', 'name' => 'SMP B']);
        $this->tenant()->run($this->b['school'], function () {
            $this->b['student']->user->forceFill(['password' => 'passwordB-siswa'])->save();
            $this->b['teacher']->user->forceFill(['password' => 'passwordB-guru'])->save();
        });
    }

    private function central(string $path): string
    {
        return 'http://'.config('tenancy.central_domain').'/'.ltrim($path, '/');
    }

    public function test_setiap_sekolah_otomatis_punya_kode_unik_yang_mudah_dibaca(): void
    {
        $codes = School::query()->pluck('access_code');
        $this->assertCount(2, $codes->unique());
        foreach ($codes as $code) {
            // Kode acak: 6 karakter tanpa huruf/angka yang mudah tertukar (0/O, 1/I/L).
            $this->assertMatchesRegularExpression('/^['.SchoolCode::ALPHABET.']{6}$/', $code);
        }
        // Kode tidak bisa diisi lewat mass assignment (mis. dari form pendaftaran).
        $this->assertNotContains('access_code', (new School)->getFillable());
    }

    public function test_halaman_kode_sekolah_tersedia_di_domain_pusat(): void
    {
        $this->get($this->central('app'))->assertOk()->assertSee('Kode sekolah')->assertSee('Masuk sebagai');
        $this->get($this->central('siswa'))->assertOk()->assertSee('Masuk aplikasi Siswa')->assertSee('NIS');
        $this->get($this->central('guru'))->assertOk()->assertSee('Masuk aplikasi Guru')->assertSee('NIK');
        $this->get('http://'.config('tenancy.central_domain').'/')->assertOk()->assertSee('masuk dengan kode sekolah');
    }

    public function test_kode_benar_mengantar_ke_aplikasi_sekolah_itu_walau_diketik_huruf_kecil_atau_dengan_tanda_hubung(): void
    {
        $code = strtolower(SchoolCode::format($this->a['school']->access_code)); // mis. "a7k-2qm"

        $this->post($this->central('kode-sekolah'), ['code' => " {$code} ", 'app' => 'siswa'])
            ->assertRedirect($this->a['school']->url('siswa'));
        $this->post($this->central('kode-sekolah'), ['code' => $this->b['school']->access_code, 'app' => 'guru'])
            ->assertRedirect($this->b['school']->url('guru'));
    }

    public function test_kode_salah_ditolak_tanpa_membocorkan_sekolah_lain(): void
    {
        $this->from($this->central('siswa'))->post($this->central('kode-sekolah'), ['code' => 'ZZZZZZ', 'app' => 'siswa'])
            ->assertRedirect($this->central('siswa'))->assertSessionHasErrors('code');
        // Subdomain bukan kode sekolah.
        $this->post($this->central('kode-sekolah'), ['code' => 'sekolah-a', 'app' => 'siswa'])->assertSessionHasErrors('code');
        // Peran wajib dipilih di halaman /app.
        $this->post($this->central('kode-sekolah'), ['code' => $this->a['school']->access_code])->assertSessionHasErrors('app');
    }

    public function test_nis_sama_di_dua_sekolah_hanya_masuk_ke_sekolah_dari_kode(): void
    {
        $url = $this->at($this->a['school'], 'api/auth/login');

        // Password siswa sekolah B tidak berlaku di sekolah A walaupun NIS-nya sama.
        $this->fromDevice($this->a['deviceToken'])->postJson($url, ['username' => '1001', 'password' => 'passwordB-siswa', 'app' => 'siswa'])->assertStatus(422);
        $this->assertGuest();

        // Password sekolah A → akun siswa sekolah A, bukan sekolah B.
        $this->fromDevice($this->a['deviceToken'])->postJson($url, ['username' => '1001', 'password' => '2009-05-12', 'app' => 'siswa'])
            ->assertOk()->assertJsonPath('user.role', 'student');
        $this->assertSame($this->a['school']->id, (int) auth()->user()->school_id);
    }

    public function test_nik_sama_di_dua_sekolah_hanya_masuk_ke_sekolah_dari_kode(): void
    {
        $url = $this->at($this->a['school'], 'api/auth/login');

        $this->postJson($url, ['username' => '3301010101', 'password' => 'passwordB-guru', 'app' => 'guru'])->assertStatus(422);
        $this->assertGuest();

        $this->postJson($url, ['username' => '3301010101', 'password' => 'rahasia123', 'app' => 'guru'])->assertOk()->assertJsonPath('user.role', 'teacher');
        $this->assertSame($this->a['school']->id, (int) auth()->user()->school_id);
    }

    private function superAdmin(): \App\Models\User
    {
        $this->tenant()->forget();
        $u = \App\Models\User::factory()->create(['role' => \App\Enums\Role::SuperAdmin, 'username' => 'superadmin', 'password' => 'SuperRahasia1']);
        $u->forceFill(['two_factor_secret' => 'JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXP', 'two_factor_confirmed_at' => now()])->save();

        return $u;
    }

    public function test_super_admin_menyusun_kode_sendiri_dan_kode_lama_langsung_tidak_berlaku(): void
    {
        $school = $this->a['school'];
        $old = $school->access_code;
        $super = $this->superAdmin();

        $this->actingAs($super)->get($this->central('panel/sekolah/'.$school->id))->assertOk()->assertSee(SchoolCode::format($old));
        $this->actingAs($super)->post($this->central('panel/sekolah/'.$school->id.'/kode'), ['access_code' => 'smpn 1-ksg'])->assertSessionHas('ok');
        $this->assertSame('SMPN1KSG', $school->fresh()->access_code);

        $this->post($this->central('kode-sekolah'), ['code' => $old, 'app' => 'siswa'])->assertSessionHasErrors('code');
        $this->post($this->central('kode-sekolah'), ['code' => 'smpn1ksg', 'app' => 'siswa'])->assertRedirect($school->url('siswa'));
        $this->assertTrue(AuditLog::query()->withoutGlobalScopes()->where('action', 'school.access_code')->exists());

        // Kosong = kode acak baru.
        $this->actingAs($super)->post($this->central('panel/sekolah/'.$school->id.'/kode'), ['access_code' => ''])->assertSessionHas('ok');
        $this->assertMatchesRegularExpression('/^['.SchoolCode::ALPHABET.']{6}$/', $school->fresh()->access_code);
    }

    public function test_kode_susunan_super_admin_harus_unik_dan_berformat_benar(): void
    {
        $super = $this->superAdmin();
        $url = $this->central('panel/sekolah/'.$this->a['school']->id.'/kode');

        $this->actingAs($super)->post($url, ['access_code' => $this->b['school']->access_code])->assertSessionHasErrors('access_code');
        $this->actingAs($super)->post($url, ['access_code' => 'AB'])->assertSessionHasErrors('access_code');
        $this->actingAs($super)->post($url, ['access_code' => 'KODE#SALAH!'])->assertSessionHasErrors('access_code');
        $this->actingAs($super)->post($url, ['access_code' => 'TERLALUPANJANGSEKALI'])->assertSessionHasErrors('access_code');
    }

    public function test_super_admin_bisa_mengisi_kode_saat_membuat_sekolah(): void
    {
        $this->actingAs($this->superAdmin())->post($this->central('panel/sekolah'), [
            'name' => 'SMA Panel', 'subdomain' => 'sma-panel', 'timezone' => 'Asia/Jakarta', 'status' => 'trial', 'student_quota' => 100,
            'admin_name' => 'Admin Panel', 'admin_email' => 'admin@panel.test', 'access_code' => 'smapanel1',
        ])->assertRedirect();
        $this->assertSame('SMAPANEL1', School::query()->where('subdomain', 'sma-panel')->value('access_code'));
    }

    public function test_admin_sekolah_hanya_melihat_kode_tidak_bisa_mengubah(): void
    {
        $school = $this->a['school'];
        $old = $school->access_code;

        $this->actingAs($this->a['admin'])->get($this->at($school, 'profil-sekolah'))->assertOk()
            ->assertSee(SchoolCode::format($old))->assertSee('disusun oleh ParamaEdu.Tech')->assertDontSee('Ganti kode');
        $this->actingAs($this->a['admin'])->get($this->at($school, 'bagikan'))->assertOk()->assertSee(SchoolCode::format($old));

        $this->actingAs($this->a['admin'])->post($this->at($school, 'profil-sekolah/kode'));
        $this->actingAs($this->a['admin'])->post($this->central('panel/sekolah/'.$school->id.'/kode'), ['access_code' => 'BOBOL123']);
        $this->assertSame($old, $school->fresh()->access_code);
    }

    public function test_sekolah_baru_dari_pendaftaran_mandiri_mendapat_kode(): void
    {
        $this->post($this->central('daftar'), [
            'name' => 'SMP Uji Kode', 'subdomain' => 'uji-kode', 'timezone' => 'Asia/Jakarta',
            'admin_name' => 'Admin Uji', 'admin_email' => 'admin@uji.test', 'contact_phone' => '081234567890',
            'admin_password' => 'Rahasia123', 'admin_password_confirmation' => 'Rahasia123', 'agree' => '1',
        ])->assertRedirect();

        $school = School::query()->where('subdomain', 'uji-kode')->firstOrFail();
        $this->assertTrue(SchoolCode::isValidFormat($school->access_code));
        $this->get($this->central('daftar/selesai'))->assertOk()->assertSee(SchoolCode::format($school->access_code));
    }
}
