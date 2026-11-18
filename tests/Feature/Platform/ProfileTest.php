<?php

namespace Tests\Feature\Platform;

use App\Enums\Role;
use App\Models\User;
use App\Services\Platform\Totp;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ProfileTest extends TestCase
{
    private User $super;

    private const SECRET = 'JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXP';

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant()->forget();
        $this->super = User::factory()->create(['role' => Role::SuperAdmin, 'username' => 'superadmin', 'name' => 'Dina Support', 'password' => 'SuperRahasia1']);
        $this->super->forceFill(['two_factor_secret' => self::SECRET, 'two_factor_confirmed_at' => now(), 'two_factor_recovery_codes' => ['aaaaaa-bbbbbb', 'cccccc-dddddd']])->save();
    }

    private function panel(string $path = ''): string
    {
        return 'http://'.config('tenancy.central_domain').'/panel'.($path ? '/'.ltrim($path, '/') : '');
    }

    public function test_halaman_profil_tampil(): void
    {
        $this->actingAs($this->super)->get($this->panel('profil'))->assertOk()
            ->assertSee('Dina Support')->assertSee('2FA aktif')->assertSee('Ganti password')->assertSee('DS'); // inisial avatar
    }

    public function test_ubah_nama_tanpa_password_tapi_username_butuh_password(): void
    {
        $this->actingAs($this->super)->put($this->panel('profil'), ['name' => 'Dina S.', 'username' => 'superadmin', 'email' => (string) $this->super->email, 'phone' => '0812 3456'])
            ->assertSessionHas('ok');
        $this->assertSame('Dina S.', $this->super->fresh()->name);

        $this->actingAs($this->super)->put($this->panel('profil'), ['name' => 'Dina S.', 'username' => 'dina', 'email' => (string) $this->super->email])->assertSessionHasErrors('current_password');
        $this->assertSame('superadmin', $this->super->fresh()->username);

        $other = User::factory()->create(['role' => Role::SuperAdmin, 'username' => 'budi', 'name' => 'Budi']);
        $this->actingAs($this->super)->put($this->panel('profil'), ['name' => 'Dina S.', 'username' => 'budi', 'email' => (string) $this->super->email, 'current_password' => 'SuperRahasia1'])
            ->assertSessionHasErrors('username');

        $this->actingAs($this->super)->put($this->panel('profil'), ['name' => 'Dina S.', 'username' => 'dina', 'email' => 'Dina@Parama.SBS', 'current_password' => 'SuperRahasia1'])
            ->assertSessionHas('ok');
        $this->assertSame('dina', $this->super->fresh()->username);
        $this->assertSame('dina@parama.sbs', $this->super->fresh()->email);
    }

    public function test_ganti_password(): void
    {
        $this->actingAs($this->super)->put($this->panel('profil/password'), ['current_password' => 'salah', 'password' => 'PasswordBaru123', 'password_confirmation' => 'PasswordBaru123'])
            ->assertSessionHasErrors('current_password');
        $this->actingAs($this->super)->put($this->panel('profil/password'), ['current_password' => 'SuperRahasia1', 'password' => 'pendek1', 'password_confirmation' => 'pendek1'])
            ->assertSessionHasErrors('password');
        $this->actingAs($this->super)->put($this->panel('profil/password'), ['current_password' => 'SuperRahasia1', 'password' => 'PasswordBaru123', 'password_confirmation' => 'PasswordBaru123'])
            ->assertSessionHas('ok');
        $this->assertTrue(Hash::check('PasswordBaru123', $this->super->fresh()->password));
    }

    public function test_kode_cadangan_baru_dan_atur_ulang_2fa(): void
    {
        $this->actingAs($this->super)->post($this->panel('profil/2fa/kode-cadangan'), ['current_password' => 'SuperRahasia1'])->assertOk()->assertSee('Kode cadangan baru');
        $codes = $this->super->fresh()->two_factor_recovery_codes;
        $this->assertCount(10, $codes);
        $this->assertNotContains('aaaaaa-bbbbbb', $codes);

        // Kode salah → ditolak, 2FA tetap aktif.
        $this->actingAs($this->super)->post($this->panel('profil/2fa/atur-ulang'), ['current_password' => 'SuperRahasia1', 'code' => '000000'])->assertSessionHasErrors('code');
        $this->assertNotNull($this->super->fresh()->two_factor_confirmed_at);

        $code = app(Totp::class)->code(self::SECRET, intdiv(time(), 30));
        $this->actingAs($this->super)->post($this->panel('profil/2fa/atur-ulang'), ['current_password' => 'SuperRahasia1', 'code' => $code])
            ->assertRedirect($this->panel('2fa/aktifkan'));
        $this->assertNull($this->super->fresh()->two_factor_confirmed_at);
    }

    public function test_unggah_foto_profil_hanya_jpg(): void
    {
        Storage::fake('local');
        $this->actingAs($this->super)->post($this->panel('profil/foto'), ['photo' => UploadedFile::fake()->image('foto.png', 400, 300)])->assertSessionHasErrors('photo');
        $this->assertNull($this->super->fresh()->photo_path);

        $this->actingAs($this->super)->post($this->panel('profil/foto'), ['photo' => UploadedFile::fake()->image('foto.jpg', 900, 600)])->assertSessionHas('ok');
        $path = $this->super->fresh()->photo_path;
        $this->assertStringStartsWith('panel/avatars/', $path);
        Storage::disk('local')->assertExists($path);
        $size = getimagesizefromstring(Storage::disk('local')->get($path));
        $this->assertSame([512, 512, IMAGETYPE_JPEG], [$size[0], $size[1], $size[2]]);

        $this->actingAs($this->super)->get($this->panel('foto/'.$this->super->id))->assertOk()->assertHeader('Content-Type', 'image/jpeg');
        $this->actingAs($this->super)->get($this->panel('profil'))->assertOk()->assertSee('/panel/foto/'.$this->super->id, false);

        // Ganti foto → file lama dihapus; hapus foto → kosong.
        $this->actingAs($this->super)->post($this->panel('profil/foto'), ['photo' => UploadedFile::fake()->image('baru.jpeg', 600, 600)])->assertSessionHas('ok');
        Storage::disk('local')->assertMissing($path);
        $this->actingAs($this->super)->delete($this->panel('profil/foto'))->assertSessionHas('ok');
        $this->assertNull($this->super->fresh()->photo_path);
        $this->actingAs($this->super)->get($this->panel('foto/'.$this->super->id))->assertNotFound();
    }

    public function test_keluarkan_sesi_lain(): void
    {
        config(['session.driver' => 'database']);
        DB::table('sessions')->insert(['id' => 'sesi-lain-123', 'user_id' => $this->super->id, 'ip_address' => '10.0.0.9', 'user_agent' => 'Mozilla/5.0 (Linux; Android 14) Chrome/120', 'payload' => '', 'last_activity' => time()]);
        $this->actingAs($this->super)->get($this->panel('profil'))->assertOk()->assertSee('Chrome · Android');
        $this->actingAs($this->super)->post($this->panel('profil/sesi/keluarkan'), ['current_password' => 'SuperRahasia1'])->assertSessionHas('ok');
        $this->assertFalse(DB::table('sessions')->where('id', 'sesi-lain-123')->exists());
    }
}
