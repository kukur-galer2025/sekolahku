<?php

namespace Tests\Feature\Programs;

use App\Models\Classroom;
use App\Models\Schedule;
use App\Models\UserDevice;
use App\Services\ClassQrCode;
use App\Services\TeacherAttendanceService;
use Carbon\CarbonImmutable;
use Tests\TestCase;

class TeacherAttendanceTest extends TestCase
{
    private array $s;

    private string $tToken;

    private Schedule $block1;

    private Schedule $block2;

    private Classroom $other;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-11-03 07:40', 'Asia/Jakarta')); // Selasa
        $this->s = $this->makeSchool(['subdomain' => 'smkn1']);
        $this->tenant()->run($this->s['school'], function () {
            $this->other = Classroom::create(['name' => 'X-FKK 2']);
            $this->block1 = Schedule::create(['classroom_id' => $this->s['classroom']->id, 'teacher_id' => $this->s['teacher']->id, 'subject' => 'Farmakologi', 'day' => 2, 'period_start' => 1, 'period_count' => 1]);
            $this->block2 = Schedule::create(['classroom_id' => $this->other->id, 'teacher_id' => $this->s['teacher']->id, 'subject' => 'Farmakologi', 'day' => 2, 'period_start' => 2, 'period_count' => 1]);
            $this->tToken = str_repeat('t', 40);
            UserDevice::create(['user_id' => $this->s['teacher']->user_id, 'token_hash' => hash('sha256', $this->tToken), 'name' => 'Android']);
        });
        $this->tenant()->forget();
    }

    private function gate(): string
    {
        return app(TeacherAttendanceService::class)->gatePayload($this->s['school']->fresh());
    }

    private function classQr(Classroom $c): string
    {
        return $this->tenant()->run($this->s['school'], fn () => app(ClassQrCode::class)->current($c->fresh(), 'static')['payload']);
    }

    private function asTeacher(string $ip = '127.0.0.1')
    {
        return $this->actingAs($this->s['teacher']->user)->fromDevice($this->tToken)->withServerVariables(['REMOTE_ADDR' => $ip]);
    }

    private function api(string $path): string
    {
        return $this->at($this->s['school'], 'api/'.$path);
    }

    public function test_langkah_satu_hadir_di_sekolah_dengan_qr_guru_dari_wifi_sekolah(): void
    {
        // Tanpa HP terdaftar (sebelum cookie HP dipasang di tes ini)
        $this->actingAs($this->s['teacher']->user)->postJson($this->api('teacher-attendance/check-in'), ['code' => $this->gate()])
            ->assertStatus(422)->assertJsonPath('errors.code.0', 'Scan hanya bisa dari HP yang terdaftar untuk akunmu. Keluar lalu masuk lagi dari HP-mu sendiri, atau minta admin sekolah untuk reset perangkat.');
        $this->asTeacher()->postJson($this->api('teacher-attendance/check-in'), ['code' => $this->classQr($this->s['classroom'])])
            ->assertStatus(422)->assertJsonPath('errors.code.0', 'Ini bukan QR kehadiran guru. Scan QR yang ditempel di ruang guru.');
        $this->asTeacher('203.0.113.9')->postJson($this->api('teacher-attendance/check-in'), ['code' => $this->gate()])
            ->assertStatus(422)->assertJsonPath('errors.code.0', 'Sambungkan HP ke WiFi sekolah dan matikan data seluler, lalu scan lagi.');

        $this->asTeacher()->postJson($this->api('teacher-attendance/check-in'), ['code' => $this->gate()])->assertOk()->assertJsonFragment(['message' => 'Kehadiran di sekolah tercatat pukul 07:40. Sekarang kamu bisa absen masuk kelas.']);
        $this->asTeacher()->postJson($this->api('teacher-attendance/check-in'), ['code' => $this->gate()])->assertStatus(422);

        $this->asTeacher()->getJson($this->api('teacher-attendance/me'))->assertOk()->assertJsonPath('check_in.time', '07:40')
            ->assertJsonPath('blocks.0.status', 'jadwal')->assertJsonCount(2, 'blocks')->assertJsonPath('history.0.kind', 'Hadir di sekolah');
    }

    public function test_qr_guru_lama_dan_qr_sekolah_lain_ditolak(): void
    {
        $old = $this->gate();
        app(TeacherAttendanceService::class)->rotateGate($this->s['school']->fresh());
        $this->asTeacher()->postJson($this->api('teacher-attendance/check-in'), ['code' => $old])->assertStatus(422)
            ->assertJsonPath('errors.code.0', 'QR kehadiran guru tidak berlaku. Minta admin mencetak QR terbaru.');

        $b = $this->makeSchool(['subdomain' => 'smkn2']);
        $this->tenant()->forget();
        $otherGate = app(TeacherAttendanceService::class)->gatePayload($b['school']->fresh());
        $this->asTeacher()->postJson($this->api('teacher-attendance/check-in'), ['code' => $otherGate])->assertStatus(422);
    }

    public function test_langkah_dua_masuk_kelas_terkunci_sampai_hadir_lalu_hadir_telat_dan_selesai(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-03 07:58', 'Asia/Jakarta'));
        $enter = fn (Schedule $b, Classroom $c) => $this->asTeacher()->postJson($this->api('teacher-attendance/enter'), ['schedule_id' => $b->id, 'code' => $this->classQr($c)]);

        $enter($this->block1, $this->s['classroom'])->assertStatus(422)->assertJsonPath('errors.code.0', 'Absen kehadiran di sekolah dulu, baru bisa masuk kelas.');
        $this->asTeacher()->postJson($this->api('teacher-attendance/check-in'), ['code' => $this->gate()])->assertOk();

        // QR kelas lain untuk blok ini ditolak.
        $enter($this->block1, $this->other)->assertStatus(422)->assertJsonPath('errors.code.0', 'QR ini bukan untuk kelas X-FKK 1.');
        $enter($this->block1, $this->s['classroom'])->assertOk()->assertJsonPath('message', 'Masuk kelas X-FKK 1 tercatat.');
        $enter($this->block1, $this->s['classroom'])->assertStatus(422);

        // Blok 2 mulai 08:30 → scan 08:45 = terlambat (> 10 menit).
        $this->travelTo(CarbonImmutable::parse('2026-11-03 08:45', 'Asia/Jakarta'));
        $enter($this->block2, $this->other)->assertOk()->assertJsonPath('message', 'Masuk kelas X-FKK 2 tercatat (terlambat).');

        $me = $this->asTeacher()->getJson($this->api('teacher-attendance/me'))->json();
        $this->assertSame(['hadir', 'telat'], array_column($me['blocks'], 'status'));
    }

    public function test_jadwal_guru_lain_dan_jam_yang_sudah_lewat_ditolak(): void
    {
        $this->asTeacher()->postJson($this->api('teacher-attendance/check-in'), ['code' => $this->gate()])->assertOk();
        $foreign = $this->tenant()->run($this->s['school'], function () {
            $u = \App\Models\User::factory()->create(['role' => \App\Enums\Role::Teacher, 'username' => '3302']);
            $t = \App\Models\Teacher::create(['user_id' => $u->id, 'nik' => '3302', 'name' => 'Guru Lain']);

            return Schedule::create(['classroom_id' => $this->s['classroom']->id, 'teacher_id' => $t->id, 'subject' => 'Kimia', 'day' => 2, 'period_start' => 2, 'period_count' => 1]);
        });
        $this->tenant()->forget();
        $this->asTeacher()->postJson($this->api('teacher-attendance/enter'), ['schedule_id' => $foreign->id, 'code' => $this->classQr($this->s['classroom'])])
            ->assertStatus(422)->assertJsonPath('errors.code.0', 'Ini bukan jadwal mengajarmu hari ini.');

        $this->travelTo(CarbonImmutable::parse('2026-11-03 09:30', 'Asia/Jakarta'));
        $this->asTeacher()->postJson($this->api('teacher-attendance/enter'), ['schedule_id' => $this->block1->id, 'code' => $this->classQr($this->s['classroom'])])
            ->assertStatus(422)->assertJsonPath('errors.code.0', 'Jam pelajaran ini sudah selesai (08:30). Hubungi admin bila perlu dicatat manual.');
    }

    public function test_admin_memantau_rekap_dan_mencatat_manual(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-03 09:30', 'Asia/Jakarta'));
        $admin = fn () => $this->actingAs($this->s['admin']);
        $r = $admin()->getJson($this->api('teacher-attendance'))->assertOk()->json();
        $this->assertSame(['teachers' => 1, 'checked_in' => 0, 'blocks' => 2, 'entered' => 0, 'telat' => 0, 'belum' => 0, 'tidak' => 2], $r['summary']);
        $this->assertSame('Belum absen sekolah', $r['teachers'][0]['state'][0]);

        // Masuk kelas manual ditolak sebelum hadir sekolah dicatat.
        $admin()->postJson($this->api('teacher-attendance/manual-enter'), ['schedule_id' => $this->block1->id, 'date' => '2026-11-03'])->assertStatus(422);
        $admin()->postJson($this->api('teacher-attendance/manual'), ['teacher_id' => $this->s['teacher']->id, 'date' => '2026-11-03'])->assertOk();
        $admin()->postJson($this->api('teacher-attendance/manual-enter'), ['schedule_id' => $this->block1->id, 'date' => '2026-11-03'])->assertOk();

        $r = $admin()->getJson($this->api('teacher-attendance?date=2026-11-03'))->json();
        $this->assertSame(1, $r['summary']['checked_in']);
        $this->assertSame(['hadir', 'tidak'], array_column($r['blocks'], 'status'));
        $this->assertSame('manual', $r['teachers'][0]['check_in']['method']);

        // Dashboard admin menampilkan ringkasan guru.
        $admin()->getJson($this->api('dashboard'))->assertJsonPath('teacher_attendance.checked_in', 1)->assertJsonPath('teacher_attendance.entered', 1);
    }

    public function test_isolasi_dan_hak_akses(): void
    {
        // Siswa & guru tidak bisa membuka rekap/QR guru.
        $this->actingAs($this->s['student']->user)->getJson($this->api('teacher-attendance/me'))->assertForbidden();
        $this->actingAs($this->s['teacher']->user)->getJson($this->api('teacher-attendance'))->assertForbidden();
        $this->actingAs($this->s['teacher']->user)->getJson($this->api('teacher-attendance/gate'))->assertForbidden();
        $this->actingAs($this->s['admin'])->get($this->api('teacher-attendance/gate.svg'))->assertOk()->assertHeader('Content-Type', 'image/svg+xml');
        $this->actingAs($this->s['admin'])->get($this->at($this->s['school'], 'cetak-qr'))->assertOk()->assertSee('Kehadiran Guru')->assertSee('MPG-');

        // Admin sekolah lain tidak bisa mencatat guru sekolah ini.
        $b = $this->makeSchool(['subdomain' => 'smkn2']);
        $this->tenant()->forget();
        $this->actingAs($b['admin'])->withSession(['tenant_id' => $b['school']->id])->postJson($this->at($b['school'], 'api/teacher-attendance/manual'), ['teacher_id' => $this->s['teacher']->id, 'date' => '2026-11-03'])->assertNotFound();
    }
}
