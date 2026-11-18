<?php

namespace Tests\Feature;

use Tests\TestCase;

/** Pengumpulan tugas: siswa kumpul berkas, guru melihat & memberi nilai, siswa melihat nilai. */
class AssignmentGradingTest extends TestCase
{
    public function test_guru_melihat_pengumpulan_dan_memberi_nilai(): void
    {
        $s = $this->makeSchool(['subdomain' => 'smkn1']);
        $api = fn ($p) => $this->at($s['school'], 'api/'.$p);
        $guru = fn () => $this->actingAs($s['teacher']->user);
        $siswa = fn () => $this->fromDevice($s['deviceToken'])->actingAs($s['student']->user);
        $today = now()->setTimezone('Asia/Jakarta')->toDateString();

        $id = $guru()->postJson($api('assignments'), ['classroom_id' => $s['classroom']->id, 'subject' => 'B. Jawa', 'title' => 'PR', 'due_date' => $today])->assertCreated()->json('assignment.id');
        $siswa()->post($api('assignment-files'), ['folder' => 'B. Jawa', 'assignment_id' => $id, 'file' => \Illuminate\Http\UploadedFile::fake()->image('tugas.jpg', 600, 400)], ['Accept' => 'application/json'])->assertCreated();

        $d = $guru()->getJson($api("assignments/$id"))->assertOk()->json('students.0');
        $this->assertTrue($d['done']);
        $this->assertCount(1, $d['files']);
        $this->assertFalse($d['late']);

        $guru()->putJson($api("assignments/$id/grades/".$s['student']->id), ['score' => 101])->assertStatus(422);
        $guru()->putJson($api("assignments/$id/grades/".$s['student']->id), ['score' => 88.5, 'feedback' => 'Bagus'])->assertOk()->assertJsonPath('graded', true);
        $guru()->getJson($api('assignments'))->assertJsonPath('0.graded_count', 1)->assertJsonPath('0.file_count', 1);

        $siswa()->getJson($api('assignments'))->assertJsonPath('0.score', 88.5)->assertJsonPath('0.feedback', 'Bagus');
        $siswa()->deleteJson($api("assignments/$id/done"))->assertStatus(422); // sudah dinilai
        $guru()->get($api("assignments/$id/export"))->assertOk();

        // Word (.docx) diterima; berkas palsu berekstensi .docx ditolak.
        $docx = tempnam(sys_get_temp_dir(), 'w').'.docx';
        $z = new \ZipArchive;
        $z->open($docx, \ZipArchive::CREATE);
        $z->addFromString('word/document.xml', '<w:document/>');
        $z->close();
        $siswa()->post($api('assignment-files'), ['folder' => 'B. Jawa', 'file' => new \Illuminate\Http\UploadedFile($docx, 'Tugas Bab 3.docx', null, null, true)], ['Accept' => 'application/json'])
            ->assertCreated()->assertJsonPath('file.type', 'DOCX');
        $siswa()->post($api('assignment-files'), ['folder' => 'B. Jawa', 'file' => \Illuminate\Http\UploadedFile::fake()->createWithContent('palsu.docx', 'bukan word')], ['Accept' => 'application/json'])
            ->assertStatus(422);

        $other = $this->makeSchool(['subdomain' => 'smkn2']);
        $this->actingAs($other['teacher']->user)->getJson($this->at($other['school'], "api/assignments/$id"))->assertNotFound();
    }

    public function test_guru_mendapat_notifikasi_saat_siswa_mengumpulkan(): void
    {
        $s = $this->makeSchool(['subdomain' => 'smkn1']);
        $api = fn ($p) => $this->at($s['school'], 'api/'.$p);
        $guru = fn () => $this->actingAs($s['teacher']->user);
        $siswa = fn () => $this->fromDevice($s['deviceToken'])->actingAs($s['student']->user);
        $today = now()->setTimezone('Asia/Jakarta')->toDateString();
        $id = $guru()->postJson($api('assignments'), ['classroom_id' => $s['classroom']->id, 'subject' => 'B. Jawa', 'title' => 'PR Bab 2', 'due_date' => $today])->json('assignment.id');
        $notif = fn () => \Illuminate\Support\Facades\DB::table('user_notifications')->where('user_id', $s['teacher']->user->id)->where('type', 'submission');

        // Unggah 2 berkas beruntun → 1 notifikasi saja.
        foreach (['a.jpg', 'b.jpg'] as $n) {
            $siswa()->post($api('assignment-files'), ['folder' => 'B. Jawa', 'assignment_id' => $id, 'file' => \Illuminate\Http\UploadedFile::fake()->image($n, 400, 300)], ['Accept' => 'application/json'])->assertCreated();
        }
        $this->assertSame(1, $notif()->count());
        $n = $notif()->first();
        $this->assertStringContainsString('mengumpulkan: PR Bab 2', $n->title);
        $this->assertStringContainsString('1/1 terkumpul', $n->body);
        $this->assertStringEndsWith('#/tugas?a='.$id, $n->url);
        $guru()->getJson($api('notifications'))->assertOk()->assertJsonPath('items.0.type', 'submission');

        // Berkas tanpa tugas (arsip pribadi) tidak memberi tahu guru; tanda selesai ulang juga tidak.
        $siswa()->post($api('assignment-files'), ['folder' => 'B. Jawa', 'file' => \Illuminate\Http\UploadedFile::fake()->image('c.jpg', 400, 300)], ['Accept' => 'application/json'])->assertCreated();
        $siswa()->putJson($api("assignments/$id/done"))->assertOk();
        $this->assertSame(1, $notif()->count());
    }

    public function test_tenggat_memakai_hari_tanggal_dan_jam(): void
    {
        $s = $this->makeSchool(['subdomain' => 'smkn1']);
        $api = fn ($p) => $this->at($s['school'], 'api/'.$p);
        $guru = fn () => $this->actingAs($s['teacher']->user);
        $this->travelTo(\Illuminate\Support\Carbon::parse('2026-11-12 10:00', 'Asia/Jakarta'));

        $base = ['classroom_id' => $s['classroom']->id, 'subject' => 'B. Jawa', 'title' => 'PR'];
        $guru()->postJson($api('assignments'), $base + ['due_date' => '2026-11-12', 'due_time' => '09:00'])->assertStatus(422)->assertJsonValidationErrors('due_time');
        $guru()->postJson($api('assignments'), $base + ['due_date' => '2026-11-13', 'due_time' => '25:00'])->assertStatus(422);
        $id = $guru()->postJson($api('assignments'), $base + ['due_date' => '2026-11-13', 'due_time' => '14:00'])->assertCreated()
            ->assertJsonPath('assignment.due_time', '14:00')->json('assignment.id');

        // Dikumpulkan setelah jam 14:00 → terlambat.
        $this->travelTo(\Illuminate\Support\Carbon::parse('2026-11-13 14:05', 'Asia/Jakarta'));
        $this->fromDevice($s['deviceToken'])->actingAs($s['student']->user)
            ->post($api('assignment-files'), ['folder' => 'B. Jawa', 'assignment_id' => $id, 'file' => \Illuminate\Http\UploadedFile::fake()->image('t.jpg', 300, 200)], ['Accept' => 'application/json'])->assertCreated();
        $this->assertTrue($guru()->getJson($api("assignments/$id"))->json('students.0.late'));
        $this->fromDevice($s['deviceToken'])->actingAs($s['student']->user)->getJson($api('my-folders'))->assertJsonPath('folders.0.tasks.0.due_time', '14:00');
    }
}
