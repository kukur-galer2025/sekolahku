<?php

namespace Tests\Feature\Billing;

use App\Support\Plan;
use Tests\TestCase;

/** Batasan fitur & kuota per paket langganan (config billing.features / billing.limits). */
class PlanFeaturesTest extends TestCase
{
    private array $s;

    protected function setUp(): void
    {
        parent::setUp();
        $this->s = $this->makeSchool(['subdomain' => 'smkn1', 'plan' => 'starter']);
        $this->tenant()->forget();
    }

    private function api(string $p): string
    {
        return $this->at($this->s['school'], 'api/'.$p);
    }

    private function setPlan(string $plan): void
    {
        $this->s['school']->forceFill(['plan' => $plan])->save();
    }

    public function test_peta_fitur_sesuai_spreadsheet(): void
    {
        $this->assertSame(['pdf_import', 'jurnal', 'jkelas', 'tugas', 'agenda', 'wali', 'struktur', 'piket', 'cbt', 'leger', 'pembiasaan', 'library', 'denah_editor', 'kas'], Plan::locked('starter'));
        $this->assertSame(['cbt', 'leger', 'pembiasaan', 'library', 'denah_editor'], Plan::locked('pro'));
        $this->assertSame([], Plan::locked('all_service'));
        $this->assertTrue(Plan::allows('starter', 'qr')); // fitur tak terdaftar = semua paket
        $this->assertSame('cbt', Plan::featureForPath('api/cbt/12'));
        $this->assertNull(Plan::featureForPath('api/attendance/scan'));
    }

    public function test_starter_menolak_fitur_pro_dan_all_service(): void
    {
        $guru = $this->s['teacher']->user;
        $this->actingAs($guru)->getJson($this->api('journals'))->assertForbidden()->assertJsonPath('code', 'plan_locked')->assertJsonPath('feature', 'jurnal');
        $this->actingAs($guru)->getJson($this->api('cbt/packages'))->assertForbidden()->assertJsonPath('feature', 'cbt');
        $this->actingAs($this->s['student']->user)->getJson($this->api('my-class'))->assertForbidden();

        // Fitur inti tetap jalan.
        $this->actingAs($guru)->getJson($this->api('dashboard'))->assertOk();
        $this->actingAs($guru)->getJson($this->api('timetable'))->assertOk();
    }

    public function test_pro_membuka_jurnal_tapi_belum_cbt(): void
    {
        $this->setPlan('pro');
        $guru = $this->s['teacher']->user;
        $this->actingAs($guru)->getJson($this->api('journals'))->assertOk();
        $this->actingAs($guru)->getJson($this->api('cbt/packages'))->assertForbidden();

        $this->setPlan('all_service');
        $this->actingAs($guru)->getJson($this->api('cbt/packages'))->assertOk();
    }

    public function test_menu_terkunci_dikirim_ke_aplikasi(): void
    {
        $html = $this->get($this->at($this->s['school'], 'guru'))->assertOk()->getContent();
        $this->assertStringContainsString('"plan":"starter"', $html);
        $this->assertStringContainsString('"locked":["pdf_import"', $html);
    }

    public function test_batas_siswa_sekolah_aktif_mengikuti_paket(): void
    {
        // Sekolah aktif: tidak dibatasi jumlah yang sudah dibayar, hanya batas paket (Starter 300, Pro 1.000, All Service tanpa batas).
        $this->assertSame(300, $this->s['school']->fresh()->effectiveStudentQuota());
        $this->setPlan('pro');
        $this->assertSame(1000, $this->s['school']->fresh()->effectiveStudentQuota());
        $this->setPlan('all_service');
        $this->assertNull($this->s['school']->fresh()->effectiveStudentQuota());
    }
}
