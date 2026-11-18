<?php

namespace Tests\Feature\Pwa;

use App\Enums\AttendanceMethod;
use App\Enums\AttendanceStatus;
use App\Enums\Role;
use App\Enums\SchoolStatus;
use App\Models\Attendance;
use App\Models\Classroom;
use App\Models\Schedule;
use App\Models\Student;
use App\Models\Teacher;
use App\Models\User;
use Tests\TestCase;

class PwaTest extends TestCase
{
    private array $a;

    private array $b;

    protected function setUp(): void
    {
        parent::setUp();
        $this->a = $this->makeSchool(['subdomain' => 'smkn1', 'name' => 'SMKN 1 Contoh']);
        $this->b = $this->makeSchool(['subdomain' => 'smkn2', 'name' => 'SMKN 2 Lain']);
        $this->tenant()->run($this->a['school'], function () {
            Schedule::create(['classroom_id' => $this->a['classroom']->id, 'teacher_id' => $this->a['teacher']->id, 'subject' => 'Matematika',
                'day' => $this->a['school']->localNow()->dayOfWeekIso, 'period_start' => 1, 'period_count' => 2]);
        });
        $this->tenant()->forget();
    }

    private function api(array $who, string $path): string
    {
        return $this->at($who['school'], 'api/'.$path);
    }

    public function test_shell_manifest_dan_service_worker(): void
    {
        $this->get($this->at($this->a['school'], '/'))->assertRedirect('/app');
        $this->get($this->at($this->a['school'], 'app'))->assertOk()
            ->assertSee('rel="manifest" href="/manifest.webmanifest"', false)->assertSee('/pwa/app.js?v=', false)->assertSee('SMKN 1 Contoh');
        $m = $this->get($this->at($this->a['school'], 'manifest.webmanifest'))->assertOk()->assertHeader('Content-Type', 'application/manifest+json');
        $this->assertSame('MyPresence · SMKN 1 Contoh', $m->json('name'));
        $this->assertSame('/app', $m->json('start_url'));
        $this->assertSame('standalone', $m->json('display'));
        $this->assertContains('maskable', array_column($m->json('icons'), 'purpose'));
        foreach (['sw.js', 'pwa/app.js', 'pwa/pages.js', 'pwa/student.js', 'pwa/app.css', 'pwa/vendor/jsQR.js', 'pwa/icons/icon-192.png', 'pwa/icons/maskable-512.png'] as $f) {
            $this->assertFileExists(public_path($f));
        }
        // Service worker tidak boleh menyimpan request yang mengubah data (presensi wajib online).
        $sw = file_get_contents(public_path('sw.js'));
        $this->assertStringContainsString("if (req.method !== 'GET') return;", $sw);
        $this->assertStringNotContainsString('attendance/scan', $sw);
    }

    public function test_jadwal_sesuai_peran_dan_terisolasi(): void
    {
        $this->actingAs($this->a['student']->user)->getJson($this->api($this->a, 'timetable'))->assertOk()
            ->assertJsonPath('classroom.name', 'X-FKK 1')->assertJsonPath('blocks.0.subject', 'Matematika')->assertJsonPath('blocks.0.from', '07:55')->assertJsonPath('blocks.0.to', '09:05');
        $this->actingAs($this->a['teacher']->user)->getJson($this->api($this->a, 'timetable'))->assertOk()->assertJsonCount(1, 'blocks');
        $this->actingAs($this->a['admin'])->getJson($this->api($this->a, 'timetable?classroom_id='.$this->a['classroom']->id))->assertOk()->assertJsonCount(1, 'blocks');
        // Kelas sekolah lain → 404
        $this->actingAs($this->a['admin'])->getJson($this->api($this->a, 'timetable?classroom_id='.$this->b['classroom']->id))->assertNotFound();
    }

    public function test_jurnal_guru_dengan_snapshot_presensi_dan_tugas(): void
    {
        $this->tenant()->run($this->a['school'], fn () => Attendance::create(['student_id' => $this->a['student']->id, 'classroom_id' => $this->a['classroom']->id,
            'date' => $this->a['school']->localNow()->toDateString(), 'status' => AttendanceStatus::Present, 'method' => AttendanceMethod::Qr, 'scanned_at' => now()]));
        $this->tenant()->forget();

        $t = $this->actingAs($this->a['teacher']->user);
        $t->getJson($this->api($this->a, 'journals/prefill?classroom_id='.$this->a['classroom']->id))->assertOk()
            ->assertJsonPath('period_start', 1)->assertJsonPath('period_end', 2)->assertJsonPath('subject', 'Matematika')->assertJsonPath('attendance.H', 1);
        $t->postJson($this->api($this->a, 'journals'), ['classroom_id' => $this->a['classroom']->id, 'period_start' => 1, 'period_end' => 2, 'topic' => 'Persamaan linear',
            'task_title' => 'Latihan bab 2', 'task_due' => now()->addDays(3)->toDateString()])->assertCreated()->assertJsonPath('journal.present', 1)->assertJsonPath('journal.total', 1);

        // Siswa kelas itu melihat jurnal & tugasnya; siswa sekolah lain tidak.
        $this->actingAs($this->a['student']->user)->getJson($this->api($this->a, 'journals'))->assertOk()->assertJsonPath('data.0.topic', 'Persamaan linear');
        $this->actingAs($this->a['student']->user)->getJson($this->api($this->a, 'assignments'))->assertOk()->assertJsonPath('0.title', 'Latihan bab 2')->assertJsonPath('0.done', false);
        $this->flushSession();
        $this->actingAs($this->b['student']->user)->getJson($this->api($this->b, 'journals'))->assertOk()->assertJsonCount(0, 'data');

        // Guru sekolah B tidak bisa menulis jurnal untuk kelas sekolah A.
        $this->actingAs($this->b['teacher']->user)->postJson($this->api($this->b, 'journals'), ['classroom_id' => $this->a['classroom']->id, 'period_start' => 1, 'period_end' => 1, 'topic' => 'x'])->assertNotFound();
        // Siswa tidak bisa menulis jurnal.
        $this->flushSession();
        $this->actingAs($this->a['student']->user)->postJson($this->api($this->a, 'journals'), ['classroom_id' => $this->a['classroom']->id, 'period_start' => 1, 'period_end' => 1, 'topic' => 'x'])->assertForbidden();
    }

    public function test_tugas_dicentang_siswa_dan_terlihat_guru(): void
    {
        $t = $this->actingAs($this->a['teacher']->user);
        $t->postJson($this->api($this->a, 'assignments'), ['classroom_id' => $this->a['classroom']->id, 'title' => 'Ringkasan', 'due_date' => now()->subDay()->toDateString()])->assertStatus(422);
        $id = $t->postJson($this->api($this->a, 'assignments'), ['classroom_id' => $this->a['classroom']->id, 'title' => 'Ringkasan', 'due_date' => now()->addDay()->toDateString()])->assertCreated()->json('assignment.id');

        $this->actingAs($this->a['student']->user)->putJson($this->api($this->a, "assignments/$id/done"))->assertOk();
        $this->actingAs($this->a['student']->user)->getJson($this->api($this->a, 'dashboard'))->assertOk()->assertJsonCount(0, 'pending_tasks');
        $this->actingAs($this->a['teacher']->user)->getJson($this->api($this->a, "assignments/$id"))->assertOk()->assertJsonPath('students.0.done', true);
        $this->actingAs($this->a['teacher']->user)->getJson($this->api($this->a, 'assignments'))->assertOk()->assertJsonPath('0.done_count', 1)->assertJsonPath('0.student_count', 1);

        // Siswa kelas lain di sekolah yang sama tidak bisa mencentang.
        $other = $this->tenant()->run($this->a['school'], function () {
            $c = Classroom::create(['name' => 'XI-TKJ 1']);
            $u = User::factory()->create(['role' => Role::Student, 'username' => '2002']);
            Student::create(['user_id' => $u->id, 'nis' => '2002', 'name' => 'Lain', 'classroom_id' => $c->id]);

            return $u;
        });
        $this->tenant()->forget();
        $this->actingAs($other)->putJson($this->api($this->a, "assignments/$id/done"))->assertNotFound();
        // Siswa sekolah lain → 404 (tidak terlihat sama sekali)
        $this->flushSession();
        $this->actingAs($this->b['student']->user)->putJson($this->api($this->b, "assignments/$id/done"))->assertNotFound();
    }

    public function test_agenda_dan_informasi_menurut_peran(): void
    {
        $adm = $this->actingAs($this->a['admin']);
        $adm->putJson($this->api($this->a, 'agenda/sekolah'), ['title' => 'Agenda November', 'body' => "1 Nov: Upacara\n10 Nov: Hari Pahlawan", 'coordinator_id' => $this->a['teacher']->id])->assertOk();
        $adm->putJson($this->api($this->a, 'agenda/briefing'), ['title' => 'Briefing', 'body' => 'Rapat guru Senin'])->assertOk();
        $adm->putJson($this->api($this->a, 'agenda/sekolah'), ['title' => 'x', 'body' => 'y', 'coordinator_id' => $this->b['teacher']->id])->assertStatus(422);
        $adm->putJson($this->api($this->a, 'info-coordinators/kesiswaan'), ['teacher_id' => $this->a['teacher']->id])->assertOk();
        $koor = $this->actingAs($this->a['teacher']->user);
        $koor->postJson($this->api($this->a, 'announcements'), ['title' => 'Untuk guru', 'body' => 'Rapat', 'audience' => 'guru', 'category' => 'kesiswaan'])->assertCreated();
        $koor->postJson($this->api($this->a, 'announcements'), ['title' => 'Untuk semua', 'body' => 'Libur', 'audience' => 'semua', 'category' => 'kesiswaan'])->assertCreated();

        $s = $this->actingAs($this->a['student']->user)->getJson($this->api($this->a, 'agenda'))->assertOk();
        $this->assertSame(['sekolah'], array_keys($s->json()));
        $this->assertSame('Agenda November', $s->json('sekolah.page.title'));
        $this->assertSame(['Untuk semua'], array_column($this->actingAs($this->a['student']->user)->getJson($this->api($this->a, 'announcements'))->json(), 'title'));
        $this->assertCount(2, $this->actingAs($this->a['teacher']->user)->getJson($this->api($this->a, 'announcements'))->json());
        $this->assertSame(['sekolah', 'briefing'], array_keys($this->actingAs($this->a['teacher']->user)->getJson($this->api($this->a, 'agenda'))->json()));

        // Guru koordinator Agenda Sekolah boleh mengedit isinya; Agenda Briefing (bukan koordinatornya) tidak.
        $this->actingAs($this->a['teacher']->user)->putJson($this->api($this->a, 'agenda/sekolah'), ['title' => 'x', 'body' => 'y'])->assertOk();
        $this->actingAs($this->a['teacher']->user)->putJson($this->api($this->a, 'agenda/briefing'), ['title' => 'x', 'body' => 'y'])->assertForbidden();
        $this->actingAs($this->a['student']->user)->putJson($this->api($this->a, 'agenda/sekolah'), ['title' => 'x', 'body' => 'y'])->assertForbidden();
        $this->flushSession(); // sesi terikat ke sekolah A; pindah sekolah = sesi baru
        $this->actingAs($this->b['admin'])->getJson($this->api($this->b, 'agenda'))->assertOk()->assertJsonPath('sekolah.page', null);
    }

    public function test_wali_kelas_jabatan_dan_qr(): void
    {
        $this->actingAs($this->a['teacher']->user)->getJson($this->api($this->a, 'homeroom'))->assertOk()->assertJsonCount(0, 'classrooms');
        $this->actingAs($this->a['teacher']->user)->putJson($this->api($this->a, 'students/'.$this->a['student']->id.'/class-role'), ['class_role' => 'ketua'])->assertForbidden();

        $this->actingAs($this->a['admin'])->putJson($this->api($this->a, 'classrooms/'.$this->a['classroom']->id), ['name' => 'X-FKK 1', 'homeroom_teacher_id' => $this->a['teacher']->id])->assertOk();
        $this->actingAs($this->a['teacher']->user)->getJson($this->api($this->a, 'homeroom'))->assertOk()->assertJsonPath('classrooms.0.students.0.devices', 1);
        $this->actingAs($this->a['teacher']->user)->putJson($this->api($this->a, 'students/'.$this->a['student']->id.'/class-role'), ['class_role' => 'ketua'])->assertOk();
        $this->assertSame('ketua', $this->a['student']->fresh()->class_role);

        $svg = $this->actingAs($this->a['teacher']->user)->get($this->api($this->a, 'classrooms/'.$this->a['classroom']->id.'/qr.svg'))->assertOk()->assertHeader('Content-Type', 'image/svg+xml');
        $this->assertStringContainsString('<svg', $svg->getContent());
        $this->actingAs($this->a['student']->user)->get($this->api($this->a, 'classrooms/'.$this->a['classroom']->id.'/qr.svg'), ['Accept' => 'application/json'])->assertForbidden();
        $this->actingAs($this->a['teacher']->user)->get($this->api($this->a, 'classrooms/'.$this->b['classroom']->id.'/qr.svg'), ['Accept' => 'application/json'])->assertNotFound();

        $this->actingAs($this->a['admin'])->get($this->at($this->a['school'], 'cetak-qr'))->assertOk()->assertSee('X-FKK 1')->assertSee('MP-'.$this->a['classroom']->id.'-');
        $this->actingAs($this->a['teacher']->user)->get($this->at($this->a['school'], 'cetak-qr'))->assertForbidden();
    }

    public function test_dashboard_per_peran(): void
    {
        $this->actingAs($this->a['admin'])->getJson($this->api($this->a, 'dashboard'))->assertOk()->assertJsonPath('students', 1)->assertJsonPath('per_classroom.0.belum', 1);
        $this->actingAs($this->a['teacher']->user)->getJson($this->api($this->a, 'dashboard'))->assertOk()->assertJsonPath('hours_today', 2)->assertJsonPath('my_classrooms.0.name', 'X-FKK 1');
        $this->actingAs($this->a['student']->user)->getJson($this->api($this->a, 'dashboard'))->assertOk()->assertJsonPath('attendance', null)->assertJsonPath('today_lessons.0.subject', 'Matematika');
    }

    public function test_guru_dan_siswa_masuk_lewat_portal_diarahkan_ke_aplikasi(): void
    {
        $this->post($this->at($this->a['school'], 'masuk'), ['username' => '3301010101', 'password' => 'rahasia123'])->assertRedirect('/guru');
    }

    public function test_shell_tetap_terbuka_saat_diblokir_tagihan_tetapi_data_402(): void
    {
        $this->a['school']->forceFill(['status' => SchoolStatus::Suspended, 'suspension_type' => 'billing', 'suspended_at' => now()])->save();
        $this->get($this->at($this->a['school'], 'app'))->assertOk();
        $this->actingAs($this->a['student']->user)->getJson($this->api($this->a, 'dashboard'))->assertStatus(402);
        // api/me sengaja terbuka saat diblokir (profil untuk layar "diblokir"); data lain tetap 402.
        $this->actingAs($this->a['student']->user)->getJson($this->api($this->a, 'me'))->assertOk();
        $this->actingAs($this->a['student']->user)->getJson($this->api($this->a, 'timetable'))->assertStatus(402);
    }
}
