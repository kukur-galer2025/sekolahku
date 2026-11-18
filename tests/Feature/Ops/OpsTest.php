<?php

namespace Tests\Feature\Ops;

use App\Services\Ops\ErrorAlert;
use App\Services\Ops\HealthCheck;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class OpsTest extends TestCase
{
    private function health(string $token = ''): \Illuminate\Testing\TestResponse
    {
        return $this->getJson('http://'.config('tenancy.central_domain').'/health'.($token !== '' ? '?token='.$token : ''));
    }

    public function test_health_butuh_token_dan_melaporkan_komponen(): void
    {
        config(['app.health_token' => 'rahasia-monitor']);
        $this->health()->assertNotFound();
        $this->health('salah')->assertNotFound();

        // Belum ada detak scheduler (cron mati) → 503 agar monitor uptime berbunyi.
        $this->health('rahasia-monitor')->assertStatus(503)->assertJsonPath('status', 'fail')
            ->assertJsonPath('checks.scheduler.ok', false)->assertJsonPath('checks.database.ok', true);

        Cache::forever(HealthCheck::HEARTBEAT, now()->getTimestamp());
        $this->health('rahasia-monitor')->assertOk()->assertJsonPath('status', 'ok')
            ->assertJsonStructure(['checks' => ['database', 'cache', 'scheduler', 'queue', 'storage', 'disk']]);

        // Detak terakhir > 3 menit lalu → gagal lagi.
        Cache::forever(HealthCheck::HEARTBEAT, now()->subMinutes(10)->getTimestamp());
        $this->health('rahasia-monitor')->assertStatus(503);
    }

    public function test_health_nonaktif_tanpa_token_di_env(): void
    {
        config(['app.health_token' => null]);
        $this->health()->assertNotFound();
        $this->get('http://'.config('tenancy.central_domain').'/up')->assertOk()->assertSee('OK'); // cek hidup tanpa token
        $this->get('http://smkn1.'.config('tenancy.central_domain').'/up')->assertOk(); // juga di subdomain sekolah
    }

    public function test_alert_error_produksi_dikirim_sekali_per_15_menit(): void
    {
        config(['app.ops_alert_email' => 'ops@paramatech.id', 'mail.default' => 'array']);
        $this->app['env'] = 'production';

        $e = new \RuntimeException('Koneksi Redis putus');
        ErrorAlert::report($e);
        ErrorAlert::report($e); // error yang sama → tidak dikirim lagi
        ErrorAlert::report(new \LogicException('Lain'));

        $this->app['env'] = 'testing';
        ErrorAlert::report(new \RuntimeException('Tidak dikirim di testing'));

        $sent = Mail::mailer('array')->getSymfonyTransport()->messages();
        $this->assertCount(2, $sent);
        $this->assertStringContainsString('Koneksi Redis putus', $sent[0]->getOriginalMessage()->getSubject());
        $this->assertSame('ops@paramatech.id', $sent[0]->getOriginalMessage()->getTo()[0]->getAddress());
    }
}
