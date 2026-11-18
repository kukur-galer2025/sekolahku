<?php

namespace Tests\Feature\Programs;

use App\Models\CbtAnswer;
use App\Models\CbtAttempt;
use App\Models\CbtExamClass;
use App\Models\Classroom;
use App\Models\GradeBook;
use App\Models\GradeEntry;
use App\Models\Schedule;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/** Alur CBT: guru buat paket → admin terima → ujian (token per kelas) → siswa mengerjakan → nilai PG → essay dinilai → nilai akhir. */
class CbtTest extends TestCase
{
    private array $s;

    protected function setUp(): void
    {
        parent::setUp();
        $this->s = $this->makeSchool(['subdomain' => 'smkn1']);
        $this->tenant()->run($this->s['school'], fn () => Schedule::create(['classroom_id' => $this->s['classroom']->id, 'teacher_id' => $this->s['teacher']->id,
            'subject' => 'Matematika', 'day' => 1, 'period_start' => 1, 'period_count' => 2]));
        $this->tenant()->forget();
    }

    private function api(string $p): string
    {
        return $this->at($this->s['school'], 'api/cbt/'.$p);
    }

    private function guru()
    {
        return $this->actingAs($this->s['teacher']->user);
    }

    private function admin()
    {
        return $this->actingAs($this->s['admin']);
    }

    private function siswa()
    {
        return $this->fromDevice($this->s['deviceToken'])->actingAs($this->s['student']->user);
    }

    private function acceptedPackage(): int
    {
        $id = $this->guru()->postJson($this->api('packages'), ['name' => 'PTS Matematika X', 'subject' => 'Matematika', 'grade' => 'X'])->assertCreated()->json('id');
        $this->guru()->putJson($this->api("packages/$id"), ['questions' => [
            ['type' => 'pg', 'body' => '2 + 3 = …', 'options' => [['text' => '4'], ['text' => '5'], ['text' => '6']], 'answer' => 1, 'score' => 1],
            ['type' => 'pg', 'body' => '3 × 3 = …', 'options' => [['text' => '6'], ['text' => '9']], 'answer' => 1, 'score' => 1],
            ['type' => 'essay', 'body' => 'Jelaskan langkahmu.', 'guide' => 'Langkah benar', 'score' => 2],
        ]])->assertOk()->assertJsonPath('problems', []);
        $this->guru()->postJson($this->api("packages/$id/submit"))->assertOk();
        $this->admin()->postJson($this->api("packages/$id/review"), ['action' => 'terima'])->assertOk();

        return $id;
    }

    public function test_guru_tidak_bisa_kirim_paket_belum_lengkap_dan_admin_wajib_catatan_saat_mengembalikan(): void
    {
        $id = $this->guru()->postJson($this->api('packages'), ['name' => 'Draf', 'subject' => 'Matematika'])->assertCreated()->json('id');
        $this->guru()->postJson($this->api('packages'), ['name' => 'Lain', 'subject' => 'Biologi'])->assertStatus(422); // bukan mapel yang diajar
        $this->guru()->putJson($this->api("packages/$id"), ['questions' => [['type' => 'pg', 'body' => 'Soal', 'options' => [['text' => 'a'], ['text' => '']]]]])
            ->assertOk()->assertJsonCount(1, 'problems');
        $this->guru()->postJson($this->api("packages/$id/submit"))->assertStatus(422);
        $this->admin()->getJson($this->api("packages/$id"))->assertForbidden(); // draf belum terlihat admin

        $this->guru()->putJson($this->api("packages/$id"), ['questions' => [['type' => 'pg', 'body' => 'Soal', 'options' => [['text' => 'a'], ['text' => 'b']], 'answer' => 0, 'score' => 1]]])->assertOk();
        $this->guru()->postJson($this->api("packages/$id/submit"))->assertOk();
        $this->guru()->putJson($this->api("packages/$id"), ['questions' => []])->assertStatus(422); // terkunci setelah dikirim
        $this->admin()->postJson($this->api("packages/$id/review"), ['action' => 'kembalikan'])->assertStatus(422);
        $this->admin()->postJson($this->api("packages/$id/review"), ['action' => 'kembalikan', 'note' => 'Perbaiki no. 1'])->assertOk();
        $this->guru()->getJson($this->api('packages'))->assertJsonPath('0.status', 'dikembalikan')->assertJsonPath('0.note', 'Perbaiki no. 1');
    }

    public function test_gambar_soal_dikompres_dan_jalur_asing_ditolak(): void
    {
        $img = $this->guru()->post($this->api('images'), ['file' => UploadedFile::fake()->image('rumus.jpg', 800, 300)], ['Accept' => 'application/json'])->assertCreated();
        $path = $img->json('path');
        $this->assertStringEndsWith('.webp', $path);
        $id = $this->guru()->postJson($this->api('packages'), ['name' => 'Gambar', 'subject' => 'Matematika'])->json('id');
        $this->guru()->putJson($this->api("packages/$id"), ['questions' => [
            ['type' => 'pg', 'body' => '', 'image' => $path, 'options' => [['text' => '', 'image' => 'school_999/cbt/x.webp'], ['text' => 'b']], 'answer' => 1],
        ]])->assertOk();
        $q = $this->guru()->getJson($this->api("packages/$id"))->json('questions.0');
        $this->assertSame($path, $q['image']);
        $this->assertNull($q['options'][0]['image']); // milik sekolah lain → dibuang
    }

    public function test_alur_ujian_lengkap_dengan_token_per_kelas(): void
    {
        $this->acceptedPackage();
        $bank = $this->admin()->getJson($this->api('bank?subject=Matematika'))->assertOk()->json('questions');
        $this->assertCount(3, $bank);
        $other = $this->tenant()->run($this->s['school'], fn () => Classroom::create(['name' => 'X-FKK 2']));
        $this->tenant()->forget();

        $now = now()->setTimezone('Asia/Jakarta');
        $payload = ['title' => 'PTS Matematika X', 'subject' => 'Matematika', 'code' => 'mtk-x-01', 'date' => $now->format('Y-m-d'),
            'start' => $now->copy()->subMinutes(5)->format('H:i'), 'end' => $now->copy()->addMinutes(90)->format('H:i'), 'duration' => 60, 'exit_action' => 'catat',
            'questions' => array_column($bank, 'id'), 'classes' => [['classroom_id' => $this->s['classroom']->id, 'grader_id' => $this->s['teacher']->id], ['classroom_id' => $other->id]]];
        if ($now->format('H:i') < '00:10' || $now->format('H:i') > '22:20') {
            $this->markTestSkipped('Jadwal melewati tengah malam.');
        }
        $exam = $this->admin()->postJson($this->api('exams'), $payload)->assertCreated()->json('id');
        $this->admin()->postJson($this->api("exams/$exam/publish"))->assertStatus(422); // kelas X-FKK 2 tanpa penilai essay
        $payload['classes'][1]['grader_id'] = $this->s['teacher']->id;
        $this->admin()->putJson($this->api("exams/$exam"), $payload)->assertOk();
        $this->admin()->postJson($this->api("exams/$exam/publish"))->assertOk();

        $classes = collect($this->admin()->getJson($this->api("exams/$exam"))->json('classes'))->keyBy('classroom_id');
        $mine = $classes[$this->s['classroom']->id]['token'];
        $theirs = $classes[$other->id]['token'];
        $this->assertMatchesRegularExpression('/^[A-Z2-9]{6}$/', $mine);
        $this->assertNotSame($mine, $theirs);
        $this->assertNotEquals($mine, $this->tenant()->run($this->s['school'], fn () => \DB::table('cbt_exam_classes')->where('classroom_id', $this->s['classroom']->id)->value('token')));
        $this->tenant()->forget();

        // Guru tidak bisa melihat ujian/token; kartu token hanya admin.
        $this->guru()->getJson($this->api("exams/$exam"))->assertForbidden();
        $this->admin()->get($this->at($this->s['school'], "cbt/kartu-token/$exam"))->assertOk()->assertSee($mine)->assertSee($theirs);

        // Token kelas lain ditolak; token kelas sendiri diterima.
        $this->siswa()->getJson($this->api('my-exams'))->assertOk()->assertJsonPath('0.phase', 'berlangsung');
        $this->siswa()->postJson($this->api("my-exams/$exam/start"), ['token' => $theirs])->assertStatus(422);
        $att = $this->siswa()->postJson($this->api("my-exams/$exam/start"), ['token' => strtolower($mine)])->assertCreated()->json('attempt_id');
        $this->siswa()->postJson($this->api("my-exams/$exam/start"))->assertOk()->assertJsonPath('resumed', true); // lanjut tanpa token

        $A = $this->siswa()->getJson($this->api("attempts/$att"))->assertOk();
        $qs = collect($A->json('questions'));
        $this->assertCount(3, $qs);
        $this->assertArrayNotHasKey('answer', $qs[0]);
        $this->assertStringNotContainsString('Langkah benar', $A->getContent()); // pedoman essay tidak ke HP siswa

        $pgs = $qs->where('type', 'pg')->values();
        $first = $pgs->firstWhere('body', '2 + 3 = …');
        $second = $pgs->firstWhere('body', '3 × 3 = …');
        $essay = $qs->firstWhere('type', 'essay');
        $this->siswa()->putJson($this->api("attempts/$att/answer"), ['question_id' => $first['id'], 'choice' => 1, 'rev' => 2])->assertJsonPath('saved', true);
        $this->siswa()->putJson($this->api("attempts/$att/answer"), ['question_id' => $first['id'], 'choice' => 0, 'rev' => 1])->assertJsonPath('saved', false); // kiriman lama
        $this->siswa()->putJson($this->api("attempts/$att/answer"), ['question_id' => $second['id'], 'choice' => 0, 'rev' => 1])->assertOk();
        $this->siswa()->putJson($this->api("attempts/$att/answer"), ['question_id' => $essay['id'], 'text' => '2 tambah 3', 'rev' => 1])->assertOk();
        $this->siswa()->postJson($this->api("attempts/$att/event"), ['type' => 'keluar'])->assertJsonPath('exits', 1);

        $mon = $this->admin()->getJson($this->api("exams/$exam/monitor"))->assertOk();
        $this->assertSame('mengerjakan', collect($mon->json('rows'))->firstWhere('student_id', $this->s['student']->id)['status']);

        $r = $this->siswa()->postJson($this->api("attempts/$att/finish"))->assertOk();
        $r->assertJsonPath('result.pg_percent', 50)->assertJsonPath('result.essay_pending', true)->assertJsonPath('result.correct', 1);

        // Essay dinilai guru penilai → nilai akhir (1 + 2) / 4 = 75
        $grading = $this->guru()->getJson($this->api('grading'))->assertOk()->json();
        $cls = collect($grading)->firstWhere('class', 'X-FKK 1');
        $this->assertSame(1, $cls['pending']);
        $answer = $this->guru()->getJson($this->api('grading/'.$cls['id']))->assertOk()->json('students.0.answers.0.id');
        $this->guru()->putJson($this->api("grading/answers/$answer"), ['score' => 3])->assertStatus(422); // melebihi bobot
        $this->guru()->putJson($this->api("grading/answers/$answer"), ['score' => 2])->assertOk()->assertJsonPath('final', 75);
        $this->siswa()->getJson($this->api('my-exams'))->assertJsonPath('0.attempt.final_score', 75);

        $res = $this->admin()->getJson($this->api("exams/$exam/results"))->assertOk();
        $res->assertJsonPath('finished', 1)->assertJsonPath('rows.0.final', 75);
        $this->admin()->get($this->api("exams/$exam/export"))->assertOk();
        $this->admin()->getJson($this->api('students/'.$this->s['student']->id))->assertOk()->assertJsonPath('attempts.0.final', 75);
    }

    public function test_ulangan_harian_guru_dari_paket_sendiri_dan_nilai_masuk_kolom_s(): void
    {
        // Soal UH ditulis langsung (paket kind=uh): tidak tampil di "Paket soal PTS/PAS", tidak bisa dikirim, tidak terlihat admin.
        $pkg = $this->guru()->postJson($this->api('packages'), ['name' => 'Soal UH 1 Matematika', 'subject' => 'Matematika', 'kind' => 'uh'])->assertCreated()->json('id');
        $this->guru()->putJson($this->api("packages/$pkg"), ['questions' => [
            ['type' => 'pg', 'body' => '7 − 2 = …', 'options' => [['text' => '5'], ['text' => '4']], 'answer' => 0, 'score' => 1],
            ['type' => 'pg', 'body' => '2 × 4 = …', 'options' => [['text' => '6'], ['text' => '8']], 'answer' => 1, 'score' => 1],
        ]])->assertOk();
        $this->guru()->postJson($this->api("packages/$pkg/submit"))->assertStatus(422);
        $this->guru()->getJson($this->api('packages'))->assertJsonCount(0);
        $this->guru()->getJson($this->api('packages?kind=uh'))->assertJsonCount(1);
        $this->admin()->getJson($this->api("packages/$pkg"))->assertForbidden();
        $bank = $this->guru()->getJson($this->api('bank?subject=Matematika'))->assertOk()->json('questions');
        $this->assertCount(2, $bank);
        $this->admin()->getJson($this->api('bank?subject=Matematika'))->assertOk()->assertJsonCount(0, 'questions'); // bank admin tetap hanya paket diterima
        $other = $this->tenant()->run($this->s['school'], fn () => Classroom::create(['name' => 'X-FKK 2'])); // tidak diajar guru ini
        $this->tenant()->forget();

        $now = now()->setTimezone('Asia/Jakarta');
        if ($now->format('H:i') < '00:10' || $now->format('H:i') > '22:20') {
            $this->markTestSkipped('Jadwal melewati tengah malam.');
        }
        $payload = ['title' => 'UH 1 Matematika', 'subject' => 'Matematika', 'code' => 'uh1-mtk', 'date' => $now->format('Y-m-d'),
            'start' => $now->copy()->subMinutes(5)->format('H:i'), 'end' => $now->copy()->addMinutes(60)->format('H:i'), 'duration' => 30,
            'questions' => array_column($bank, 'id'), 'classes' => [['classroom_id' => $this->s['classroom']->id], ['classroom_id' => $other->id]]];
        $this->guru()->postJson($this->api('exams'), $payload)->assertStatus(422); // kolom S wajib
        $exam = $this->guru()->postJson($this->api('exams'), $payload + ['grade_lm' => 2, 'bank_id' => $pkg])->assertCreated()->json('id');
        $E = $this->guru()->getJson($this->api("exams/$exam"))->assertOk()->assertJsonPath('kind', 'uh')->assertJsonPath('grade_lm', 2)->assertJsonPath('bank_id', $pkg)->json();
        $this->assertCount(1, $E['classes']); // kelas yang tidak diajar dibuang
        $this->assertSame($this->s['teacher']->id, $E['classes'][0]['grader_id']);
        $this->guru()->getJson($this->api('exams'))->assertJsonCount(1);
        // Admin tidak memantau UH sama sekali.
        $this->admin()->getJson($this->api('exams'))->assertJsonCount(0);
        $this->admin()->getJson($this->api("exams/$exam"))->assertForbidden();
        $this->admin()->putJson($this->api("exams/$exam"), $payload)->assertForbidden();

        $this->guru()->postJson($this->api("exams/$exam/publish"))->assertOk();
        $token = $this->guru()->getJson($this->api("exams/$exam"))->json('classes.0.token');
        $this->guru()->get($this->at($this->s['school'], "cbt/kartu-token/$exam"))->assertOk()->assertSee($token);

        // Guru lain tidak bisa membuka UH ini.
        $lain = $this->tenant()->run($this->s['school'], function () {
            $u = \App\Models\User::factory()->create(['role' => \App\Enums\Role::Teacher, 'username' => '3301010102', 'password' => 'rahasia123']);

            // Relasi user dimuat di dalam konteks sekolah: di luar tenant, SchoolScope (gagal tertutup) mengembalikan null.
            return \App\Models\Teacher::create(['user_id' => $u->id, 'nik' => '3301010102', 'name' => 'Guru Lain'])->load('user');
        });
        $this->tenant()->forget();
        $this->actingAs($lain->user)->getJson($this->at($this->s['school'], "api/cbt/exams/$exam/monitor"))->assertForbidden();

        $this->siswa()->getJson($this->api('my-exams'))->assertJsonPath('0.kind_label', 'Ulangan Harian');
        $att = $this->siswa()->postJson($this->api("my-exams/$exam/start"), ['token' => $token])->assertCreated()->json('attempt_id');
        $qs = collect($this->siswa()->getJson($this->api("attempts/$att"))->json('questions'));
        $q1 = $qs->firstWhere('body', '7 − 2 = …');
        $this->siswa()->putJson($this->api("attempts/$att/answer"), ['question_id' => $q1['id'], 'choice' => 0, 'rev' => 1])->assertOk();
        $this->siswa()->postJson($this->api("attempts/$att/finish"))->assertOk()->assertJsonPath('result.final_score', 50);
        $this->guru()->getJson($this->api("exams/$exam/monitor"))->assertOk();
        $this->guru()->getJson($this->api("exams/$exam/results"))->assertOk()->assertJsonPath('rows.0.final', 50);
        $this->admin()->getJson($this->api("exams/$exam/results"))->assertForbidden();
        $this->admin()->getJson($this->api('students/'.$this->s['student']->id))->assertOk()->assertJsonCount(0, 'attempts');

        // Nilai akhir tertulis di kolom S2 Daftar Nilai guru (buku dibuat otomatis) dan tetap bisa diubah guru.
        $this->tenant()->run($this->s['school'], function () {
            $book = GradeBook::query()->where('teacher_id', $this->s['teacher']->id)->where('classroom_id', $this->s['classroom']->id)->where('subject', 'Matematika')->firstOrFail();
            $lm = GradeEntry::query()->where('grade_book_id', $book->id)->where('student_id', $this->s['student']->id)->value('lm');
            $this->assertNull($lm[0]);
            $this->assertEquals(50, $lm[1]);
        });
        $this->tenant()->forget();
    }

    public function test_waktu_habis_otomatis_terkirim_dan_reset_admin(): void
    {
        $this->acceptedPackage();
        $bank = $this->admin()->getJson($this->api('bank'))->json('questions');
        $now = now()->setTimezone('Asia/Jakarta');
        if ($now->format('H:i') < '00:10' || $now->format('H:i') > '22:20') {
            $this->markTestSkipped('Jadwal melewati tengah malam.');
        }
        $exam = $this->admin()->postJson($this->api('exams'), ['title' => 'UH', 'subject' => 'Matematika', 'code' => 'UH-1', 'date' => $now->format('Y-m-d'),
            'start' => $now->copy()->subMinutes(5)->format('H:i'), 'end' => $now->copy()->addMinutes(60)->format('H:i'), 'duration' => 10,
            'questions' => array_column($bank, 'id'), 'classes' => [['classroom_id' => $this->s['classroom']->id, 'grader_id' => $this->s['teacher']->id]]])->json('id');
        $this->admin()->postJson($this->api("exams/$exam/publish"))->assertOk();
        $token = $this->tenant()->run($this->s['school'], fn () => CbtExamClass::query()->where('cbt_exam_id', $exam)->first()->token);
        $this->tenant()->forget();
        $att = $this->siswa()->postJson($this->api("my-exams/$exam/start"), ['token' => $token])->json('attempt_id');

        $this->travel(11)->minutes();
        $this->siswa()->getJson($this->api("attempts/$att"))->assertOk()->assertJsonPath('status', 'selesai')->assertJsonPath('result.finish_reason', 'waktu');

        $this->admin()->postJson($this->api("attempts/$att/action"), ['action' => 'reset'])->assertOk();
        $this->assertSame(0, $this->tenant()->run($this->s['school'], fn () => CbtAttempt::query()->count()));
    }

    public function test_kunci_ujian_saat_keluar_aplikasi(): void
    {
        $this->acceptedPackage();
        $bank = $this->admin()->getJson($this->api('bank'))->json('questions');
        $now = now()->setTimezone('Asia/Jakarta');
        if ($now->format('H:i') < '00:10' || $now->format('H:i') > '22:20') {
            $this->markTestSkipped('Jadwal melewati tengah malam.');
        }
        $mk = function (string $code, string $action, int $allow) use ($bank, $now) {
            $id = $this->admin()->postJson($this->api('exams'), ['title' => 'UH '.$code, 'subject' => 'Matematika', 'code' => $code, 'date' => $now->format('Y-m-d'),
                'start' => $now->copy()->subMinutes(5)->format('H:i'), 'end' => $now->copy()->addMinutes(60)->format('H:i'), 'duration' => 30,
                'exit_action' => $action, 'exit_allow' => $allow,
                'questions' => array_column($bank, 'id'), 'classes' => [['classroom_id' => $this->s['classroom']->id, 'grader_id' => $this->s['teacher']->id]]])->assertCreated()->json('id');
            $this->admin()->postJson($this->api("exams/$id/publish"))->assertOk();
            $token = $this->tenant()->run($this->s['school'], fn () => CbtExamClass::query()->where('cbt_exam_id', $id)->first()->token);
            $this->tenant()->forget();

            return [$id, $this->siswa()->postJson($this->api("my-exams/$id/start"), ['token' => $token])->json('attempt_id')];
        };

        // Ulang dari awal (toleransi 1): keluar pertama dimaafkan, keluar kedua menghapus jawaban.
        [, $att] = $mk('UH-ULANG', 'ulang', 1);
        $q = $this->siswa()->getJson($this->api("attempts/$att"))->assertJsonPath('exam.exit_action', 'ulang')->json('questions.0');
        $this->siswa()->putJson($this->api("attempts/$att/answer"), ['question_id' => $q['id'], 'choice' => 0, 'rev' => 1])->assertOk();
        $this->siswa()->postJson($this->api("attempts/$att/event"), ['type' => 'keluar', 'seconds' => 5])->assertJsonPath('action', null);
        $this->siswa()->postJson($this->api("attempts/$att/event"), ['type' => 'keluar', 'seconds' => 5])->assertJsonPath('action', 'ulang')->assertJsonPath('restarts', 1);
        $this->assertSame(0, $this->tenant()->run($this->s['school'], fn () => CbtAnswer::query()->where('cbt_attempt_id', $att)->count()));
        $this->tenant()->forget();
        $this->siswa()->getJson($this->api("attempts/$att"))->assertJsonPath('status', 'mengerjakan')->assertJsonPath('restarts', 1);

        // Kunci: soal disembunyikan, jawaban & kirim ditolak sampai pengawas membuka.
        [$exam2, $att2] = $mk('UH-KUNCI', 'kunci', 0);
        $q2 = $this->siswa()->getJson($this->api("attempts/$att2"))->json('questions.0');
        $this->siswa()->postJson($this->api("attempts/$att2/event"), ['type' => 'keluar', 'seconds' => 3])->assertJsonPath('action', 'kunci')->assertJsonPath('locked', true);
        $this->siswa()->getJson($this->api("attempts/$att2"))->assertJsonPath('locked', true)->assertJsonMissingPath('questions');
        $this->siswa()->putJson($this->api("attempts/$att2/answer"), ['question_id' => $q2['id'], 'choice' => 0, 'rev' => 1])->assertStatus(423);
        $this->siswa()->postJson($this->api("attempts/$att2/finish"))->assertStatus(423);
        $this->admin()->getJson($this->api("exams/$exam2/monitor"))->assertJsonPath('count.locked', 1);
        $this->admin()->postJson($this->api("attempts/$att2/action"), ['action' => 'unlock'])->assertOk();
        $this->siswa()->getJson($this->api("attempts/$att2"))->assertJsonPath('locked', false)->assertJsonCount(3, 'questions');
        $this->siswa()->putJson($this->api("attempts/$att2/answer"), ['question_id' => $q2['id'], 'choice' => 0, 'rev' => 1])->assertOk();
    }

    public function test_impor_excel_ke_paket_guru(): void
    {
        $id = $this->guru()->postJson($this->api('packages'), ['name' => 'Impor', 'subject' => 'Matematika'])->json('id');

        // Template resmi masih kosong (sheet "Soal" hanya nomor; sheet "Contoh" dilewati) → ditolak dengan petunjuk.
        $res = $this->guru()->get($this->api('format'))->assertOk();
        $blank = new UploadedFile($res->baseResponse->getFile()->getPathname(), 'FORMAT_CBT.xlsx', null, null, true);
        $this->guru()->post($this->api("packages/$id/import"), ['file' => $blank], ['Accept' => 'application/json'])
            ->assertStatus(422)->assertJsonValidationErrors('file');

        // Template yang sudah diisi guru (tata letak sama: 2 baris judul, lalu kolom) → soal masuk; sheet Contoh tetap dilewati.
        $head = ['No', 'Jenis', 'Pertanyaan', 'Opsi A', 'Opsi B', 'Opsi C', 'Opsi D', 'Opsi E', 'Kunci', 'Skor', 'Pedoman Penilaian'];
        $filled = $this->xlsx([
            'Soal' => [['FORMAT IMPOR SOAL CBT'], ['Isi mulai baris 5.'], $head,
                [1, 'PG', 'Obat penurun panas disebut', 'Antibiotik', 'Antipiretik', 'Analgesik', '', '', 'B', 1, ''],
                [2, 'ESSAY', 'Jelaskan cara kerja antipiretik', '', '', '', '', '', '', 5, 'Skor 5: menyebut pusat termoregulasi'],
                [3, '', '', '', '', '', '', '', '', '', '']],
            'Contoh' => [['CONTOH'], [''], $head, [1, 'PG', 'Ibu kota negara Indonesia', 'Bandung', 'Jakarta', '', '', '', 'B', 1, '']],
        ]);
        $this->guru()->post($this->api("packages/$id/import"), ['file' => $filled], ['Accept' => 'application/json'])->assertOk()->assertJsonPath('message', '2 soal ditambahkan dari Excel.');
        $q = $this->guru()->getJson($this->api("packages/$id"))->json('questions');
        $this->assertSame('Antipiretik', $q[0]['options'][$q[0]['answer']]['text']);
        $this->assertSame('essay', $q[1]['type']);
    }

    private function xlsx(array $sheets): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'cbt').'.xlsx';
        $w = new \OpenSpout\Writer\XLSX\Writer;
        $w->openToFile($path);
        $first = true;
        foreach ($sheets as $title => $rows) {
            $sheet = $first ? $w->getCurrentSheet() : $w->addNewSheetAndMakeItCurrent();
            $sheet->setName($title);
            $first = false;
            foreach ($rows as $r) {
                $w->addRow(\OpenSpout\Common\Entity\Row::fromValues($r));
            }
        }
        $w->close();

        return new UploadedFile($path, 'soal.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
    }
}
