<?php

namespace Tests\Feature;

use App\Services\ClassQrCode;
use Carbon\CarbonImmutable;
use Tests\TestCase;

/**
 * QR statis + WiFi sekolah: scan hanya diterima dari IP publik jaringan sekolah.
 */
class SchoolNetworkTest extends TestCase
{
    private array $s;

    private const SCHOOL_IP = '103.10.20.30';   // IP publik WiFi sekolah

    private const HOME_IP = '36.80.1.2';        // kuota / WiFi rumah

    protected function setUp(): void
    {
        parent::setUp();
        $this->s = $this->makeSchool(['subdomain' => 'smkn1', 'settings' => [
            'attendance' => ['qr_mode' => 'static', 'networks' => [['cidr' => self::SCHOOL_IP.'/32', 'label' => 'IndiHome']]],
        ]]);
        $this->travelTo(CarbonImmutable::parse('2026-11-03 07:40', 'Asia/Jakarta'));
    }

    private function payload(): string
    {
        return app(ClassQrCode::class)->current($this->s['classroom'], 'static')['payload'];
    }

    private function scanFrom(string $ip, array $headers = [], ?string $code = null)
    {
        return $this->actingAs($this->s['student']->user)->fromDevice($this->s['deviceToken'])
            ->withServerVariables(['REMOTE_ADDR' => $ip])
            ->withHeaders($headers)
            ->postJson($this->at($this->s['school'], 'api/attendance/scan'), ['code' => $code ?? $this->payload()]);
    }

    public function test_scan_dari_wifi_sekolah_diterima(): void
    {
        $this->scanFrom(self::SCHOOL_IP)->assertOk()->assertJsonPath('attendance.status', 'H');
    }

    public function test_scan_dari_luar_jaringan_sekolah_ditolak(): void
    {
        $this->scanFrom(self::HOME_IP)->assertStatus(422)
            ->assertJsonPath('errors.code.0', 'Sambungkan HP ke WiFi sekolah dan matikan data seluler, lalu scan lagi.');
    }

    public function test_memalsukan_ip_lewat_header_x_forwarded_for_tidak_berhasil(): void
    {
        $this->scanFrom(self::HOME_IP, ['X-Forwarded-For' => self::SCHOOL_IP, 'X-Real-IP' => self::SCHOOL_IP])->assertStatus(422);
    }

    public function test_di_belakang_cloudflare_ip_asli_siswa_terbaca_dan_pemalsuan_tetap_ditolak(): void
    {
        // Seperti produksi: daftar proxy dari config (berlaku walau config di-cache).
        config(['tenancy.trusted_proxies' => '173.245.48.0/20,104.16.0.0/13']);
        $cf = '104.16.1.10';

        // Lewat Cloudflare: REMOTE_ADDR = IP Cloudflare, IP asli di X-Forwarded-For.
        $this->scanFrom($cf, ['X-Forwarded-For' => self::SCHOOL_IP])->assertOk()->assertJsonPath('attendance.status', 'H');
    }

    public function test_di_belakang_cloudflare_siswa_dari_rumah_tetap_ditolak(): void
    {
        config(['tenancy.trusted_proxies' => '104.16.0.0/13']);
        $this->scanFrom('104.16.1.10', ['X-Forwarded-For' => self::HOME_IP])->assertStatus(422);
        // Koneksi langsung (bukan dari Cloudflare) yang mengaku-ngaku lewat header tetap ditolak.
        $this->scanFrom(self::HOME_IP, ['X-Forwarded-For' => self::SCHOOL_IP])->assertStatus(422);
    }

    public function test_belum_ada_jaringan_terdaftar_maka_scan_ditolak(): void
    {
        $this->s['school']->update(['settings' => ['attendance' => ['qr_mode' => 'static', 'networks' => []]]]);
        $this->scanFrom(self::SCHOOL_IP)->assertStatus(422)
            ->assertJsonPath('errors.code.0', 'Jaringan WiFi sekolah belum didaftarkan admin. Presensi QR belum bisa dipakai.');
    }

    public function test_qr_statis_tetap_berlaku_di_hari_lain(): void
    {
        $code = $this->payload();
        $this->travelTo(CarbonImmutable::parse('2026-11-10 07:40', 'Asia/Jakarta')); // seminggu kemudian
        $this->scanFrom(self::SCHOOL_IP, [], $code)->assertOk();
    }

    public function test_qr_statis_bisa_diketik_huruf_kecil(): void
    {
        $this->scanFrom(self::SCHOOL_IP, [], strtolower($this->payload()))->assertOk();
    }

    public function test_status_jaringan_ditampilkan_ke_siswa(): void
    {
        $this->actingAs($this->s['student']->user);
        $this->withServerVariables(['REMOTE_ADDR' => self::SCHOOL_IP])->getJson($this->at($this->s['school'], 'api/attendance/mine'))
            ->assertJsonPath('on_school_network', true);
        $this->withServerVariables(['REMOTE_ADDR' => self::HOME_IP])->getJson($this->at($this->s['school'], 'api/attendance/mine'))
            ->assertJsonPath('on_school_network', false);
    }

    public function test_admin_mendaftarkan_jaringan_saat_ini_lalu_siswa_bisa_scan(): void
    {
        $newIp = '182.253.9.9'; // sekolah pindah ISP / IP berubah
        $this->scanFrom($newIp)->assertStatus(422);

        $this->actingAs($this->s['admin'])->withServerVariables(['REMOTE_ADDR' => $newIp])
            ->postJson($this->at($this->s['school'], 'api/settings/attendance/networks/register-current'), ['label' => 'Biznet lab'])
            ->assertOk()->assertJsonPath('current_ip_allowed', true)->assertJsonPath('networks.1.cidr', $newIp.'/32');

        $this->scanFrom($newIp)->assertOk();
    }

    public function test_ipv6_didaftarkan_sebagai_prefiks_64(): void
    {
        $this->actingAs($this->s['admin'])->withServerVariables(['REMOTE_ADDR' => '2001:db8:1234:5678::abcd'])
            ->postJson($this->at($this->s['school'], 'api/settings/attendance/networks/register-current'))
            ->assertOk()->assertJsonPath('networks.1.cidr', '2001:db8:1234:5678::/64');

        // HP lain di WiFi yang sama mendapat alamat berbeda di prefiks yang sama
        $this->scanFrom('2001:db8:1234:5678:9:8:7:6')->assertOk();
    }

    public function test_rentang_terlalu_lebar_ditolak(): void
    {
        $this->actingAs($this->s['admin'])->putJson($this->at($this->s['school'], 'api/settings/attendance'), [
            'networks' => [['cidr' => '0.0.0.0/0']],
        ])->assertStatus(422);

        $this->actingAs($this->s['admin'])->putJson($this->at($this->s['school'], 'api/settings/attendance'), [
            'networks' => [['cidr' => '103.10.20.0/24', 'label' => 'Blok IP sekolah']], 'qr_mode' => 'static',
        ])->assertOk()->assertJsonPath('networks.0.cidr', '103.10.20.0/24');
    }

    public function test_hanya_admin_yang_bisa_memperbarui_qr_dan_mengatur_jaringan(): void
    {
        $this->actingAs($this->s['teacher']->user);
        $this->postJson($this->at($this->s['school'], 'api/classrooms/'.$this->s['classroom']->id.'/qr/rotate'))->assertForbidden();
        $this->getJson($this->at($this->s['school'], 'api/settings/attendance'))->assertForbidden();
    }

    public function test_jaringan_sekolah_lain_tidak_berlaku(): void
    {
        $other = $this->makeSchool(['subdomain' => 'smkn2', 'settings' => [
            'attendance' => ['qr_mode' => 'static', 'networks' => [['cidr' => self::HOME_IP.'/32']]],
        ]]);

        // IP yang sah untuk sekolah lain tidak membuka presensi di sekolah ini
        $this->scanFrom(self::HOME_IP)->assertStatus(422);
        $this->assertNotSame($other['school']->id, $this->s['school']->id);
    }
}
