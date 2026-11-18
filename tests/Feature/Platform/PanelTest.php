<?php

namespace Tests\Feature\Platform;

use App\Enums\Role;
use App\Mail\VerifyEmailMail;
use App\Models\AuditLog;
use App\Models\School;
use App\Models\User;
use App\Tenancy\SchoolScope;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class PanelTest extends TestCase
{
    private User $super;

    private array $s;

    private const SECRET = 'JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXP';

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant()->forget();
        $this->super = User::factory()->create(['role' => Role::SuperAdmin, 'username' => 'superadmin', 'name' => 'Dina Support', 'password' => 'SuperRahasia1']);
        $this->super->forceFill(['two_factor_secret' => self::SECRET, 'two_factor_confirmed_at' => now(), 'two_factor_recovery_codes' => ['aaaaaa-bbbbbb']])->save();
        $this->s = $this->makeSchool(['subdomain' => 'smkn1', 'name' => 'SMKN 1 Contoh', 'student_quota' => 500]);
    }

    private function panel(string $path = ''): string
    {
        return 'http://'.config('tenancy.central_domain').'/panel'.($path ? '/'.ltrim($path, '/') : '');
    }

    private function logs(string $action): \Illuminate\Support\Collection
    {
        return AuditLog::query()->withoutGlobalScope(SchoolScope::class)->where('action', $action)->get();
    }

    public function test_tamu_dan_akun_sekolah_tidak_bisa_membuka_panel(): void
    {
        $this->get($this->panel())->assertRedirect($this->panel('masuk'));
        $this->post($this->panel('masuk'), ['username' => 'admin', 'password' => 'rahasia123'])->assertSessionHasErrors('username');
    }

    public function test_super_admin_masuk_dan_melihat_daftar_sekolah_dengan_pemakaian(): void
    {
        $this->post($this->panel('masuk'), ['username' => 'superadmin', 'password' => 'SuperRahasia1'])->assertRedirect($this->panel('2fa'));
        $this->assertGuest(); // password saja belum cukup
        $code = app(\App\Services\Platform\Totp::class)->code(self::SECRET, intdiv(time(), 30));
        $this->post($this->panel('2fa'), ['code' => '000000'])->assertSessionHasErrors('code');
        $this->post($this->panel('2fa'), ['code' => $code])->assertRedirect($this->panel());
        $this->get($this->panel())->assertOk()->assertSee('SMKN 1 Contoh')->assertSee('1 / 500');
        $this->get($this->panel('sekolah/'.$this->s['school']->id))->assertOk()->assertSee('Ukuran data')->assertSee('HP terdaftar');
        $this->assertCount(1, $this->logs('panel.login'));
    }

    public function test_buat_sekolah_dari_panel(): void
    {
        Mail::fake();
        $this->actingAs($this->super)->post($this->panel('sekolah'), [
            'name' => 'SMA Harapan', 'subdomain' => 'sma-harapan', 'timezone' => 'Asia/Jayapura', 'status' => 'active',
            'student_quota' => 1200, 'active_until' => now()->addYear()->toDateString(), 'admin_name' => 'Pak Budi', 'admin_email' => 'budi@harapan.sch.id',
        ])->assertRedirect()->assertSessionHas('created');

        $s = School::firstWhere('subdomain', 'sma-harapan');
        $this->assertSame('active', $s->status->value);
        $this->assertSame(1200, $s->student_quota);
        $pw = session('created')['password'];
        $this->assertNotEmpty($pw);
        Mail::assertSent(VerifyEmailMail::class);
        $this->assertSame($s->id, $this->logs('school.created')->first()->school_id);

        // admin baru bisa login dengan password sementara, wajib ganti
        auth()->logout();
        $this->postJson($this->at($s, 'api/auth/login'), ['username' => 'admin', 'password' => $pw])->assertOk()->assertJsonPath('user.must_change_password', true);
    }

    public function test_tangguhkan_lalu_aktifkan_sekolah(): void
    {
        $id = $this->s['school']->id;
        $this->actingAs($this->super)->post($this->panel("sekolah/$id/tangguhkan"), ['reason' => 'Tagihan belum dibayar'])->assertSessionHas('ok');
        $this->getJson($this->at($this->s['school'], 'api/school'))->assertForbidden();
        $this->assertSame('Tagihan belum dibayar', School::find($id)->suspended_reason);

        $this->actingAs($this->super)->post($this->panel("sekolah/$id/aktifkan"))->assertSessionHas('ok');
        $this->getJson($this->at($this->s['school'], 'api/school'))->assertOk();
        $this->assertCount(1, $this->logs('school.suspended'));
        $this->assertCount(1, $this->logs('school.activated'));
    }

    public function test_ubah_kuota_dan_masa_aktif(): void
    {
        $id = $this->s['school']->id;
        $this->actingAs($this->super)->put($this->panel("sekolah/$id"), [
            'name' => 'SMKN 1 Contoh', 'timezone' => 'Asia/Jakarta', 'student_quota' => 900, 'status' => 'active', 'active_until' => '2027-07-31',
        ])->assertSessionHas('ok');
        $s = School::find($id);
        $this->assertSame(900, $s->student_quota);
        $this->assertSame('2027-07-31', $s->active_until->toDateString());
    }

    public function test_ganti_paket_langganan(): void
    {
        $id = $this->s['school']->id;
        $this->actingAs($this->super)->put($this->panel("sekolah/$id"), [
            'name' => 'SMKN 1 Contoh', 'timezone' => 'Asia/Jakarta', 'student_quota' => 300, 'status' => 'trial', 'plan' => 'pro',
        ])->assertSessionHas('ok');
        $this->assertSame('pro', School::find($id)->plan);

        $this->actingAs($this->super)->put($this->panel("sekolah/$id"), [
            'name' => 'SMKN 1 Contoh', 'timezone' => 'Asia/Jakarta', 'student_quota' => 300, 'status' => 'trial', 'plan' => 'platinum',
        ])->assertSessionHasErrors('plan');
        $this->assertSame('Rp –', \App\Support\Plan::priceLabel('all_service'));
    }

    public function test_super_admin_mengatur_menu_per_paket(): void
    {
        $this->actingAs($this->super)->get($this->panel('paket'))->assertOk()->assertSee('Menu & fitur per paket')->assertSee('Daftar Nilai Sumatif')->assertSee('menu termasuk');
        $this->assertFalse(\App\Support\Plan::allows('starter', 'cbt'));

        // CBT dibuka untuk Pro, Daftar Nilai ditutup untuk Starter; fitur lain tetap seperti bawaan.
        $f = collect(config('billing.features'))->all();
        $f['cbt'] = ['pro', 'all_service'];
        $f['nilai'] = ['pro', 'all_service', 'paket_palsu'];
        $this->actingAs($this->super)->put($this->panel('paket/fitur'), ['features' => $f])->assertSessionHas('ok');
        $this->assertTrue(\App\Support\Plan::allows('pro', 'cbt'));
        $this->assertFalse(\App\Support\Plan::allows('starter', 'nilai'));
        $this->assertSame(['pro', 'all_service'], \App\Support\Plan::featureMap()['nilai']);
        $this->assertContains('nilai', \App\Support\Plan::locked('starter'));
        $this->assertCount(1, $this->logs('plan.features_updated'));

        // Sekolah Starter: API Daftar Nilai ditolak.
        $this->s['school']->forceFill(['plan' => 'starter'])->save();
        $this->actingAs($this->s['teacher']->user)->getJson($this->at($this->s['school'], 'api/grade-books'))->assertForbidden()->assertJsonPath('code', 'plan_locked');
        $this->actingAs($this->s['admin'])->put($this->panel('paket/fitur'), ['features' => []])->assertStatus(403);
    }

    public function test_super_admin_mengisi_harga_paket(): void
    {
        $this->actingAs($this->super)->get($this->panel('paket'))->assertOk()->assertSee('Paket & harga')->assertSee('belum ditetapkan');

        $this->actingAs($this->super)->put($this->panel('paket'), ['price' => ['starter' => '3.500', 'pro' => 'Rp5.000', 'all_service' => '']])
            ->assertSessionHas('ok');
        $this->assertSame(3500, \App\Support\Plan::price('starter'));
        $this->assertSame(5000, \App\Support\Plan::price('pro'));
        $this->assertNull(\App\Support\Plan::price('all_service'));
        $this->assertSame('Rp3.500', \App\Support\Plan::priceLabel('starter'));
        $this->assertCount(1, $this->logs('plan.prices_updated'));

        // Tagihan baru memakai harga paket sekolah.
        $this->s['school']->forceFill(['plan' => 'pro'])->save();
        $q = app(\App\Services\Billing\BillingCalculator::class)->quote(100, 1, 'pro');
        $this->assertSame(5000, $q['unit_price']);

        // Input tidak masuk akal ditolak; harga lama tetap.
        $this->actingAs($this->super)->put($this->panel('paket'), ['price' => ['starter' => 'gratis', 'pro' => '50', 'all_service' => '']])
            ->assertSessionHasErrors(['price.starter', 'price.pro']);
        $this->assertSame(3500, \App\Support\Plan::price('starter'));

        // Admin sekolah tidak bisa membuka halaman ini.
        $this->actingAs($this->s['admin'])->get($this->panel('paket'))->assertForbidden();
    }

    public function test_reset_admin_sekolah(): void
    {
        $this->actingAs($this->super)->post($this->panel('sekolah/'.$this->s['school']->id.'/reset-admin'), ['user_id' => $this->s['admin']->id])->assertSessionHas('reset');
        $pw = session('reset')['password'];
        auth()->logout();
        $this->postJson($this->at($this->s['school'], 'api/auth/login'), ['username' => 'admin', 'password' => $pw])->assertOk();

        // tidak bisa mereset akun sekolah lain lewat sekolah ini
        $b = $this->makeSchool(['subdomain' => 'smkn2']);
        $this->actingAs($this->super)->post($this->panel('sekolah/'.$this->s['school']->id.'/reset-admin'), ['user_id' => $b['admin']->id])->assertNotFound();
    }

    public function test_masuk_sebagai_sekolah_dengan_token_sekali_pakai_dan_tercatat(): void
    {
        $res = $this->actingAs($this->super)->post($this->panel('sekolah/'.$this->s['school']->id.'/bantuan'), ['reason' => 'Bantu impor siswa, tiket #42']);
        $url = $res->headers->get('Location');
        $this->assertStringStartsWith($this->at($this->s['school'], 'bantuan/'), $url);
        auth()->logout();

        $this->get($url)->assertRedirect($this->at($this->s['school'], 'dashboard'));
        $this->assertSame($this->s['admin']->id, auth()->id());
        $this->get($this->at($this->s['school'], 'dashboard'))->assertOk()->assertSee('Mode bantuan Parama Tech')->assertSee('tiket #42');

        // admin sekolah melihat jejaknya di Log Akses
        $this->get($this->at($this->s['school'], 'log-akses'))->assertOk()->assertSee('Dina Support (Parama Tech)');

        $this->post($this->at($this->s['school'], 'bantuan/selesai'))->assertRedirect();
        $this->assertGuest();
        $this->assertCount(1, $this->logs('impersonation.ended'));

        // token sekali pakai
        $this->get($url)->assertForbidden();
    }

    public function test_token_bantuan_kedaluwarsa_dan_tidak_berlaku_di_sekolah_lain(): void
    {
        $b = $this->makeSchool(['subdomain' => 'smkn2']);
        $url = $this->actingAs($this->super)->post($this->panel('sekolah/'.$this->s['school']->id.'/bantuan'), ['reason' => 'Cek laporan'])->headers->get('Location');
        auth()->logout();

        $this->get(str_replace('//smkn1.', '//smkn2.', $url))->assertForbidden();
        $this->travel(3)->minutes();
        $this->get($url)->assertForbidden();
        $this->assertGuest();
    }

    public function test_bantuan_wajib_alasan_dan_tidak_untuk_sekolah_ditangguhkan(): void
    {
        $id = $this->s['school']->id;
        $this->actingAs($this->super)->post($this->panel("sekolah/$id/bantuan"), ['reason' => ''])->assertSessionHasErrors('reason');
        $this->s['school']->update(['status' => 'suspended']);
        $this->actingAs($this->super)->post($this->panel("sekolah/$id/bantuan"), ['reason' => 'Cek data'])->assertStatus(422);
    }

    public function test_panel_bisa_dibatasi_ip_kantor(): void
    {
        config(['tenancy.panel_allowed_ips' => ['203.0.113.0/24']]);
        $this->get($this->panel('masuk'))->assertNotFound();
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.7'])->get($this->panel('masuk'))->assertOk();
    }

    public function test_log_aktivitas_platform(): void
    {
        $this->actingAs($this->super)->post($this->panel('sekolah/'.$this->s['school']->id.'/tangguhkan'), ['reason' => 'Uji']);
        $this->actingAs($this->super)->get($this->panel('log'))->assertOk()->assertSee('Sekolah ditangguhkan');
    }
}
