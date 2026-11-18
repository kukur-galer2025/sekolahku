<?php

namespace Tests\Feature;

use App\Models\WebauthnCredential;
use Tests\Support\FakeAuthenticator;
use Tests\TestCase;

/** Login sidik jari / Face ID (passkey WebAuthn). */
class PasskeyTest extends TestCase
{
    private array $s;

    protected function setUp(): void
    {
        parent::setUp();
        $this->s = $this->makeSchool(['subdomain' => 'smkn1']);
        $this->tenant()->forget();
    }

    private function api(string $p, ?array $school = null): string
    {
        return $this->at(($school ?? $this->s)['school'], 'api/'.$p);
    }

    private function phone(?array $school = null): FakeAuthenticator
    {
        $base = rtrim($this->at(($school ?? $this->s)['school'], ''), '/');

        return new FakeAuthenticator(parse_url($base, PHP_URL_HOST), $base);
    }

    private function enroll($user, FakeAuthenticator $phone): void
    {
        $o = $this->actingAs($user)->postJson($this->api('passkeys/options'))->assertOk()->json('publicKey');
        $this->assertSame('required', $o['authenticatorSelection']['userVerification']);
        $this->actingAs($user)->postJson($this->api('passkeys'), $phone->register($o['challenge']) + ['name' => 'HP Android (Chrome)'])->assertCreated();
        $this->postJson($this->api('auth/logout'));
        $this->app['auth']->forgetGuards();
    }

    public function test_guru_mendaftar_lalu_masuk_dengan_sidik_jari(): void
    {
        $guru = $this->s['teacher']->user;
        $phone = $this->phone();
        $this->enroll($guru, $phone);

        $ch = $this->postJson($this->api('auth/passkey/options'), ['ids' => [\App\Services\Platform\WebAuthn::b64url($phone->credentialId)]])->assertOk()->json('publicKey.challenge');
        $this->postJson($this->api('auth/passkey'), $phone->assert($ch) + ['app' => 'guru'])->assertOk()->assertJsonPath('user.id', $guru->id);
        $this->assertAuthenticatedAs($guru);
        $this->tenant()->run($this->s['school'], fn () => $this->assertSame(1, WebauthnCredential::query()->value('sign_count')));
    }

    public function test_tanpa_verifikasi_sidik_jari_atau_tantangan_lama_ditolak(): void
    {
        $guru = $this->s['teacher']->user;
        $phone = $this->phone();
        $this->enroll($guru, $phone);

        $ch = $this->postJson($this->api('auth/passkey/options'))->json('publicKey.challenge');
        $this->postJson($this->api('auth/passkey'), $phone->assert($ch, verified: false))->assertUnprocessable()->assertJsonValidationErrors('passkey');
        // Tantangan sekali pakai: dipakai ulang → ditolak.
        $this->postJson($this->api('auth/passkey'), $phone->assert($ch))->assertUnprocessable();
        $this->assertGuest();
    }

    public function test_passkey_tidak_berlaku_di_sekolah_lain_dan_aplikasi_lain(): void
    {
        $siswa = $this->s['student']->user;
        $phone = $this->phone();
        $this->enroll($siswa, $phone);

        // Aplikasi Guru menolak akun siswa.
        $ch = $this->postJson($this->api('auth/passkey/options'))->json('publicKey.challenge');
        $this->postJson($this->api('auth/passkey'), $phone->assert($ch) + ['app' => 'guru'])->assertUnprocessable();

        // Sekolah lain tidak mengenal kredensial ini.
        $other = $this->makeSchool(['subdomain' => 'smkn2']);
        $this->tenant()->forget();
        $ch2 = $this->postJson($this->api('auth/passkey/options', $other))->json('publicKey.challenge');
        $this->postJson($this->api('auth/passkey', $other), $phone->assert($ch2))->assertUnprocessable();
        $this->assertGuest();
    }

    public function test_daftar_dan_hapus_hp(): void
    {
        $guru = $this->s['teacher']->user;
        $this->enroll($guru, $this->phone());
        $id = $this->actingAs($guru)->getJson($this->api('passkeys'))->assertOk()->assertJsonCount(1)->json('0.id');
        $this->actingAs($this->s['student']->user)->deleteJson($this->api('passkeys/'.$id))->assertNotFound(); // milik orang lain
        $this->actingAs($guru)->deleteJson($this->api('passkeys/'.$id))->assertOk();
        $this->actingAs($guru)->getJson($this->api('passkeys'))->assertJsonCount(0);
    }
}
