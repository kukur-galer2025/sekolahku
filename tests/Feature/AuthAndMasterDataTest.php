<?php

namespace Tests\Feature;

use App\Models\Student;
use Tests\TestCase;

class AuthAndMasterDataTest extends TestCase
{
    private array $s;

    protected function setUp(): void
    {
        parent::setUp();
        $this->s = $this->makeSchool(['subdomain' => 'smkn1']);
    }

    public function test_siswa_login_dengan_nis_dan_tanggal_lahir(): void
    {
        $this->fromDevice($this->s['deviceToken'])->postJson($this->at($this->s['school'], 'api/auth/login'), ['username' => '1001', 'password' => '2009-05-12'])
            ->assertOk()->assertJsonPath('user.role', 'student')->assertJsonPath('user.student.classroom.name', 'X-FKK 1');
    }

    public function test_guru_login_dengan_nik_berspasi_tetap_dikenali(): void
    {
        $this->postJson($this->at($this->s['school'], 'api/auth/login'), ['username' => '33010 10101', 'password' => 'rahasia123'])
            ->assertOk()->assertJsonPath('user.role', 'teacher');
    }

    public function test_password_salah_dan_pembatasan_percobaan(): void
    {
        $url = $this->at($this->s['school'], 'api/auth/login');
        for ($i = 0; $i < 5; $i++) {
            $this->postJson($url, ['username' => 'admin', 'password' => 'salah'])->assertStatus(422);
        }
        $this->postJson($url, ['username' => 'admin', 'password' => 'rahasia123'])->assertStatus(429);
    }

    public function test_batas_login_per_akun_berlaku_lintas_ip(): void
    {
        $url = $this->at($this->s['school'], 'api/auth/login');
        for ($i = 1; $i <= 10; $i++) {
            $this->withServerVariables(['REMOTE_ADDR' => "10.0.0.$i"])->postJson($url, ['username' => 'admin', 'password' => 'salah'])->assertStatus(422);
        }
        $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.99'])->postJson($url, ['username' => 'admin', 'password' => 'rahasia123'])->assertStatus(429);
    }

    public function test_password_awal_wajib_diganti_sebelum_memakai_fitur(): void
    {
        $user = $this->s['teacher']->user;
        $this->tenant()->run($this->s['school'], fn () => $user->forceFill(['must_change_password' => true])->save());

        $this->actingAs($user)->getJson($this->at($this->s['school'], 'api/dashboard'))
            ->assertStatus(403)->assertJsonPath('must_change_password', true);
        $this->actingAs($user)->getJson($this->at($this->s['school'], 'api/me'))->assertOk();

        $this->actingAs($user)->putJson($this->at($this->s['school'], 'api/me/password'), [
            'current_password' => 'rahasia123', 'password' => 'PasswordBaru123', 'password_confirmation' => 'PasswordBaru123',
        ])->assertOk();

        $this->actingAs($user)->getJson($this->at($this->s['school'], 'api/dashboard'))->assertOk();
    }

    public function test_siswa_baru_dari_admin_mendapat_password_acak_bukan_nis(): void
    {
        $res = $this->actingAs($this->s['admin'])->postJson($this->at($this->s['school'], 'api/students'), [
            'nis' => '1002', 'name' => 'Bima', 'birth_date' => '2009-01-02',
        ])->assertCreated();

        $pw = $res->json('initial_password');
        $this->assertMatchesRegularExpression('/^[a-z0-9]{8}$/', $pw);
        $this->assertNotSame('1002', $pw);
        $this->assertNotSame('2009-01-02', $pw);
    }

    public function test_password_disimpan_sebagai_hash(): void
    {
        $this->assertNotSame('rahasia123', $this->s['admin']->getRawOriginal('password'));
        $this->assertStringStartsWith('$2y$', $this->s['admin']->getRawOriginal('password'));
    }

    public function test_admin_menambah_guru_mendapat_password_awal_dan_guru_bisa_login(): void
    {
        $res = $this->actingAs($this->s['admin'])->postJson($this->at($this->s['school'], 'api/teachers'), [
            'nik' => '3301010199', 'name' => 'Bu Sari', 'subject' => 'Matematika',
        ])->assertCreated();

        auth()->logout();
        $this->postJson($this->at($this->s['school'], 'api/auth/login'), ['username' => '3301010199', 'password' => $res->json('initial_password')])
            ->assertOk()->assertJsonPath('user.must_change_password', true);
    }

    public function test_kuota_paket_per_siswa_ditegakkan(): void
    {
        $this->s['school']->update(['student_quota' => 1, 'status' => \App\Enums\SchoolStatus::Trial, 'trial_ends_at' => now()->addDays(7)]); // trial: kuota keras; sudah ada 1 siswa
        $this->actingAs($this->s['admin'])->postJson($this->at($this->s['school'], 'api/students'), ['nis' => '1002', 'name' => 'Bima'])
            ->assertStatus(422);

        $this->s['school']->update(['student_quota' => 2]);
        $this->actingAs($this->s['admin'])->postJson($this->at($this->s['school'], 'api/students'), ['nis' => '1002', 'name' => 'Bima', 'birth_date' => '2009-08-03'])
            ->assertCreated();
        $this->tenant()->run($this->s['school'], fn () => $this->assertSame(2, Student::count()));
    }

    public function test_nis_ganda_di_sekolah_yang_sama_ditolak(): void
    {
        $this->actingAs($this->s['admin'])->postJson($this->at($this->s['school'], 'api/students'), ['nis' => '1001', 'name' => 'Duplikat'])
            ->assertStatus(422)->assertJsonValidationErrors('nis');
    }

    public function test_ganti_password(): void
    {
        $this->actingAs($this->s['student']->user)->putJson($this->at($this->s['school'], 'api/me/password'), [
            'current_password' => '2009-05-12', 'password' => 'PasswordBaru1', 'password_confirmation' => 'PasswordBaru1',
        ])->assertOk();

        auth()->logout();
        $this->fromDevice($this->s['deviceToken'])->postJson($this->at($this->s['school'], 'api/auth/login'), ['username' => '1001', 'password' => 'PasswordBaru1'])->assertOk()
            ->assertJsonPath('user.must_change_password', false);
    }
}
