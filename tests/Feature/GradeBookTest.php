<?php

namespace Tests\Feature;

use App\Models\Schedule;
use App\Services\Grades\SummativeMath;
use Tests\TestCase;

/** Daftar Nilai Sumatif: guru pengampu mengisi, rumus NA & nilai rapor, ekspor, hak akses. */
class GradeBookTest extends TestCase
{
    public function test_rumus_nilai_sumatif(): void
    {
        $r = SummativeMath::compute(['lm' => [80, 90, null, 70, 100, 55], 'sts_nontes' => 80, 'sts_tes' => 70, 'sas_nontes' => null, 'sas_tes' => 90], 5);
        $this->assertSame(85.0, $r['na_s']);      // (80+90+70+100)/4, LM ke-6 di luar jumlah LM
        $this->assertSame(75.0, $r['na_sts']);
        $this->assertSame(90.0, $r['na_sas']);
        $this->assertSame(83, $r['rapor']);       // (85+75+90)/3 = 83,33
        $this->assertFalse($r['complete']);
        $this->assertNull(SummativeMath::compute([], 5)['rapor']);
        $this->assertSame(84, SummativeMath::compute(['lm' => [83.5], 'sts_nontes' => 84.5, 'sts_tes' => 84.5, 'sas_nontes' => 83.5, 'sas_tes' => 83.5], 1)['rapor']); // 83,83 → 84
    }

    public function test_guru_mengisi_daftar_nilai_kelas_yang_diampu(): void
    {
        $s = $this->makeSchool(['subdomain' => 'smkn1']);
        $api = fn ($p) => $this->at($s['school'], 'api/'.$p);
        $guru = fn () => $this->actingAs($s['teacher']->user);
        $this->tenant()->run($s['school'], fn () => Schedule::create(['classroom_id' => $s['classroom']->id, 'teacher_id' => $s['teacher']->id, 'subject' => 'Matematika', 'day' => 1, 'period_start' => 1, 'period_count' => 2]));
        $this->tenant()->forget();

        $L = $guru()->getJson($api('grade-books'))->assertOk()->json();
        $this->assertSame('Matematika', $L['books'][0]['subject']);
        $this->assertNull($L['books'][0]['book_id']);

        // Mapel yang tidak diampu ditolak.
        $guru()->postJson($api('grade-books'), ['classroom_id' => $s['classroom']->id, 'subject' => 'Fisika'])->assertForbidden();
        $id = $guru()->postJson($api('grade-books'), ['classroom_id' => $s['classroom']->id, 'subject' => 'Matematika'])->assertOk()->json('id');
        $this->assertSame($id, $guru()->postJson($api('grade-books'), ['classroom_id' => $s['classroom']->id, 'subject' => 'Matematika'])->json('id'));

        $b = $guru()->getJson($api("grade-books/$id"))->assertOk()->assertJsonPath('lm_count', 5)->assertJsonPath('phase', 'E')->assertJsonPath('can_edit', true)->json();
        $this->assertSame('Aulia Rahma', $b['students'][0]['name']);

        $sid = $s['student']->id;
        $guru()->putJson($api("grade-books/$id/entries"), ['rows' => [['student_id' => $sid, 'lm' => [101]]]])->assertStatus(422);
        $guru()->putJson($api("grade-books/$id/entries"), ['rows' => [['student_id' => 999999, 'lm' => [80]]]])->assertStatus(422);
        $guru()->putJson($api("grade-books/$id/entries"), ['rows' => [['student_id' => $sid, 'lm' => [80, 90, 85.5, null, 70], 'sts_nontes' => 80, 'sts_tes' => 70, 'sas_nontes' => 90, 'sas_tes' => 88]]])->assertOk();

        $st = $guru()->getJson($api("grade-books/$id"))->json('students.0');
        $this->assertSame(81.38, $st['calc']['na_s']);   // (80+90+85,5+70)/4
        $this->assertEquals(75.0, $st['calc']['na_sts']);
        $this->assertEquals(89.0, $st['calc']['na_sas']);
        $this->assertSame(82, $st['calc']['rapor']);     // (81,375+75+89)/3 = 81,79

        $guru()->putJson($api("grade-books/$id"), ['phase' => 'E', 'lm_count' => 3, 'lm_labels' => ['Bilangan', 'Aljabar', 'Fungsi']])->assertOk()->assertJsonPath('lm_labels.2', 'Fungsi');
        $guru()->putJson($api("grade-books/$id"), ['lm_count' => 11])->assertStatus(422);
        $guru()->get($api("grade-books/$id/export"))->assertOk();
        $guru()->getJson($api('grade-books'))->assertJsonPath('books.0.filled', 1);

        // Admin melihat (hanya baca); siswa & sekolah lain tidak.
        $this->actingAs($s['admin'])->getJson($api("grade-books/$id"))->assertOk()->assertJsonPath('can_edit', false);
        $this->actingAs($s['admin'])->putJson($api("grade-books/$id/entries"), ['rows' => [['student_id' => $sid, 'lm' => [1]]]])->assertForbidden();
        $this->fromDevice($s['deviceToken'])->actingAs($s['student']->user)->getJson($api("grade-books/$id"))->assertForbidden();
        $other = $this->makeSchool(['subdomain' => 'smkn2']);
        $this->actingAs($other['teacher']->user)->getJson($this->at($other['school'], "api/grade-books/$id"))->assertNotFound();
    }

    public function test_struktur_kelas_lengkap(): void
    {
        $s = $this->makeSchool(['subdomain' => 'smkn1']);
        $api = fn ($p) => $this->at($s['school'], 'api/'.$p);
        $this->tenant()->run($s['school'], fn () => $s['classroom']->update(['homeroom_teacher_id' => $s['teacher']->id]));
        $this->tenant()->forget();
        $wali = fn () => $this->actingAs($s['teacher']->user);

        $H = $wali()->getJson($api('homeroom'))->assertOk()->json();
        $this->assertSame('Seksi Kebersihan 2', $H['class_roles']['kebersihan_2']);
        $this->assertCount(16, $H['class_roles']);
        $wali()->putJson($api('students/'.$s['student']->id.'/class-role'), ['class_role' => 'rohani_1'])->assertOk();
        $wali()->putJson($api('students/'.$s['student']->id.'/class-role'), ['class_role' => 'sekretaris'])->assertStatus(422); // kunci lama
        $this->assertSame('rohani_1', $s['student']->fresh()->class_role);
    }
}
