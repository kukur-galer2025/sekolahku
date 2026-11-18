<?php

namespace Tests\Feature;

use App\Models\LessonPeriod;
use App\Models\Schedule;
use App\Services\ClassQrCode;
use Carbon\CarbonImmutable;
use Tests\TestCase;

/**
 * Scan hanya saat jam pelajaran kelas itu berlangsung (bukan istirahat / jam kosong).
 *
 * Jam KBM tes: 1 07:55–08:30 · 2 08:30–09:05 · Istirahat 09:05–09:20 · 3 09:20–09:55
 * 3 November 2026 = Selasa (day 2).
 */
class LessonWindowTest extends TestCase
{
    private array $s;

    protected function setUp(): void
    {
        parent::setUp();
        $this->s = $this->makeSchool(['subdomain' => 'smkn1']);
        $this->tenant()->run($this->s['school'], function () {
            LessonPeriod::create(['label' => 'Istirahat', 'starts_at' => '09:05', 'ends_at' => '09:20', 'is_break' => true, 'sort' => 3]);
            LessonPeriod::create(['label' => '3', 'starts_at' => '09:20', 'ends_at' => '09:55', 'sort' => 4]);
        });
    }

    private function scanAt(string $time)
    {
        $this->travelTo(CarbonImmutable::parse("2026-11-03 $time", 'Asia/Jakarta'));
        $payload = app(ClassQrCode::class)->current($this->s['classroom'], 'static')['payload'];

        return $this->actingAs($this->s['student']->user)->fromDevice($this->s['deviceToken'])
            ->postJson($this->at($this->s['school'], 'api/attendance/scan'), ['code' => $payload]);
    }

    private function schedule(int $day, int $start, int $count): void
    {
        $this->tenant()->run($this->s['school'], fn () => Schedule::create([
            'classroom_id' => $this->s['classroom']->id, 'subject' => 'Matematika', 'day' => $day, 'period_start' => $start, 'period_count' => $count,
        ]));
    }

    public function test_tanpa_jadwal_memakai_jam_kbm_sekolah_dengan_toleransi_30_menit(): void
    {
        $this->scanAt('07:20')->assertStatus(422)->assertJsonPath('errors.code.0', 'Scan dibuka mulai pukul 07:25.');
        $this->scanAt('07:26')->assertOk();
    }

    public function test_setelah_jam_pelajaran_terakhir_ditolak(): void
    {
        $this->scanAt('10:30')->assertStatus(422)->assertJsonPath('errors.code.0', 'Jam pelajaran kelasmu hari ini sudah selesai (pukul 09:55).');
    }

    public function test_saat_istirahat_ditolak(): void
    {
        $this->scanAt('09:10')->assertStatus(422)
            ->assertJsonPath('errors.code.0', 'Sekarang bukan jam pelajaran kelasmu (istirahat atau jam kosong). Scan saat pelajaran berlangsung di kelas.');
        $this->scanAt('09:25')->assertOk()->assertJsonPath('attendance.is_late', true);
    }

    public function test_mengikuti_jadwal_kelas_hari_itu(): void
    {
        $this->schedule(day: 2, start: 2, count: 1); // Selasa hanya jam ke-2 (08:30–09:05)

        $this->scanAt('07:58')->assertStatus(422)->assertJsonPath('errors.code.0', 'Scan dibuka mulai pukul 08:00.');
        $this->scanAt('08:40')->assertOk();
    }

    public function test_jam_kosong_di_antara_pelajaran_ditolak(): void
    {
        $this->schedule(day: 2, start: 1, count: 1); // jam ke-1
        $this->schedule(day: 2, start: 3, count: 1); // jam ke-3; jam ke-2 kosong

        $this->scanAt('08:45')->assertStatus(422);
        $this->scanAt('09:30')->assertOk();
    }

    public function test_kelas_tanpa_pelajaran_hari_ini_ditolak(): void
    {
        $this->schedule(day: 1, start: 1, count: 3); // hanya Senin

        $this->scanAt('08:00')->assertStatus(422)->assertJsonPath('errors.code.0', 'Tidak ada jam pelajaran untuk kelasmu hari ini.');
    }

    public function test_aturan_bisa_dimatikan_admin(): void
    {
        $this->actingAs($this->s['admin'])->putJson($this->at($this->s['school'], 'api/settings/attendance'), ['restrict_to_lessons' => false])
            ->assertOk()->assertJsonPath('restrict_to_lessons', false);
        auth()->logout();

        $this->scanAt('07:00')->assertOk();
    }

    public function test_siswa_melihat_jendela_scan_dan_status_hp(): void
    {
        $this->schedule(day: 2, start: 2, count: 2);
        $this->travelTo(CarbonImmutable::parse('2026-11-03 07:00', 'Asia/Jakarta'));

        $this->actingAs($this->s['student']->user)->fromDevice($this->s['deviceToken'])
            ->getJson($this->at($this->s['school'], 'api/attendance/mine'))->assertOk()
            ->assertJsonPath('on_registered_device', true)
            ->assertJsonPath('scan_windows', [
                ['from' => '08:00', 'to' => '08:30'],
                ['from' => '08:30', 'to' => '09:05'],
                ['from' => '09:20', 'to' => '09:55'],
            ]);
    }
}
