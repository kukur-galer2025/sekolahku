<?php

namespace App\Services\Cbt;

use App\Enums\Role;
use App\Models\CbtAnswer;
use App\Models\CbtAttempt;
use App\Models\CbtBank;
use App\Models\CbtExam;
use App\Models\CbtExamClass;
use App\Models\CbtExamLog;
use App\Models\CbtExamQuestion;
use App\Models\CbtQuestion;
use App\Models\Classroom;
use App\Models\GradeBook;
use App\Models\GradeEntry;
use App\Models\Schedule;
use App\Models\Student;
use App\Models\Teacher;
use App\Models\User;
use App\Services\ImageSanitizer;
use App\Services\LegerService;
use App\Services\Notify\Notifier;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantFiles;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Logika CBT: paket soal guru → bank soal admin → ujian (token per kelas) → pengerjaan siswa → nilai.
 * Kunci jawaban & pedoman essay tidak pernah dikirim ke HP siswa; penilaian di server.
 */
class CbtService
{
    public const IMAGE_MAX = 2 * 1024 * 1024;

    public const MAX_QUESTIONS = 200;

    public function __construct(private TenantFiles $files, private ImageSanitizer $images, private Notifier $notifier) {}

    // ── Gambar ───────────────────────────────────────────────────────

    /** Gambar soal/opsi: JPG/PNG maks. 2 MB → WebP terkompres (EXIF terbuang). */
    public function storeImage(UploadedFile $file): string
    {
        $data = $this->images->webp($file, 1400, false, self::IMAGE_MAX);

        return $this->files->put('cbt/img/'.Str::random(20).'.webp', $data);
    }

    /** Jalur gambar kiriman browser hanya diterima bila milik sekolah ini & berada di folder CBT. */
    public function cleanImage(mixed $path): ?string
    {
        if (! is_string($path) || $path === '') {
            return null;
        }

        return $this->files->owns($path) && str_starts_with($path, $this->files->prefix().'cbt/') ? $path : null;
    }

    public function url(?string $path, int $minutes = 120): ?string
    {
        return $path ? $this->files->url($path, $minutes) : null;
    }

    // ── Guru & jadwal ───────────────────────────────────────────────

    /** Mapel yang diajar guru (Jadwal KBM) + mapel di data guru. */
    public function teacherSubjects(Teacher $t): array
    {
        return Schedule::query()->where('teacher_id', $t->id)->distinct()->pluck('subject')
            ->push($t->subject)->filter()->map(fn ($s) => trim($s))->unique()->sort()->values()->all();
    }

    /** Peta mapel → kelas → [id guru] dari Jadwal KBM (untuk mengisi otomatis guru penilai essay). */
    public function pengampu(): array
    {
        $map = [];
        foreach (Schedule::query()->get(['subject', 'classroom_id', 'teacher_id']) as $s) {
            if ($s->teacher_id && $s->classroom_id) {
                $map[mb_strtolower(trim($s->subject))][$s->classroom_id][$s->teacher_id] = true;
            }
        }

        return array_map(fn ($cls) => array_map(fn ($t) => array_map('intval', array_keys($t)), $cls), $map);
    }

    public function gradersFor(string $subject, int $classroomId): array
    {
        return $this->pengampu()[mb_strtolower(trim($subject))][$classroomId] ?? [];
    }

    // ── Paket soal ──────────────────────────────────────────────────

    public function presentQuestion(CbtQuestion|CbtExamQuestion $q, bool $withKey = true): array
    {
        return [
            'id' => $q->id, 'number' => $q->number, 'type' => $q->type ?: 'pg', 'body' => $q->body,
            'image' => $q->image_path, 'image_url' => $this->url($q->image_path),
            'options' => collect($q->options ?? [])->map(fn ($o) => is_array($o)
                ? ['text' => (string) ($o['text'] ?? ''), 'image' => $o['image'] ?? null, 'image_url' => $this->url($o['image'] ?? null)]
                : ['text' => (string) $o, 'image' => null, 'image_url' => null])->values()->all(),
            'answer' => $withKey ? $q->answer : null, 'score' => (float) $q->score, 'guide' => $withKey ? $q->essay_guide : null,
        ];
    }

    /**
     * Simpan isi paket (seluruh daftar soal sekaligus, dipakai juga untuk simpan otomatis).
     * Soal belum lengkap tetap boleh disimpan sebagai draf; kelengkapan dicek saat dikirim.
     */
    public function savePackage(CbtBank $bank, array $questions): void
    {
        if (count($questions) > self::MAX_QUESTIONS) {
            throw ValidationException::withMessages(['questions' => 'Satu paket maksimal '.self::MAX_QUESTIONS.' soal.']);
        }
        DB::transaction(function () use ($bank, $questions) {
            $keep = [];
            foreach (array_values($questions) as $i => $q) {
                $type = ($q['type'] ?? 'pg') === 'essay' ? 'essay' : 'pg';
                $opts = $type === 'pg' ? collect($q['options'] ?? [])->take(5)->map(fn ($o) => [
                    'text' => mb_substr(trim((string) ($o['text'] ?? '')), 0, 1000), 'image' => $this->cleanImage($o['image'] ?? null),
                ])->values()->all() : [];
                $answer = $type === 'pg' && isset($q['answer']) && is_numeric($q['answer']) && (int) $q['answer'] < count($opts) && (int) $q['answer'] >= 0 ? (int) $q['answer'] : null;
                $row = [
                    'number' => $i + 1, 'type' => $type, 'body' => mb_substr((string) ($q['body'] ?? ''), 0, 5000),
                    'image_path' => $this->cleanImage($q['image'] ?? null), 'options' => $opts, 'answer' => $answer,
                    'score' => max(0, min(100, (float) ($q['score'] ?? ($type === 'essay' ? 5 : 1)))),
                    'essay_guide' => $type === 'essay' ? mb_substr((string) ($q['guide'] ?? ''), 0, 3000) : null,
                ];
                $existing = isset($q['id']) ? $bank->questions()->whereKey((int) $q['id'])->first() : null;
                $existing ? $existing->update($row) : $existing = $bank->questions()->create($row);
                $keep[] = $existing->id;
            }
            $bank->questions()->whereNotIn('id', $keep ?: [0])->delete();
            $bank->update(['question_count' => count($keep)]);
        });
    }

    /** @return list<string> masalah per soal, mis. "No. 3: kunci belum dipilih" */
    public function problems(CbtBank $bank): array
    {
        $out = [];
        foreach ($bank->questions()->get() as $q) {
            $p = [];
            if (trim($q->body) === '' && ! $q->image_path) {
                $p[] = 'teks atau gambar soal kosong';
            }
            if ($q->type === 'pg') {
                $opts = $q->options ?? [];
                if (count($opts) < 2) {
                    $p[] = 'minimal 2 pilihan';
                }
                if (collect($opts)->contains(fn ($o) => trim((string) ($o['text'] ?? '')) === '' && empty($o['image']))) {
                    $p[] = 'ada pilihan kosong';
                }
                if ($q->answer === null) {
                    $p[] = 'kunci belum dipilih';
                }
            }
            if ($q->score <= 0) {
                $p[] = 'bobot belum diisi';
            }
            if ($p) {
                $out[] = 'No. '.$q->number.': '.implode(', ', $p);
            }
        }

        return $out;
    }

    // ── Ujian ───────────────────────────────────────────────────────

    /** Terbitkan: soal dibekukan (gambar disalin ke folder ujian), token per kelas dibuat, siswa diberi tahu (tanpa token). */
    public function publish(CbtExam $exam, User $by): void
    {
        $ids = array_map('intval', $exam->draft['questions'] ?? []);
        $source = CbtQuestion::query()->whereIn('id', $ids)
            ->whereHas('bank', fn ($b) => $b->where('subject', $exam->subject)
                ->when($exam->isUh(), fn ($x) => $x->where('teacher_id', $exam->teacher_id), fn ($x) => $x->where('status', 'diterima')))->get()->keyBy('id');
        $classes = $exam->classes()->get();
        $errors = [];
        if (! $source->count()) {
            $errors[] = $exam->isUh() ? 'Pilih minimal 1 soal dari paket soal '.$exam->subject.' Anda.' : 'Pilih minimal 1 soal dari bank soal '.$exam->subject.'.';
        }
        if ($classes->isEmpty()) {
            $errors[] = 'Pilih minimal 1 kelas peserta.';
        }
        if ($source->contains('type', 'essay') && $classes->contains(fn ($c) => ! $c->essay_grader_id)) {
            $errors[] = 'Ada kelas tanpa guru penilai essay.';
        }
        if ($exam->ends_at->lte(now())) {
            $errors[] = 'Jadwal selesai sudah lewat.';
        }
        if ($errors) {
            throw ValidationException::withMessages(['exam' => implode(' ', $errors)]);
        }

        DB::transaction(function () use ($exam, $ids, $source, $classes, $by) {
            $n = 0;
            foreach ($ids as $id) {
                $q = $source->get($id);
                if (! $q) {
                    continue;
                }
                $n++;
                $exam->questions()->create([
                    'source_question_id' => $q->id, 'number' => $n, 'type' => $q->type, 'body' => $q->body,
                    'image_path' => $this->copyImage($q->image_path, $exam),
                    'options' => collect($q->options ?? [])->map(fn ($o) => ['text' => (string) ($o['text'] ?? ''), 'image' => $this->copyImage($o['image'] ?? null, $exam)])->all(),
                    'answer' => $q->answer, 'score' => $q->score, 'essay_guide' => $q->essay_guide,
                ]);
            }
            foreach ($classes as $c) {
                if (! $c->token) {
                    $c->update(['token' => CbtExamClass::newToken(), 'token_at' => now()]);
                }
            }
            $exam->update(['status' => 'terbit', 'published_at' => now()]);
            $this->log($exam, 'terbit', $n.' soal, '.$classes->count().' kelas', null, $by);
        });

        $users = User::query()->whereIn('id', Student::query()->whereIn('classroom_id', $classes->pluck('classroom_id'))->where('is_active', true)->pluck('user_id'))->get();
        $when = $exam->starts_at->setTimezone($this->tz())->locale('id')->translatedFormat('l, j F · H.i');
        $this->notifier->send($users, 'cbt', $exam->kindLabel().' '.$exam->subject.' dijadwalkan', $exam->title.' — '.$when.'. Token diumumkan pengawas di kelas.', '#/cbt');
    }

    private function copyImage(?string $path, CbtExam $exam): ?string
    {
        if (! $path || ! $this->files->owns($path) || ! $this->files->disk()->exists($path)) {
            return null;
        }
        $dest = $this->files->path('cbt/exam_'.$exam->id.'/'.Str::random(16).'.webp');
        $this->files->disk()->copy($path, $dest);

        return $dest;
    }

    public function tz(): string
    {
        return app(\App\Tenancy\TenantContext::class)->get()?->timezone ?: 'Asia/Jakarta';
    }

    public function log(CbtExam $exam, string $event, ?string $detail = null, ?int $studentId = null, ?User $by = null): void
    {
        CbtExamLog::create(['cbt_exam_id' => $exam->id, 'student_id' => $studentId, 'user_id' => $by?->id, 'event' => $event, 'detail' => $detail ? mb_substr($detail, 0, 300) : null]);
    }

    // ── Pengerjaan ──────────────────────────────────────────────────

    public function start(CbtExam $exam, Student $student, CbtExamClass $class, ?string $ip): CbtAttempt
    {
        $qs = $exam->questions()->get(['id', 'type', 'options']);
        $order = $qs->pluck('id')->all();
        if ($exam->shuffle_questions) {
            shuffle($order);
        }
        $opt = [];
        foreach ($qs as $q) {
            if ($q->type === 'pg') {
                $o = array_keys($q->options ?? []);
                if ($exam->shuffle_options) {
                    shuffle($o);
                }
                $opt[$q->id] = $o;
            }
        }
        $now = now();
        $deadline = $now->copy()->addMinutes($exam->duration_minutes);
        if ($deadline->gt($exam->ends_at)) {
            $deadline = $exam->ends_at->copy(); // tidak melewati jadwal selesai
        }

        return CbtAttempt::create([
            'cbt_exam_id' => $exam->id, 'student_id' => $student->id, 'classroom_id' => $class->classroom_id, 'status' => 'mengerjakan',
            'started_at' => $now, 'deadline_at' => $deadline, 'question_order' => $order, 'option_orders' => $opt,
            'pg_max' => (float) $exam->questions()->where('type', 'pg')->sum('score'),
            'essay_max' => (float) $exam->questions()->where('type', 'essay')->sum('score'), 'last_seen_at' => $now, 'ip' => $ip,
        ]);
    }

    /** Sesi yang melewati batas waktu otomatis dikirim (dipanggil setiap kali sesi dibaca). */
    public function expireIfDue(CbtAttempt $a): CbtAttempt
    {
        if ($a->status === 'mengerjakan' && now()->gte($a->deadline_at)) {
            $this->finish($a, 'waktu');
        }

        return $a;
    }

    public function expireExam(CbtExam $exam): void
    {
        $exam->attempts()->where('status', 'mengerjakan')->where('deadline_at', '<=', now())->get()->each(fn ($a) => $this->finish($a, 'waktu'));
    }

    /** Kirim jawaban: nilai PG langsung dihitung; essay kosong otomatis 0; essay terisi menunggu guru penilai. */
    public function finish(CbtAttempt $a, string $reason, ?User $by = null): void
    {
        DB::transaction(function () use ($a, $reason, $by) {
            $a = CbtAttempt::query()->lockForUpdate()->find($a->id);
            if ($a->status !== 'mengerjakan') {
                return;
            }
            $qs = CbtExamQuestion::query()->where('cbt_exam_id', $a->cbt_exam_id)->get()->keyBy('id');
            $ans = $a->answers()->get()->keyBy('cbt_exam_question_id');
            [$pg, $right, $wrong, $blank, $answered] = [0.0, 0, 0, 0, 0];
            foreach ($qs as $q) {
                $x = $ans->get($q->id);
                if ($q->type === 'pg') {
                    if (! $x || $x->choice === null) {
                        $blank++;
                        $x?->update(['is_correct' => null, 'score' => 0]);

                        continue;
                    }
                    $answered++;
                    $ok = $q->answer !== null && $x->choice === $q->answer;
                    $ok ? $right++ : $wrong++;
                    $pg += $ok ? $q->score : 0;
                    $x->update(['is_correct' => $ok, 'score' => $ok ? $q->score : 0]);
                } else {
                    if (! $x || trim((string) $x->text) === '') {
                        $blank++;
                        $x
                            ? $x->update(['score' => 0, 'graded_at' => now(), 'note' => 'Tidak dijawab'])
                            : CbtAnswer::create(['cbt_attempt_id' => $a->id, 'cbt_exam_question_id' => $q->id, 'score' => 0, 'graded_at' => now(), 'note' => 'Tidak dijawab']);
                    } else {
                        $answered++;
                    }
                }
            }
            $a->update(['status' => 'selesai', 'finished_at' => now(), 'finish_reason' => $reason, 'pg_score' => $pg,
                'correct' => $right, 'wrong' => $wrong, 'blank' => $blank, 'answered' => $answered]);
            $this->recompute($a);
            $this->log($a->exam, $reason === 'admin' ? 'paksa_kirim' : 'kirim', $reason === 'waktu' ? 'Waktu habis' : null, $a->student_id, $by);
        });
    }

    /** Nilai akhir (0–100) setelah semua essay terisi dinilai. */
    public function recompute(CbtAttempt $a): void
    {
        $a->refresh();
        if ($a->status !== 'selesai') {
            return;
        }
        $essayIds = CbtExamQuestion::query()->where('cbt_exam_id', $a->cbt_exam_id)->where('type', 'essay')->pluck('id');
        $essay = $a->answers()->whereIn('cbt_exam_question_id', $essayIds)->get();
        $pending = $essayIds->count() > 0 && ($essay->count() < $essayIds->count() || $essay->contains(fn ($x) => $x->graded_at === null));
        $essayScore = $essayIds->count() ? (float) $essay->sum('score') : null;
        $max = $a->pg_max + $a->essay_max;
        $final = $pending ? null : ($max > 0 ? round(($a->pg_score + ($essayScore ?? 0)) / $max * 100, 2) : 0);
        $was = $a->final_score;
        $a->update(['essay_score' => $pending ? null : $essayScore, 'final_score' => $final]);
        if ($final !== null && ($was === null || (float) $was !== (float) $final)) {
            $this->syncGrade($a);
        }
    }

    /**
     * Ulangan Harian → kolom S (lingkup materi) di Daftar Nilai guru pembuatnya, semester berjalan.
     * Buku nilai dibuat bila belum ada; nilai tetap bisa diubah guru di Daftar Nilai.
     */
    public function syncGrade(CbtAttempt $a): void
    {
        $exam = $a->exam;
        if (! $exam?->isUh() || ! $exam->teacher_id || ! $exam->grade_lm || $a->final_score === null || ! $a->classroom_id) {
            return;
        }
        $idx = max(1, min(GradeBook::MAX_LM, (int) $exam->grade_lm));
        $school = app(TenantContext::class)->get() ?? \App\Models\School::query()->find($exam->school_id);
        if (! $school) {
            return;
        }
        $term = app(LegerService::class)->term($school);
        $book = GradeBook::query()->firstOrCreate(
            ['teacher_id' => $exam->teacher_id, 'classroom_id' => $a->classroom_id, 'subject' => $exam->subject, 'term' => $term],
            ['phase' => GradeBook::guessPhase(Classroom::query()->find($a->classroom_id), $school), 'lm_count' => max(5, $idx)],
        );
        if ($book->lm_count < $idx) {
            $book->update(['lm_count' => $idx]);
        }
        $entry = GradeEntry::query()->firstOrNew(['grade_book_id' => $book->id, 'student_id' => $a->student_id]);
        $lm = array_values($entry->lm ?? []);
        $lm = array_pad($lm, $idx, null);
        $lm[$idx - 1] = round((float) $a->final_score, 2);
        $entry->fill(['lm' => $lm, 'updated_by' => $exam->created_by])->save();
        $book->touch();
    }

    /** Daftar soal untuk HP siswa — tanpa kunci & pedoman, sesuai urutan acak sesi. */
    public function studentQuestions(CbtAttempt $a): array
    {
        $qs = CbtExamQuestion::query()->where('cbt_exam_id', $a->cbt_exam_id)->get()->keyBy('id');
        $ans = $a->answers()->get()->keyBy('cbt_exam_question_id');
        $minutes = max(5, (int) ceil(now()->diffInMinutes($a->deadline_at, false)) + 10);
        $out = [];
        foreach ($a->question_order as $id) {
            $q = $qs->get($id);
            if (! $q) {
                continue;
            }
            $x = $ans->get($q->id);
            $opts = [];
            if ($q->type === 'pg') {
                foreach ($a->option_orders[$q->id] ?? array_keys($q->options ?? []) as $i) {
                    $o = $q->options[$i] ?? null;
                    if ($o !== null) {
                        $opts[] = ['i' => $i, 'text' => (string) ($o['text'] ?? ''), 'image_url' => $this->url($o['image'] ?? null, $minutes)];
                    }
                }
            }
            $out[] = ['id' => $q->id, 'type' => $q->type, 'body' => $q->body, 'image_url' => $this->url($q->image_path, $minutes), 'score' => (float) $q->score,
                'options' => $opts, 'choice' => $x?->choice, 'text' => $x?->text, 'flagged' => (bool) $x?->flagged, 'rev' => (int) ($x?->rev ?? 0)];
        }

        return $out;
    }

    public function studentResult(CbtAttempt $a): array
    {
        return ['status' => $a->status, 'pg_percent' => $a->pgPercent(), 'final_score' => $a->final_score, 'essay_pending' => $a->essayPending(),
            'has_essay' => $a->essay_max > 0, 'correct' => $a->correct, 'wrong' => $a->wrong, 'blank' => $a->blank,
            'finished_at' => $a->finished_at?->toIso8601String(), 'finish_reason' => $a->finish_reason];
    }

    /** Guru penilai essay untuk kelas ini diberi tahu ada jawaban yang menunggu (sekali per ujian-kelas saat sesi pertama selesai). */
    public function notifyGrader(CbtAttempt $a): void
    {
        if ($a->essay_max <= 0 || ! $a->essayPending()) {
            return;
        }
        $class = CbtExamClass::query()->where('cbt_exam_id', $a->cbt_exam_id)->where('classroom_id', $a->classroom_id)->first();
        $done = CbtAttempt::query()->where('cbt_exam_id', $a->cbt_exam_id)->where('classroom_id', $a->classroom_id)->where('status', 'selesai')->count();
        if ($class?->grader?->user && $done === 1) {
            $this->notifier->send([$class->grader->user], 'cbt', 'Essay menunggu dinilai', $a->exam->title.' · '.$class->classroom?->name, '#/cbt?t=nilai');
        }
    }

    /** Ringkasan siswa yang ikut sebuah ujian (kelas peserta, siswa aktif). */
    public function participants(CbtExam $exam, ?int $classroomId = null): Collection
    {
        $classIds = $exam->classes()->pluck('classroom_id');
        if ($classroomId) {
            $classIds = $classIds->intersect([$classroomId]);
        }

        return Student::query()->whereIn('classroom_id', $classIds)->where('is_active', true)->orderBy('classroom_id')->orderBy('name')->get(['id', 'name', 'nis', 'classroom_id', 'user_id']);
    }

    public static function isAdmin(User $u): bool
    {
        return $u->role === Role::Admin;
    }
}
