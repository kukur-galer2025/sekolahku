<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Attendance;
use App\Models\Student;
use App\Models\Teacher;
use App\Models\User;
use App\Services\ClassQrCode;
use App\Services\DeviceBinding;
use Carbon\CarbonImmutable;
use Tests\TestCase;

/**
 * 1 akun siswa = 1 HP, dan 1 HP = 1 akun siswa.
 */
class DeviceBindingTest extends TestCase
{
    private array $s;

    protected function setUp(): void
    {
        parent::setUp();
        $this->s = $this->makeSchool(['subdomain' => 'smkn1']);
        $this->travelTo(CarbonImmutable::parse('2026-11-03 07:40', 'Asia/Jakarta'));
    }

    private function login(string $username, string $password, ?string $deviceToken = null)
    {
        $req = $deviceToken ? $this->fromDevice($deviceToken) : $this->withCredentials();

        return $req->postJson($this->at($this->s['school'], 'api/auth/login'), compact('username', 'password'));
    }

    private function newStudent(string $nis): User
    {
        return $this->tenant()->run($this->s['school'], function () use ($nis) {
            $u = User::factory()->create(['role' => Role::Student, 'username' => $nis, 'password' => 'pass-'.$nis]);
            Student::create(['user_id' => $u->id, 'nis' => $nis, 'name' => 'Siswa '.$nis, 'classroom_id' => $this->s['classroom']->id]);

            return $u;
        });
    }

    private function scanWith(User $student, ?string $deviceToken)
    {
        $payload = app(ClassQrCode::class)->current($this->s['classroom'], 'static')['payload'];
        $req = $this->actingAs($student);
        $req = $deviceToken ? $req->fromDevice($deviceToken) : $req;

        return $req->postJson($this->at($this->s['school'], 'api/attendance/scan'), ['code' => $payload]);
    }

    public function test_login_pertama_mendaftarkan_hp_dan_memberi_cookie_perangkat(): void
    {
        $this->newStudent('2001');
        $res = $this->login('2001', 'pass-2001')->assertOk();

        $token = $res->getCookie(DeviceBinding::COOKIE)?->getValue();
        $this->assertNotEmpty($token);

        auth()->logout();
        $this->login('2001', 'pass-2001', $token)->assertOk(); // HP yang sama: boleh
    }

    public function test_akun_siswa_tidak_bisa_login_dari_hp_kedua(): void
    {
        $this->login('1001', '2009-05-12')->assertStatus(422)
            ->assertJsonPath('errors.username.0', 'Akunmu sudah terdaftar di HP lain. Minta wali kelas atau admin untuk reset perangkat bila kamu ganti HP.');
    }

    public function test_satu_hp_tidak_bisa_dipakai_untuk_dua_akun_siswa(): void
    {
        $this->newStudent('2002');

        // Siswa 2002 mencoba masuk di HP milik siswa 1001 (titip absen)
        $this->login('2002', 'pass-2002', $this->s['deviceToken'])->assertStatus(422)
            ->assertJsonPath('errors.username.0', 'HP ini sudah terdaftar untuk akun siswa lain. Satu HP hanya untuk satu siswa.');
    }

    public function test_scan_wajib_dari_hp_terdaftar_milik_sendiri(): void
    {
        $other = $this->newStudent('2003');
        $this->login('2003', 'pass-2003')->assertOk(); // 2003 punya HP sendiri

        $this->scanWith($this->s['student']->user, null)->assertStatus(422);                  // tanpa cookie perangkat
        $this->scanWith($this->s['student']->user, str_repeat('x', 40))->assertStatus(422);   // token karangan
        $this->scanWith($other, $this->s['deviceToken'])->assertStatus(422);                  // HP milik siswa lain
        $this->scanWith($this->s['student']->user, $this->s['deviceToken'])->assertOk();      // HP sendiri
    }

    public function test_presensi_mencatat_perangkat_dan_ip(): void
    {
        $this->scanWith($this->s['student']->user, $this->s['deviceToken'])->assertOk();

        $a = Attendance::query()->withoutGlobalScopes()->sole();
        $this->assertNotNull($a->device_id);
        $this->assertSame('127.0.0.1', $a->ip);
    }

    public function test_wali_kelas_mereset_perangkat_lalu_siswa_bisa_pakai_hp_baru(): void
    {
        $this->s['classroom']->update(['homeroom_teacher_id' => $this->s['teacher']->id]);
        $url = $this->at($this->s['school'], 'api/users/'.$this->s['student']->user->id.'/devices');

        $this->actingAs($this->s['teacher']->user)->getJson($url)->assertOk()->assertJsonPath('devices.0.active', true);
        $this->actingAs($this->s['teacher']->user)->deleteJson($url)->assertOk()->assertJsonPath('revoked', 1);
        auth()->logout();

        // HP lama tidak bisa scan lagi
        $this->scanWith($this->s['student']->user, $this->s['deviceToken'])->assertStatus(422);
        auth()->logout();

        // Setelah reset, siswa bisa masuk lagi (HP lama yang sama maupun HP baru) dan mendapat token baru
        $res = $this->login('1001', '2009-05-12', $this->s['deviceToken'])->assertOk();
        $this->assertNotSame($this->s['deviceToken'], $res->getCookie(DeviceBinding::COOKIE)?->getValue());
    }

    public function test_setelah_reset_hp_baru_tanpa_cookie_juga_bisa_login(): void
    {
        $this->actingAs($this->s['admin'])->deleteJson($this->at($this->s['school'], 'api/users/'.$this->s['student']->user->id.'/devices'))->assertOk();
        auth()->logout();
        $this->flushHeaders();

        $this->postJson($this->at($this->s['school'], 'api/auth/login'), ['username' => '1001', 'password' => '2009-05-12'])->assertOk();
    }

    public function test_guru_yang_bukan_wali_kelas_tidak_bisa_mereset_admin_bisa(): void
    {
        $url = $this->at($this->s['school'], 'api/users/'.$this->s['student']->user->id.'/devices');

        $this->actingAs($this->s['teacher']->user)->deleteJson($url)->assertForbidden();
        $this->actingAs($this->s['admin'])->deleteJson($url)->assertOk();
    }

    public function test_siswa_tidak_bisa_mereset_perangkatnya_sendiri(): void
    {
        $this->actingAs($this->s['student']->user)
            ->deleteJson($this->at($this->s['school'], 'api/users/'.$this->s['student']->user->id.'/devices'))->assertForbidden();
    }

    public function test_guru_maksimal_dua_perangkat_admin_tanpa_batas(): void
    {
        $this->login('3301010101', 'rahasia123')->assertOk(); auth()->logout();
        $this->login('3301010101', 'rahasia123')->assertOk(); auth()->logout();
        $this->login('3301010101', 'rahasia123')->assertStatus(422);

        foreach (range(1, 4) as $_) {
            $this->login('admin', 'rahasia123')->assertOk();
            auth()->logout();
        }
    }

    public function test_admin_sekolah_lain_tidak_bisa_mereset_perangkat_siswa_sekolah_ini(): void
    {
        $b = $this->makeSchool(['subdomain' => 'smkn2']);

        $this->actingAs($b['admin'])
            ->deleteJson($this->at($b['school'], 'api/users/'.$this->s['student']->user->id.'/devices'))->assertNotFound();
    }
}
