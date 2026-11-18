<?php

namespace Tests\Feature\Pwa;

use App\Models\DataImport;
use Tests\TestCase;

/** Tahap 4a: tiga aplikasi terpisah (/admin, /guru, /siswa), Bagikan Aplikasi, dan kartu aktivasi. */
class ThreeAppsTest extends TestCase
{
    private array $s;

    protected function setUp(): void
    {
        parent::setUp();
        $this->s = $this->makeSchool(['subdomain' => 'smkn1', 'name' => 'SMKN 1 Contoh']);
    }

    public function test_tiga_aplikasi_punya_shell_dan_manifest_sendiri(): void
    {
        foreach (['admin' => 'MP Admin', 'guru' => 'MP Guru', 'siswa' => 'MP Siswa'] as $app => $short) {
            $this->get($this->at($this->s['school'], $app))->assertOk()
                ->assertSee('rel="manifest" href="/'.$app.'/manifest.webmanifest"', false);
            $this->get($this->at($this->s['school'], $app.'/manifest.webmanifest'))->assertOk()
                ->assertJsonPath('id', '/'.$app)->assertJsonPath('start_url', '/'.$app)->assertJsonPath('short_name', $short);
        }
        // Warna varian per aplikasi (identitas ParamaEdu.Tech): ikon & theme-color berbeda.
        $this->get($this->at($this->s['school'], 'guru/manifest.webmanifest'))->assertJsonPath('theme_color', '#00A890')->assertJsonPath('icons.0.src', '/pwa/icons/guru-192.png');
        $this->get($this->at($this->s['school'], 'siswa'))->assertSee('data-portal="siswa"', false)->assertSee('/pwa/icons/siswa-apple-touch.png');
        foreach (['admin', 'guru', 'siswa'] as $app) {
            foreach (['-192.png', '-512.png', '-maskable-512.png', '-apple-touch.png'] as $f) {
                $this->assertFileExists(public_path('pwa/icons/'.$app.$f));
            }
        }
        // Pintu lama tetap ada (ikon lama di HP mengarah ke sini lalu diarahkan sesuai peran).
        $this->get($this->at($this->s['school'], 'app'))->assertOk();
    }

    public function test_aplikasi_siswa_menolak_akun_guru_dan_menunjukkan_aplikasi_yang_benar(): void
    {
        $url = $this->at($this->s['school'], 'api/auth/login');

        $res = $this->postJson($url, ['username' => '3301010101', 'password' => 'rahasia123', 'app' => 'siswa'])->assertStatus(422);
        $this->assertStringContainsString('aplikasi Guru', $res->json('message'));
        $this->assertGuest();

        $this->postJson($url, ['username' => '3301010101', 'password' => 'rahasia123', 'app' => 'guru'])->assertOk()->assertJsonPath('user.role', 'teacher');
    }

    public function test_password_salah_tidak_membocorkan_peran_akun(): void
    {
        $res = $this->postJson($this->at($this->s['school'], 'api/auth/login'), ['username' => '3301010101', 'password' => 'salah', 'app' => 'siswa'])->assertStatus(422);
        $this->assertStringNotContainsString('Guru', $res->json('message'));
    }

    public function test_halaman_data_admin_pindah_ke_data_siswa_dan_data_guru(): void
    {
        $this->actingAs($this->s['admin'])->get($this->at($this->s['school'], 'data-siswa'))->assertOk();
        $this->actingAs($this->s['admin'])->get($this->at($this->s['school'], 'data-guru'))->assertOk();
    }

    public function test_bagikan_aplikasi_dan_poster(): void
    {
        $this->actingAs($this->s['admin'])->get($this->at($this->s['school'], 'bagikan'))->assertOk()
            ->assertSee('https://wa.me/?text=', false)->assertSee($this->s['school']->url('siswa'))->assertSee('<svg', false);
        $this->actingAs($this->s['admin'])->get($this->at($this->s['school'], 'bagikan/poster/siswa'))->assertOk()->assertSee('Aplikasi Siswa');
        $this->actingAs($this->s['teacher']->user)->get($this->at($this->s['school'], 'bagikan'))->assertForbidden();
    }

    public function test_kartu_aktivasi_dari_hasil_impor(): void
    {
        $import = $this->tenant()->run($this->s['school'], fn () => DataImport::create([
            'user_id' => $this->s['admin']->id, 'type' => 'students', 'file_name' => 'siswa.xlsx', 'file_path' => '', 'file_size' => 1,
            'status' => 'done', 'credentials' => [['Siswa', '2001', 'Citra', '7A', 'abcd2345']],
        ]));

        $this->actingAs($this->s['admin'])->get($this->at($this->s['school'], 'impor/'.$import->id.'/kartu'))->assertOk()
            ->assertSee('Citra')->assertSee('abcd2345')->assertSee($this->s['school']->url('siswa'));
    }
}
