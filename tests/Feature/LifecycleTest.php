<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\AcademicYearClosure;
use App\Models\Classroom;
use App\Models\Student;
use App\Models\StudentEnrollment;
use App\Models\User;
use App\Support\SchoolLevel;
use Tests\TestCase;

/** Tahap 6: tutup tahun ajaran, kelulusan → alumni hanya-baca, mutasi/keluar, surat keterangan, anonimisasi. */
class LifecycleTest extends TestCase
{
    private array $s;

    private Classroom $xi;

    private Classroom $xii;

    private Student $senior;

    protected function setUp(): void
    {
        parent::setUp();
        $this->s = $this->makeSchool(['subdomain' => 'smkn1', 'level' => 'sma']);
        $this->tenant()->run($this->s['school'], function () {
            $this->xi = Classroom::create(['name' => 'XI-FKK 1']);
            $this->xii = Classroom::create(['name' => 'XII-FKK 1']);
            $u = User::factory()->create(['role' => Role::Student, 'username' => '3001', 'password' => 'rahasia123']);
            $this->senior = Student::create(['user_id' => $u->id, 'nis' => '3001', 'name' => 'Bima', 'classroom_id' => $this->xii->id]);
        });
    }

    public function test_penanda_rombel_dan_tingkat_berikutnya(): void
    {
        $this->assertSame('A', SchoolLevel::suffixOf('7A'));
        $this->assertSame('B', SchoolLevel::suffixOf('VIII-B'));
        $this->assertSame('FKK1', SchoolLevel::suffixOf('XI-FKK 1'));
        $this->assertSame('8', SchoolLevel::nextGrade('7', 'smp'));
        $this->assertNull(SchoolLevel::nextGrade('XII', 'sma'));
    }

    public function test_tutup_tahun_ajaran_naik_kelas_dan_lulus_lalu_batalkan(): void
    {
        $school = $this->s['school'];
        $from = $this->tenant()->run($school, fn () => $school->fresh()->currentAcademicYear());

        $this->actingAs($this->s['admin'])->get($this->at($school, 'tahun-ajaran'))->assertOk()->assertSee('Naik ke XI-FKK 1');

        $this->actingAs($this->s['admin'])->post($this->at($school, 'tahun-ajaran/tutup'), [
            'mapping' => [$this->s['classroom']->id => (string) $this->xi->id, $this->xi->id => (string) $this->xii->id, $this->xii->id => 'lulus'],
            'confirm' => 'salah',
        ])->assertSessionHasErrors('confirm');

        $this->actingAs($this->s['admin'])->post($this->at($school, 'tahun-ajaran/tutup'), [
            'mapping' => [$this->s['classroom']->id => (string) $this->xi->id, $this->xi->id => (string) $this->xii->id, $this->xii->id => 'lulus'],
            'confirm' => 'TUTUP',
        ])->assertRedirect();

        $this->tenant()->run($school, function () use ($school, $from) {
            $to = \App\Services\Lifecycle\YearClosure::nextYear($from);
            $this->assertSame($to, $school->fresh()->currentAcademicYear());
            $this->assertSame($this->xi->id, $this->s['student']->fresh()->classroom_id);
            $senior = $this->senior->fresh();
            $this->assertSame('lulus', $senior->status);
            $this->assertFalse($senior->is_active);
            $this->assertTrue($senior->user->is_active); // alumni hanya-baca 90 hari
            $this->assertSame('naik', StudentEnrollment::where('student_id', $this->s['student']->id)->where('academic_year', $from)->value('status'));
            $this->assertSame('XI-FKK 1', StudentEnrollment::where('student_id', $this->s['student']->id)->where('academic_year', $to)->value('classroom_name'));
            $this->assertSame('lulus', StudentEnrollment::where('student_id', $this->senior->id)->where('academic_year', $from)->value('status'));
        });

        // Tidak bisa ditutup dua kali: tahun ajaran baru (hasil penutupan barusan) belum boleh ditutup lagi,
        // kalau tidak siswa naik dua tingkat sekaligus.
        $next = \App\Services\Lifecycle\YearClosure::nextYear($from);
        $this->actingAs($this->s['admin'])->get($this->at($school, 'tahun-ajaran'))->assertSee('sudah ditutup')->assertDontSee('Tutup tahun ajaran '.$next);
        $this->actingAs($this->s['admin'])->post($this->at($school, 'tahun-ajaran/tutup'), [
            'mapping' => [$this->s['classroom']->id => (string) $this->xi->id, $this->xi->id => (string) $this->xii->id, $this->xii->id => 'lulus'],
            'confirm' => 'TUTUP',
        ])->assertSessionHasErrors('mapping');
        $this->tenant()->run($school, fn () => $this->assertSame('XI-FKK 1', Student::find($this->s['student']->id)->classroom->name, 'tidak naik dua kali'));

        $closure = $this->tenant()->run($school, fn () => AcademicYearClosure::first());
        $this->actingAs($this->s['admin'])->post($this->at($school, 'tahun-ajaran/'.$closure->id.'/batal'), ['confirm' => 'TUTUP'])->assertRedirect();

        $this->tenant()->run($school, function () use ($school, $from) {
            $this->assertSame($from, $school->fresh()->currentAcademicYear());
            $this->assertSame($this->s['classroom']->id, $this->s['student']->fresh()->classroom_id);
            $this->assertSame('aktif', $this->senior->fresh()->status);
            $this->assertSame(0, StudentEnrollment::where('academic_year', \App\Services\Lifecycle\YearClosure::nextYear($from))->count());
        });
    }

    public function test_tinggal_kelas_tetap_di_kelasnya(): void
    {
        $school = $this->s['school'];
        $this->actingAs($this->s['admin'])->post($this->at($school, 'tahun-ajaran/tutup'), [
            'mapping' => [$this->s['classroom']->id => (string) $this->xi->id, $this->xi->id => (string) $this->xii->id, $this->xii->id => 'lulus'],
            'repeaters' => [$this->senior->id], 'confirm' => 'TUTUP',
        ])->assertRedirect();

        $this->tenant()->run($school, function () {
            $s = $this->senior->fresh();
            $this->assertSame('aktif', $s->status);
            $this->assertSame($this->xii->id, $s->classroom_id);
            $this->assertEquals(['naik' => 1, 'tinggal' => 1, 'lulus' => 0, 'tetap' => 0], AcademicYearClosure::first()->summary); // urutan kunci JSON MySQL bisa berbeda
        });
    }

    public function test_alumni_hanya_bisa_melihat(): void
    {
        $this->tenant()->run($this->s['school'], fn () => $this->senior->forceFill(['status' => 'lulus', 'is_active' => false, 'left_at' => now()->toDateString(), 'classroom_id' => null])->saveQuietly());
        $u = $this->tenant()->run($this->s['school'], fn () => $this->senior->user);

        $this->actingAs($u)->getJson($this->at($this->s['school'], 'api/me'))->assertOk()
            ->assertJsonPath('user.student.status', 'lulus')->assertJsonPath('user.student.alumni_until', now()->addDays(90)->toDateString());
        $this->actingAs($u)->getJson($this->at($this->s['school'], 'api/attendance/mine'))->assertOk();
        $this->actingAs($u)->postJson($this->at($this->s['school'], 'api/attendance/scan'), ['token' => 'x'])->assertStatus(403)->assertJsonPath('alumni', true);
        $this->actingAs($u)->postJson($this->at($this->s['school'], 'api/notifications/read'), [])->assertOk();
    }

    public function test_mutasi_keluar_mematikan_akun_dan_bisa_diaktifkan_lagi(): void
    {
        $school = $this->s['school'];
        $this->actingAs($this->s['admin'])->post($this->at($school, 'data-siswa/'.$this->s['student']->id.'/keluar'), [
            'type' => 'mutasi_keluar', 'date' => now()->toDateString(), 'reason' => 'Pindah ke SMKN 2',
        ])->assertRedirect();

        $this->tenant()->run($school, function () {
            $st = $this->s['student']->fresh();
            $this->assertSame('mutasi_keluar', $st->status);
            $this->assertFalse($st->user->is_active);
            $this->assertSame(0, \App\Models\UserDevice::query()->where('user_id', $st->user_id)->whereNull('revoked_at')->count());
        });
        $this->postJson($this->at($school, 'api/auth/login'), ['username' => '1001', 'password' => '2009-05-12'])->assertStatus(422);

        $this->actingAs($this->s['admin'])->get($this->at($school, 'data-siswa?status=mutasi_keluar'))->assertOk()->assertSee('Aulia Rahma');
        $this->actingAs($this->s['admin'])->get($this->at($school, 'data-siswa/'.$this->s['student']->id.'/surat'))->assertOk()
            ->assertSee('SURAT KETERANGAN KEHADIRAN')->assertSee('Mutasi keluar');

        $this->actingAs($this->s['admin'])->post($this->at($school, 'data-siswa/'.$this->s['student']->id.'/aktifkan'), ['classroom_id' => $this->s['classroom']->id])->assertRedirect();
        $this->tenant()->run($school, fn () => $this->assertTrue($this->s['student']->fresh()->user->is_active));
    }

    public function test_guru_pensiun_mengosongkan_wali_kelas(): void
    {
        $school = $this->s['school'];
        $this->tenant()->run($school, fn () => $this->s['classroom']->forceFill(['homeroom_teacher_id' => $this->s['teacher']->id])->save());

        $this->actingAs($this->s['admin'])->post($this->at($school, 'data-guru/'.$this->s['teacher']->id.'/keluar'), [
            'type' => 'pensiun', 'date' => now()->toDateString(),
        ])->assertRedirect();

        $this->tenant()->run($school, function () {
            $this->assertNull($this->s['classroom']->fresh()->homeroom_teacher_id);
            $this->assertSame('pensiun', $this->s['teacher']->fresh()->status);
            $this->assertSame(1, \App\Models\UserNotification::where('user_id', $this->s['admin']->id)->where('type', 'homeroom_vacant')->count());
        });
    }

    public function test_anonimisasi_setelah_masa_simpan(): void
    {
        $school = $this->s['school'];
        $this->tenant()->run($school, fn () => $this->senior->forceFill(['status' => 'lulus', 'is_active' => false, 'left_at' => now()->subDays(400)->toDateString(), 'classroom_id' => null])->saveQuietly());

        $this->artisan('lifecycle:daily', ['--school' => 'smkn1'])->assertSuccessful();

        $this->tenant()->run($school, function () {
            $s = $this->senior->fresh();
            $this->assertSame('arsip', $s->status);
            $this->assertSame('Alumni #'.$s->id, $s->name);
            $this->assertSame('X'.$s->id, $s->nis);
            $this->assertFalse($s->user->is_active);
            $this->assertNull($s->user->email);
        });
    }
}
