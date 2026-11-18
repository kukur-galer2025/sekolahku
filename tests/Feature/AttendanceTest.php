<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Services\ClassQrCode;
use Carbon\CarbonImmutable;
use Tests\TestCase;

class AttendanceTest extends TestCase
{
    private array $s;

    protected function setUp(): void
    {
        parent::setUp();
        $this->s = $this->makeSchool(['subdomain' => 'smkn1']);
    }

    private function at_(string $localTime, string $tz = 'Asia/Jakarta'): void
    {
        $this->travelTo(CarbonImmutable::parse($localTime, $tz));
    }

    private function scan(?string $code = null)
    {
        $code ??= app(ClassQrCode::class)->current($this->s['classroom'])['payload'];

        return $this->actingAs($this->s['student']->user)->fromDevice($this->s['deviceToken'])->postJson($this->at($this->s['school'], 'api/attendance/scan'), ['code' => $code]);
    }

    public function test_scan_sebelum_batas_tercatat_hadir_tepat_waktu(): void
    {
        $this->at_('2026-11-03 07:40');
        $this->scan()->assertOk()->assertJsonPath('attendance.status', 'H')->assertJsonPath('attendance.is_late', false)
            ->assertJsonPath('attendance.time', '07:40');
    }

    public function test_scan_setelah_batas_tercatat_terlambat(): void
    {
        $this->at_('2026-11-03 08:45');
        $this->scan()->assertOk()->assertJsonPath('attendance.is_late', true);
    }

    public function test_scan_dua_kali_ditolak(): void
    {
        $this->at_('2026-11-03 07:40');
        $this->scan()->assertOk();
        $this->scan()->assertStatus(422);
    }

    public function test_mode_dinamis_qr_kedaluwarsa_ditolak_tetapi_langkah_sebelumnya_masih_diterima(): void
    {
        $this->s['school']->update(['settings' => ['attendance' => ['qr_mode' => 'dynamic', 'networks' => [['cidr' => '127.0.0.1/32']]]]]);
        $this->at_('2026-11-03 07:40:00');
        $old = app(ClassQrCode::class)->current($this->s['classroom'], 'dynamic')['payload'];

        $this->at_('2026-11-03 07:40:35'); // satu langkah kemudian: masih diterima
        $this->scan($old)->assertOk();

        Attendance::query()->withoutGlobalScopes()->delete();
        $this->at_('2026-11-03 07:42:00'); // beberapa langkah kemudian: ditolak
        $this->scan($old)->assertStatus(422);
    }

    public function test_perbarui_qr_membatalkan_kode_lama(): void
    {
        $this->at_('2026-11-03 07:40');
        $old = app(ClassQrCode::class)->current($this->s['classroom'])['payload'];

        $this->actingAs($this->s['admin'])->postJson($this->at($this->s['school'], 'api/classrooms/'.$this->s['classroom']->id.'/qr/rotate'))
            ->assertOk()->assertJsonPath('mode', 'static');
        $this->s['classroom']->refresh();

        $this->scan($old)->assertStatus(422);
    }

    public function test_hari_libur_tidak_bisa_scan(): void
    {
        $this->at_('2026-11-08 07:40'); // Minggu
        $this->scan()->assertStatus(422)->assertJsonPath('errors.code.0', 'Hari ini bukan hari sekolah.');
    }

    public function test_alpa_otomatis_setelah_batas_lalu_scan_menjadi_hadir_terlambat(): void
    {
        $this->at_('2026-11-03 08:20');
        $this->artisan('attendance:mark-absent')->assertSuccessful();
        $this->assertSame(0, Attendance::query()->withoutGlobalScopes()->count(), 'belum lewat batas');

        $this->at_('2026-11-03 08:35');
        $this->artisan('attendance:mark-absent')->assertSuccessful();
        $this->artisan('attendance:mark-absent')->assertSuccessful(); // idempoten
        $a = Attendance::query()->withoutGlobalScopes()->sole();
        $this->assertSame('A', $a->status->value);
        $this->assertSame('auto', $a->method->value);

        $this->scan()->assertOk()->assertJsonPath('attendance.status', 'H')->assertJsonPath('attendance.is_late', true);
    }

    public function test_alpa_otomatis_mengikuti_zona_waktu_masing_masing_sekolah(): void
    {
        $papua = $this->makeSchool(['subdomain' => 'sman1jayapura', 'timezone' => 'Asia/Jayapura']); // WIT = WIB + 2 jam

        // 07:30 WIB = 09:30 WIT: Jakarta belum lewat batas 08:30, Jayapura sudah.
        $this->at_('2026-11-03 07:30');
        $this->artisan('attendance:mark-absent')->assertSuccessful();

        $bySchool = Attendance::query()->withoutGlobalScopes()->pluck('school_id')->all();
        $this->assertSame([$papua['school']->id], $bySchool);
    }

    public function test_guru_hanya_bisa_koreksi_ke_h_s_i(): void
    {
        $this->at_('2026-11-03 09:00');
        $url = $this->at($this->s['school'], 'api/students/'.$this->s['student']->id.'/attendance');

        // Guru yang tidak mengajar / bukan wali kelas siswa ini ditolak.
        $this->actingAs($this->s['teacher']->user)->putJson($url, ['status' => 'S'])->assertForbidden();

        // Setelah mengajar kelas itu (Jadwal), guru boleh mengoreksi.
        $this->tenant()->run($this->s['school'], fn () => \App\Models\Schedule::create([
            'classroom_id' => $this->s['classroom']->id, 'teacher_id' => $this->s['teacher']->id, 'day' => 1, 'period_start' => 1, 'period_count' => 2, 'subject' => 'Matematika',
        ]));
        $this->tenant()->forget();
        $this->actingAs($this->s['teacher']->user);

        $this->putJson($url, ['status' => 'A'])->assertStatus(422);
        $this->putJson($url, ['status' => 'S', 'note' => 'Surat dokter'])->assertOk()->assertJsonPath('attendance.status', 'S')
            ->assertJsonPath('attendance.method', 'manual');
    }

    public function test_rekap_kelas_dan_riwayat_siswa(): void
    {
        $this->at_('2026-11-03 07:40');
        $this->scan()->assertOk();

        $this->actingAs($this->s['teacher']->user)
            ->getJson($this->at($this->s['school'], 'api/classrooms/'.$this->s['classroom']->id.'/attendance'))
            ->assertOk()->assertJsonPath('summary.H', 1)->assertJsonPath('summary.total', 1);

        $this->actingAs($this->s['student']->user)->getJson($this->at($this->s['school'], 'api/attendance/mine'))
            ->assertOk()->assertJsonPath('cutoff', '08:30')->assertJsonPath('today.status', 'H');
    }

    public function test_siswa_tidak_bisa_membuka_qr_atau_data_admin(): void
    {
        $this->actingAs($this->s['student']->user);
        $this->getJson($this->at($this->s['school'], 'api/classrooms/'.$this->s['classroom']->id.'/qr'))->assertForbidden();
        $this->getJson($this->at($this->s['school'], 'api/students'))->assertForbidden();
    }
}
