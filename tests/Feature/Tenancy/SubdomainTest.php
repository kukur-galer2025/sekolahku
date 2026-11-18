<?php

namespace Tests\Feature\Tenancy;

use App\Console\Commands\CheckTenantDns;
use App\Models\School;
use App\Rules\AvailableSubdomain;
use App\Tenancy\Subdomain;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

class SubdomainTest extends TestCase
{
    private function host(string $sub): string
    {
        return 'http://'.$sub.'.'.config('tenancy.central_domain');
    }

    public function test_subdomain_cadangan_tidak_pernah_menjadi_sekolah(): void
    {
        // Walaupun (secara keliru) ada baris sekolah bernama "admin" di database
        School::factory()->create(['subdomain' => 'admin']);

        $this->getJson($this->host('admin').'/api/school')->assertNotFound();
        $this->getJson($this->host('api').'/api/school')->assertNotFound();
    }

    public function test_www_dialihkan_ke_domain_utama(): void
    {
        $this->get($this->host('www').'/daftar?ref=iklan')
            ->assertStatus(301)->assertRedirect('http://'.config('tenancy.central_domain').'/daftar?ref=iklan');
    }

    public function test_subdomain_huruf_besar_tetap_dikenali(): void
    {
        $s = $this->makeSchool(['subdomain' => 'smkn1']);
        $this->getJson($this->host('SMKN1').'/api/school')->assertOk()->assertJsonPath('subdomain', $s['school']->subdomain);
    }

    public function test_aturan_validasi_subdomain_untuk_pendaftaran(): void
    {
        School::factory()->create(['subdomain' => 'smkn1-bandung']);
        $check = fn ($v) => Validator::make(['s' => $v], ['s' => [new AvailableSubdomain]])->passes();

        $this->assertTrue($check('sman3-yogya'));
        $this->assertFalse($check('smkn1-bandung'), 'sudah dipakai');
        $this->assertFalse($check('www'), 'dicadangkan');
        $this->assertFalse($check('ab'), 'terlalu pendek');
        $this->assertFalse($check('-smk'), 'diawali tanda hubung');
        $this->assertFalse($check('smk_1'), 'garis bawah');
        $this->assertFalse($check('smk.1'), 'titik');
        $this->assertFalse($check('smk--1'), 'tanda hubung ganda');
        $this->assertFalse($check(str_repeat('a', 64)), 'lebih dari 63 karakter');
    }

    public function test_url_sekolah(): void
    {
        config(['app.url' => 'https://mypresence.id', 'tenancy.central_domain' => 'mypresence.id']);
        $this->assertSame('https://smkn1.mypresence.id/api/me', Subdomain::url('smkn1', 'api/me'));
    }

    public function test_cek_dns_wildcard_lulus_bila_semua_mengarah_ke_vps(): void
    {
        $this->app->resolving(CheckTenantDns::class, fn ($c) => $c->setResolver(fn () => ['203.0.113.10']));

        $this->artisan('tenancy:dns-check', ['--expect' => '203.0.113.10'])
            ->expectsOutputToContain('DNS wildcard sudah benar')->assertSuccessful();
    }

    public function test_cek_dns_gagal_bila_wildcard_belum_dibuat(): void
    {
        $domain = config('tenancy.central_domain');
        // Hanya domain pusat yang punya record; subdomain apa pun tidak terdaftar.
        $this->app->resolving(CheckTenantDns::class, fn ($c) => $c->setResolver(fn ($h) => $h === $domain ? ['203.0.113.10'] : []));

        $this->artisan('tenancy:dns-check', ['--expect' => '203.0.113.10'])
            ->expectsOutputToContain('TIDAK TERDAFTAR')->assertFailed();
    }

    public function test_cek_dns_gagal_bila_mengarah_ke_server_lain(): void
    {
        $this->app->resolving(CheckTenantDns::class, fn ($c) => $c->setResolver(fn () => ['198.51.100.7']));

        $this->artisan('tenancy:dns-check', ['--expect' => '203.0.113.10'])
            ->expectsOutputToContain('SALAH ARAH')->assertFailed();
    }
}
