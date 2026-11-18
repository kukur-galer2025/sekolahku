<?php

namespace Tests\Feature;

use App\Models\Classroom;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/** Kartu login per siswa dari menu Wali Kelas: password sementara baru, hanya kelas perwalian, hanya pembuatnya. */
class LoginCardTest extends TestCase
{
    public function test_wali_kelas_mencetak_kartu_login_siswanya(): void
    {
        $s = $this->makeSchool(['subdomain' => 'smkn1']);
        $api = fn ($p) => $this->at($s['school'], 'api/'.$p);
        $guru = $s['teacher']->user;
        $id = $s['student']->id;

        $this->actingAs($guru)->postJson($api('students/login-cards'), ['student_ids' => [$id]])->assertForbidden(); // belum jadi wali
        $this->tenant()->run($s['school'], fn () => Classroom::query()->whereKey($s['classroom']->id)->update(['homeroom_teacher_id' => $s['teacher']->id]));
        $this->tenant()->forget();

        $this->actingAs($guru)->getJson($api('homeroom'))->assertJsonPath('classrooms.0.students.0.account', fn ($v) => in_array($v, ['belum', 'aktif', 'sementara'], true));
        $r = $this->actingAs($guru)->postJson($api('students/login-cards'), ['student_ids' => [$id]])->assertCreated();
        $url = $r->json('url');
        $page = $this->actingAs($guru)->get($this->at($s['school'], ltrim($url, '/')))->assertOk()->assertSee('Password sementara')->assertSee($s['student']->name);
        preg_match('/class="pw">([a-z0-9]{8})</', $page->getContent(), $m);
        $user = $s['student']->user->fresh();
        $this->assertTrue(Hash::check($m[1], $user->password));
        $this->assertTrue((bool) $user->must_change_password);

        $this->actingAs($s['admin'])->get($this->at($s['school'], ltrim($url, '/')))->assertNotFound(); // hanya pembuatnya
        $this->travel(31)->minutes();
        $this->actingAs($guru)->get($this->at($s['school'], ltrim($url, '/')))->assertNotFound(); // kedaluwarsa
    }

    public function test_admin_mencetak_kartu_login_guru(): void
    {
        $s = $this->makeSchool(['subdomain' => 'smkn1']);
        $api = fn ($p) => $this->at($s['school'], 'api/'.$p);
        $t = $s['teacher'];

        $this->actingAs($s['admin'])->getJson($api('teachers'))->assertOk()->assertJsonPath('0.account', 'belum');
        $this->actingAs($t->user)->postJson($api('teachers/login-cards'), ['teacher_ids' => [$t->id]])->assertForbidden();
        $this->actingAs($s['admin'])->postJson($api('teachers/login-cards'), ['teacher_ids' => []])->assertStatus(422);

        $r = $this->actingAs($s['admin'])->postJson($api('teachers/login-cards'), ['teacher_ids' => [$t->id]])->assertCreated()->assertJsonPath('count', 1)->json();
        $this->assertTrue($t->user->fresh()->must_change_password);
        $this->actingAs($s['admin'])->get($this->at($s['school'], ltrim($r['url'], '/')))->assertOk()
            ->assertSee('Kartu Login Guru')->assertSee($t->name)->assertSee('NIK')->assertSee('Aplikasi Guru');
        $this->assertCount(1, \App\Models\AuditLog::query()->withoutGlobalScopes()->where('action', 'password.cards')->get());
    }
}
