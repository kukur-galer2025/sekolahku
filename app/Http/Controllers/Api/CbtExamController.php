<?php

namespace App\Http\Controllers\Api;

use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Models\CbtAnswer;
use App\Models\CbtAttempt;
use App\Models\CbtExam;
use App\Models\CbtExamClass;
use App\Models\CbtExamLog;
use App\Models\CbtExamQuestion;
use App\Models\CbtQuestion;
use App\Models\Classroom;
use App\Models\GradeBook;
use App\Models\Schedule;
use App\Models\Student;
use App\Models\Teacher;
use App\Services\Cbt\CbtService;
use App\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Writer;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * CBT bagian ujian: susun & terbitkan ujian, token per kelas, daftar pantau, record & nilai.
 * Admin: PTS / PAS (dan ujian lain). Guru: Ulangan Harian miliknya sendiri (soal dari paket sendiri, nilai → kolom S Daftar Nilai).
 */
class CbtExamController extends Controller
{
    public function __construct(private CbtService $cbt, private TenantContext $tenant) {}

    private function local(?\DateTimeInterface $t): ?CarbonImmutable
    {
        return $t ? CarbonImmutable::instance($t)->setTimezone($this->cbt->tz()) : null;
    }

    /** Id guru bila yang masuk guru; null untuk admin. */
    private function teacherId(Request $request): ?int
    {
        $u = $request->user();
        if ($u->role === Role::Admin) {
            return null;
        }
        abort_unless($u->teacher, 403, 'Akun guru belum terhubung ke data guru.');

        return $u->teacher->id;
    }

    /** Guru hanya boleh membuka Ulangan Harian miliknya; admin hanya PTS/PAS (UH sepenuhnya urusan guru). */
    private function guard(Request $request, CbtExam $exam): void
    {
        $tid = $this->teacherId($request);
        abort_if($tid !== null && ! ($exam->isUh() && $exam->teacher_id === $tid), 403, 'Ujian ini dikelola admin / guru lain.');
        abort_if($tid === null && $exam->isUh(), 403, 'Ulangan Harian dikelola guru masing-masing.');
    }

    /** Kelas yang diajar guru untuk mapel tertentu (Jadwal KBM). */
    private function taughtClasses(int $teacherId, string $subject): array
    {
        return Schedule::query()->where('teacher_id', $teacherId)->where('subject', $subject)->whereNotNull('classroom_id')
            ->distinct()->pluck('classroom_id')->map(fn ($x) => (int) $x)->all();
    }

    private function brief(CbtExam $e): array
    {
        $s = $this->local($e->starts_at);

        return [
            'id' => $e->id, 'title' => $e->title, 'kind' => $e->kind ?: 'ujian', 'kind_label' => $e->kindLabel(), 'teacher_id' => $e->teacher_id,
            'grade_lm' => $e->grade_lm, 'subject' => $e->subject, 'grade' => $e->grade, 'code' => $e->code, 'status' => $e->status, 'phase' => $e->phase(),
            'date' => $s->format('Y-m-d'), 'start' => $s->format('H:i'), 'end' => $this->local($e->ends_at)->format('H:i'),
            'starts_at' => $e->starts_at->toIso8601String(), 'ends_at' => $e->ends_at->toIso8601String(), 'duration' => $e->duration_minutes,
            'shuffle_questions' => $e->shuffle_questions, 'shuffle_options' => $e->shuffle_options, 'max_exits' => $e->max_exits,
            'exit_action' => $e->exit_action ?: 'ulang', 'exit_allow' => (int) $e->exit_allow,
        ];
    }

    public function index(Request $request): JsonResponse
    {
        $tid = $this->teacherId($request);
        $exams = CbtExam::query()->with(['classes.classroom:id,name', 'teacher:id,name'])
            ->when($tid !== null, fn ($q) => $q->where('kind', 'uh')->where('teacher_id', $tid))
            ->when($tid === null, fn ($q) => $q->where('kind', '!=', 'uh'))->withCount(['questions', 'attempts', 'attempts as done_count' => fn ($q) => $q->where('status', 'selesai')])
            ->orderByDesc('starts_at')->limit(200)->get();

        return response()->json($exams->map(fn ($e) => $this->brief($e) + [
            'questions' => $e->status === 'terbit' ? $e->questions_count : count($e->draft['questions'] ?? []),
            'classes' => $e->classes->map(fn ($c) => $c->classroom?->name)->filter()->values(),
            'started' => $e->attempts_count, 'done' => $e->done_count, 'teacher' => $e->teacher?->name,
        ]));
    }

    public function show(Request $request, CbtExam $exam): JsonResponse
    {
        $this->guard($request, $exam);
        $classes = $exam->classes()->with(['classroom:id,name', 'grader:id,name'])->get();

        return response()->json($this->brief($exam) + [
            'questions' => $exam->status === 'terbit' ? $exam->questions()->pluck('source_question_id') : ($exam->draft['questions'] ?? []),
            'bank_id' => $exam->draft['bank_id'] ?? null,
            'classes' => $classes->map(fn ($c) => ['id' => $c->id, 'classroom_id' => $c->classroom_id, 'name' => $c->classroom?->name,
                'grader_id' => $c->essay_grader_id, 'grader' => $c->grader?->name, 'token' => $exam->status === 'terbit' ? $c->token : null]),
        ]);
    }

    private function validated(Request $request, ?CbtExam $exam = null): array
    {
        $tid = $this->teacherId($request);
        $d = $request->validate([
            'kind' => ['nullable', Rule::in(['pts', 'pas', 'ujian'])], 'grade_lm' => [$tid !== null ? 'required' : 'nullable', 'integer', 'min:1', 'max:'.GradeBook::MAX_LM],
            'title' => ['required', 'string', 'max:150'], 'subject' => ['required', 'string', 'max:80'], 'grade' => ['nullable', 'string', 'max:8'],
            'code' => ['required', 'string', 'max:30', 'regex:/^[A-Za-z0-9._-]+$/', Rule::unique('cbt_exams', 'code')->where('school_id', $this->tenant->id())->ignore($exam?->id)],
            'date' => ['required', 'date_format:Y-m-d'], 'start' => ['required', 'date_format:H:i'], 'end' => ['required', 'date_format:H:i'],
            'duration' => ['required', 'integer', 'min:5', 'max:600'], 'shuffle_questions' => ['boolean'], 'shuffle_options' => ['boolean'],
            'max_exits' => ['nullable', 'integer', 'min:1', 'max:50'],
            'exit_action' => ['nullable', Rule::in(array_keys(\App\Models\CbtExam::EXIT_ACTIONS))], 'exit_allow' => ['nullable', 'integer', 'min:0', 'max:10'],
            'questions' => ['array', 'max:'.CbtService::MAX_QUESTIONS], 'questions.*' => ['integer'], 'bank_id' => ['nullable', 'integer'],
            'classes' => ['array'], 'classes.*.classroom_id' => ['required', 'integer'], 'classes.*.grader_id' => ['nullable', 'integer'],
        ], ['code.unique' => 'Kode ujian sudah dipakai ujian lain.', 'code.regex' => 'Kode hanya huruf, angka, titik, minus, garis bawah.'],
            ['title' => 'judul ujian', 'grade_lm' => 'kolom nilai S', 'subject' => 'mata pelajaran', 'code' => 'kode ujian', 'date' => 'tanggal', 'start' => 'jam mulai', 'end' => 'jam selesai', 'duration' => 'durasi']);
        $tz = $this->cbt->tz();
        $d['starts_at'] = CarbonImmutable::createFromFormat('Y-m-d H:i', $d['date'].' '.$d['start'], $tz)->utc();
        $d['ends_at'] = CarbonImmutable::createFromFormat('Y-m-d H:i', $d['date'].' '.$d['end'], $tz)->utc();
        if ($d['ends_at']->lte($d['starts_at'])) {
            throw ValidationException::withMessages(['end' => 'Jam selesai harus setelah jam mulai.']);
        }
        $d['code'] = strtoupper($d['code']);
        if ($tid !== null) {
            // Ulangan Harian: pemilik = guru ini, mapel & kelas sesuai Jadwal KBM, guru sendiri yang menilai essay.
            $teacher = $request->user()->teacher;
            if (! in_array($d['subject'], $this->cbt->teacherSubjects($teacher), true)) {
                throw ValidationException::withMessages(['subject' => 'Pilih mata pelajaran yang Anda ajar.']);
            }
            $taught = $this->taughtClasses($tid, $d['subject']);
            $d['classes'] = collect($d['classes'] ?? [])->filter(fn ($c) => in_array((int) $c['classroom_id'], $taught, true))
                ->map(fn ($c) => ['classroom_id' => (int) $c['classroom_id'], 'grader_id' => $tid])->values()->all();
            $d['kind'] = 'uh';
            $d['teacher_id'] = $tid;
            // Paket soal milik ulangan ini (ditulis / diimpor dari layar ulangan).
            $d['bank_id'] = isset($d['bank_id']) && \App\Models\CbtBank::query()->whereKey($d['bank_id'])->where('teacher_id', $tid)->where('kind', 'uh')->exists() ? (int) $d['bank_id'] : null;
        } else {
            $d['kind'] = $d['kind'] ?? ($exam && ! $exam->isUh() ? $exam->kind : 'ujian');
            $d['teacher_id'] = null;
            $d['grade_lm'] = null;
            $d['bank_id'] = null;
        }

        return $d;
    }

    private function saveDraft(CbtExam $exam, array $d): void
    {
        // PTS/PAS: soal dari bank soal (paket diterima admin). UH: langsung dari paket milik guru sendiri.
        $valid = CbtQuestion::query()->whereIn('id', $d['questions'] ?? [])->whereHas('bank', fn ($b) => $b->where('subject', $d['subject'])
            ->when($d['kind'] === 'uh', fn ($x) => $x->where('teacher_id', $d['teacher_id']), fn ($x) => $x->where('status', 'diterima')))->pluck('id')->all();
        $ids = array_values(array_filter(array_unique(array_map('intval', $d['questions'] ?? [])), fn ($id) => in_array($id, $valid, true)));
        $exam->fill([
            'title' => $d['title'], 'kind' => $d['kind'], 'teacher_id' => $d['teacher_id'], 'grade_lm' => $d['grade_lm'] ?? null, 'subject' => $d['subject'], 'grade' => $d['grade'] ?? null, 'code' => $d['code'], 'starts_at' => $d['starts_at'], 'ends_at' => $d['ends_at'],
            'duration_minutes' => $d['duration'], 'shuffle_questions' => $d['shuffle_questions'] ?? true, 'shuffle_options' => $d['shuffle_options'] ?? true,
            'max_exits' => $d['max_exits'] ?? 3, 'exit_action' => $d['exit_action'] ?? 'ulang', 'exit_allow' => $d['exit_allow'] ?? 0, 'draft' => array_filter(['questions' => $ids, 'bank_id' => $d['bank_id'] ?? null], fn ($v) => $v !== null),
        ])->save();
        $classIds = Classroom::query()->pluck('id')->all();
        $teacherIds = Teacher::query()->pluck('id')->all();
        $keep = [];
        foreach ($d['classes'] ?? [] as $c) {
            $cid = (int) $c['classroom_id'];
            if (! in_array($cid, $classIds, true) || in_array($cid, $keep, true)) {
                continue;
            }
            $gid = isset($c['grader_id']) && in_array((int) $c['grader_id'], $teacherIds, true) ? (int) $c['grader_id'] : null;
            CbtExamClass::query()->updateOrCreate(['cbt_exam_id' => $exam->id, 'classroom_id' => $cid], ['essay_grader_id' => $gid]);
            $keep[] = $cid;
        }
        $exam->classes()->whereNotIn('classroom_id', $keep ?: [0])->delete();
    }

    public function store(Request $request): JsonResponse
    {
        $d = $this->validated($request);
        $exam = DB::transaction(function () use ($d, $request) {
            $exam = new CbtExam(['status' => 'draf', 'created_by' => $request->user()->id]);
            $this->saveDraft($exam, $d);

            return $exam;
        });

        return response()->json(['id' => $exam->id, 'message' => 'Draf ujian disimpan.'], 201);
    }

    public function update(Request $request, CbtExam $exam): JsonResponse
    {
        $this->guard($request, $exam);
        abort_if($exam->isUh() && $this->teacherId($request) === null, 403, 'Ulangan Harian hanya bisa diubah guru pembuatnya.');
        abort_unless($exam->status === 'draf', 422, 'Ujian yang sudah terbit tidak bisa diubah. Ganti guru penilai atau token lewat daftar ujian.');
        $d = $this->validated($request, $exam);
        DB::transaction(fn () => $this->saveDraft($exam, $d));

        return response()->json(['id' => $exam->id, 'message' => 'Draf ujian disimpan.']);
    }

    public function publish(Request $request, CbtExam $exam): JsonResponse
    {
        $this->guard($request, $exam);
        abort_unless($exam->status === 'draf', 422, 'Ujian sudah terbit.');
        $this->cbt->publish($exam, $request->user());

        return response()->json(['message' => 'Ujian terbit. Siswa peserta mendapat notifikasi; token tidak ikut dikirim.']);
    }

    public function destroy(Request $request, CbtExam $exam): JsonResponse
    {
        $this->guard($request, $exam);
        abort_if($exam->attempts()->exists(), 422, 'Ujian yang sudah dikerjakan siswa tidak bisa dihapus.');
        DB::transaction(function () use ($exam) {
            foreach ($exam->questions()->get() as $q) {
                app(\App\Tenancy\TenantFiles::class)->delete($q->image_path);
                foreach ($q->options ?? [] as $o) {
                    app(\App\Tenancy\TenantFiles::class)->delete($o['image'] ?? null);
                }
            }
            // Paket soal UH ikut dihapus bila tidak dipakai ulangan lain.
            if ($bid = $exam->draft['bank_id'] ?? null) {
                $used = CbtExam::query()->whereKeyNot($exam->id)->where('draft->bank_id', $bid)->exists();
                $used || \App\Models\CbtBank::query()->whereKey($bid)->where('kind', 'uh')->delete();
            }
            $exam->delete();
        });

        return response()->json(['message' => 'Ujian dihapus.']);
    }

    /** Buat ulang token: satu kelas (classroom_id) atau semua kelas. Token lama langsung tidak berlaku. */
    public function tokens(Request $request, CbtExam $exam): JsonResponse
    {
        $this->guard($request, $exam);
        abort_unless($exam->status === 'terbit', 422, 'Token dibuat saat ujian diterbitkan.');
        $cid = $request->integer('classroom_id');
        $rows = $exam->classes()->when($cid, fn ($q) => $q->where('classroom_id', $cid))->with('classroom:id,name')->get();
        foreach ($rows as $c) {
            $c->update(['token' => CbtExamClass::newToken(), 'token_at' => now()]);
        }
        $this->cbt->log($exam, 'token_baru', $rows->map(fn ($c) => $c->classroom?->name)->implode(', '), null, $request->user());

        return response()->json(['message' => $rows->count() === 1 ? 'Token kelas '.$rows->first()->classroom?->name.' dibuat ulang.' : 'Token semua kelas dibuat ulang.',
            'tokens' => $rows->mapWithKeys(fn ($c) => [$c->classroom_id => $c->token])]);
    }

    public function grader(Request $request, CbtExam $exam, CbtExamClass $class): JsonResponse
    {
        abort_unless($class->cbt_exam_id === $exam->id, 404);
        abort_if($this->teacherId($request) !== null || $exam->isUh(), 403, 'Penilai essay Ulangan Harian selalu guru pembuatnya.');
        $d = $request->validate(['grader_id' => ['nullable', 'integer', Rule::exists('teachers', 'id')->where('school_id', $this->tenant->id())]]);
        $class->update(['essay_grader_id' => $d['grader_id'] ?? null]);
        $this->cbt->log($exam, 'ganti_penilai', $class->classroom?->name.' → '.($class->fresh()->grader?->name ?? '-'), null, $request->user());

        return response()->json(['message' => 'Guru penilai essay diperbarui.']);
    }

    // ── Daftar pantau ───────────────────────────────────────────────

    public function monitor(Request $request, CbtExam $exam): JsonResponse
    {
        $this->guard($request, $exam);
        $this->cbt->expireExam($exam);
        $cid = $request->integer('classroom_id') ?: null;
        $students = $this->cbt->participants($exam, $cid);
        $attempts = $exam->attempts()->whereIn('student_id', $students->pluck('id'))->get()->keyBy('student_id');
        $answered = CbtAnswer::query()->whereIn('cbt_attempt_id', $attempts->pluck('id'))
            ->where(fn ($q) => $q->whereNotNull('choice')->orWhere(fn ($x) => $x->whereNotNull('text')->where('text', '!=', '')))
            ->groupBy('cbt_attempt_id')->selectRaw('cbt_attempt_id, count(*) n')->pluck('n', 'cbt_attempt_id');
        $classes = $exam->classes()->with('classroom:id,name')->get();
        $names = $classes->pluck('classroom.name', 'classroom_id');
        $total = $exam->questions()->count();

        $rows = $students->map(function (Student $s) use ($attempts, $answered, $names, $total) {
            $a = $attempts->get($s->id);

            return ['student_id' => $s->id, 'name' => $s->name, 'nis' => $s->nis, 'class' => $names[$s->classroom_id] ?? '-',
                'attempt_id' => $a?->id, 'status' => $a ? $a->status : 'belum', 'answered' => $a ? ($a->status === 'selesai' ? $a->answered : (int) ($answered[$a->id] ?? 0)) : 0,
                'total' => $total, 'left' => $a && $a->status === 'mengerjakan' ? max(0, (int) now()->diffInSeconds($a->deadline_at, false)) : null,
                'exits' => $a?->exits ?? 0, 'locked' => (bool) $a?->locked_at, 'restarts' => (int) ($a?->restarts ?? 0), 'last_seen' => $a?->last_seen_at?->toIso8601String(),
                'score' => $a?->status === 'selesai' ? ($a->final_score ?? $a->pgPercent()) : null, 'essay_pending' => (bool) $a?->essayPending()];
        });

        return response()->json($this->brief($exam) + [
            'now' => now()->toIso8601String(), 'total' => $total,
            'classes' => $classes->map(fn ($c) => ['classroom_id' => $c->classroom_id, 'name' => $c->classroom?->name, 'token' => $c->token]),
            'rows' => $rows->values(),
            'count' => ['belum' => $rows->where('status', 'belum')->count(), 'mengerjakan' => $rows->where('status', 'mengerjakan')->count(),
                'selesai' => $rows->where('status', 'selesai')->count(), 'warn' => $rows->where('exits', '>=', $exam->max_exits)->count(),
                'locked' => $rows->where('locked', true)->count()],
        ]);
    }

    public function action(Request $request, CbtAttempt $attempt): JsonResponse
    {
        $d = $request->validate(['action' => ['required', 'in:reset,extend,force,unlock'], 'minutes' => ['nullable', 'integer', 'min:1', 'max:120']]);
        $exam = $attempt->exam;
        $this->guard($request, $exam);
        $name = $attempt->student?->name;
        $u = $request->user();
        switch ($d['action']) {
            case 'reset':
                $this->cbt->log($exam, 'reset', ($u->role === Role::Admin ? 'Sesi direset admin' : 'Sesi direset guru').' (jawaban dihapus)', $attempt->student_id, $u);
                $attempt->delete();
                $msg = 'Sesi '.$name.' direset. Siswa bisa mulai lagi dengan token kelas.';
                break;
            case 'unlock':
                abort_unless($attempt->status === 'mengerjakan' && $attempt->locked_at, 422, 'Sesi ini tidak terkunci.');
                $attempt->update(['locked_at' => null]);
                $this->cbt->log($exam, 'buka_kunci', 'Kunci ujian dibuka pengawas', $attempt->student_id, $u);
                $msg = 'Ujian '.$name.' dibuka. Siswa bisa melanjutkan (waktu tetap berjalan).';
                break;
            case 'extend':
                abort_unless($attempt->status === 'mengerjakan', 422, 'Sesi ini sudah selesai.');
                $m = $d['minutes'] ?? 5;
                $attempt->update(['deadline_at' => $attempt->deadline_at->copy()->addMinutes($m)]);
                $this->cbt->log($exam, 'tambah_waktu', '+'.$m.' menit', $attempt->student_id, $u);
                $msg = $name.' mendapat tambahan '.$m.' menit.';
                break;
            default:
                abort_unless($attempt->status === 'mengerjakan', 422, 'Sesi ini sudah selesai.');
                $this->cbt->finish($attempt, 'admin', $u);
                $this->cbt->notifyGrader($attempt->fresh());
                $msg = 'Jawaban '.$name.' dikirim.';
        }

        return response()->json(['message' => $msg]);
    }

    // ── Record & nilai ──────────────────────────────────────────────

    public function results(Request $request, CbtExam $exam): JsonResponse
    {
        $this->guard($request, $exam);
        $this->cbt->expireExam($exam);
        $students = $this->cbt->participants($exam)->keyBy('id');
        $attempts = $exam->attempts()->where('status', 'selesai')->get();
        $classes = $exam->classes()->with(['classroom:id,name', 'grader:id,name'])->get();
        $names = $classes->pluck('classroom.name', 'classroom_id');
        $qs = $exam->questions()->get();
        $ids = $attempts->pluck('id');
        $stats = CbtAnswer::query()->whereIn('cbt_attempt_id', $ids)->groupBy('cbt_exam_question_id')
            ->selectRaw('cbt_exam_question_id q, sum(case when is_correct = 1 then 1 else 0 end) ok, avg(score) avg_score, sum(case when graded_at is null and text is not null then 1 else 0 end) pending')
            ->get()->keyBy('q');
        $n = max(1, $attempts->count());
        $scores = $attempts->map(fn ($a) => $a->final_score ?? $a->pgPercent())->filter(fn ($v) => $v !== null);
        $essayIds = $qs->where('type', 'essay')->pluck('id');

        return response()->json($this->brief($exam) + [
            'participants' => $students->count(), 'finished' => $attempts->count(),
            'avg' => $scores->count() ? round($scores->avg(), 1) : null, 'max' => $scores->max(), 'min' => $scores->min(),
            'rows' => $attempts->map(fn ($a) => ['attempt_id' => $a->id, 'name' => $students[$a->student_id]->name ?? '(siswa keluar)', 'nis' => $students[$a->student_id]->nis ?? '',
                'class' => $names[$a->classroom_id] ?? '-', 'correct' => $a->correct, 'wrong' => $a->wrong, 'blank' => $a->blank, 'pg' => $a->pgPercent(),
                'essay' => $a->essay_score, 'final' => $a->final_score, 'pending' => $a->essayPending(), 'exits' => $a->exits])->sortBy([['class', 'asc'], ['name', 'asc']])->values(),
            'questions' => $qs->map(fn ($q) => ['number' => $q->number, 'type' => $q->type, 'body' => mb_substr($q->body, 0, 140), 'score' => $q->score,
                'correct_pct' => $q->type === 'pg' ? round(((int) ($stats[$q->id]->ok ?? 0)) / $n * 100) : null,
                'avg_score' => $q->type === 'essay' && isset($stats[$q->id]) ? round((float) $stats[$q->id]->avg_score, 2) : null]),
            'essay' => $essayIds->count() ? $classes->map(function ($c) use ($attempts, $essayIds) {
                $at = $attempts->where('classroom_id', $c->classroom_id);

                return ['class' => $c->classroom?->name, 'grader' => $c->grader?->name, 'finished' => $at->count(), 'graded' => $at->filter(fn ($a) => ! $a->essayPending())->count()];
            })->values() : [],
        ]);
    }

    public function export(Request $request, CbtExam $exam): BinaryFileResponse
    {
        $data = $this->results($request, $exam)->getData(true);
        $tmp = tempnam(sys_get_temp_dir(), 'cbt').'.xlsx';
        $w = new Writer;
        $w->openToFile($tmp);
        $bold = (new Style)->setFontBold();
        $w->addRow(Row::fromValues([$exam->title.' · '.$exam->code.' · '.$exam->subject]));
        $w->addRow(Row::fromValues(['No', 'NIS', 'Nama', 'Kelas', 'Benar', 'Salah', 'Kosong', 'Nilai PG', 'Nilai essay', 'Nilai akhir', 'Keluar aplikasi'], $bold));
        foreach ($data['rows'] as $i => $r) {
            $w->addRow(Row::fromValues([$i + 1, $r['nis'], $r['name'], $r['class'], $r['correct'], $r['wrong'], $r['blank'], $r['pg'] ?? '',
                $r['essay'] ?? ($r['pending'] ? 'menunggu' : ''), $r['final'] ?? '', $r['exits']]));
        }
        $w->addRow(Row::fromValues([]));
        $w->addRow(Row::fromValues(['Analisis soal'], $bold));
        $w->addRow(Row::fromValues(['No', 'Jenis', 'Soal', 'Bobot', '% benar / rata-rata skor essay'], $bold));
        foreach ($data['questions'] as $q) {
            $w->addRow(Row::fromValues([$q['number'], $q['type'] === 'pg' ? 'PG' : 'Essay', $q['body'], $q['score'], $q['type'] === 'pg' ? $q['correct_pct'].'%' : ($q['avg_score'] ?? '')]));
        }
        $w->close();

        return response()->download($tmp, 'Nilai_'.preg_replace('/[^A-Za-z0-9_-]+/', '_', $exam->code).'.xlsx')->deleteFileAfterSend();
    }

    /** Record per siswa: semua ujian, nilai, dan log. */
    public function record(Request $request, Student $student): JsonResponse
    {
        $attempts = CbtAttempt::query()->with('exam:id,title,code,subject,starts_at')->where('student_id', $student->id)
            ->whereHas('exam', fn ($e) => $e->where('kind', '!=', 'uh'))->latest('started_at')->get();
        foreach ($attempts as $a) {
            $this->cbt->expireIfDue($a);
        }
        $logs = CbtExamLog::query()->where('student_id', $student->id)->whereIn('cbt_exam_id', CbtExam::query()->where('kind', '!=', 'uh')->select('id'))->latest('id')->limit(100)->get();
        $examTitles = CbtExam::query()->whereIn('id', $logs->pluck('cbt_exam_id'))->pluck('code', 'id');

        return response()->json(['student' => ['id' => $student->id, 'name' => $student->name, 'nis' => $student->nis, 'class' => $student->classroom?->name],
            'attempts' => $attempts->map(fn ($a) => ['exam' => $a->exam?->title, 'code' => $a->exam?->code, 'subject' => $a->exam?->subject,
                'date' => $this->local($a->started_at)->format('Y-m-d'), 'status' => $a->status, 'pg' => $a->pgPercent(), 'final' => $a->final_score,
                'pending' => $a->essayPending(), 'correct' => $a->correct, 'wrong' => $a->wrong, 'blank' => $a->blank, 'exits' => $a->exits]),
            'logs' => $logs->map(fn ($l) => ['at' => $this->local($l->created_at)->format('Y-m-d H:i:s'), 'exam' => $examTitles[$l->cbt_exam_id] ?? '', 'event' => $l->event, 'detail' => $l->detail]),
        ]);
    }

    public function students(Request $request): JsonResponse
    {
        $q = Student::query()->where('is_active', true)->with('classroom:id,name');
        if ($c = $request->integer('classroom_id')) {
            $q->where('classroom_id', $c);
        }
        if ($s = trim($request->string('q')->toString())) {
            $q->where(fn ($x) => $x->where('name', 'like', '%'.$s.'%')->orWhere('nis', 'like', $s.'%'));
        }

        return response()->json($q->orderBy('name')->limit(60)->get()->map(fn ($s) => ['id' => $s->id, 'name' => $s->name, 'nis' => $s->nis, 'class' => $s->classroom?->name]));
    }

    // ── Kartu token (cetak) ─────────────────────────────────────────

    public function cards(Request $request, CbtExam $exam, TenantContext $tenant): \Illuminate\View\View
    {
        $this->guard($request, $exam);
        abort_unless($exam->status === 'terbit', 404);
        $s = $this->local($exam->starts_at);
        $counts = Student::query()->where('is_active', true)->groupBy('classroom_id')->selectRaw('classroom_id, count(*) n')->pluck('n', 'classroom_id');
        $classes = $exam->classes()->with(['classroom:id,name', 'grader:id,name'])->get()->sortBy(fn ($c) => $c->classroom?->name)->values();
        if ($id = request()->integer('kelas')) {
            $classes = $classes->where('classroom_id', $id)->values();
        }

        return view('pwa.cbt-token-cards', [
            'school' => $tenant->get(), 'exam' => $exam, 'classes' => $classes, 'counts' => $counts,
            'date' => $s->locale('id')->translatedFormat('l, j F Y'), 'time' => $s->format('H.i').'–'.$this->local($exam->ends_at)->format('H.i'),
            'questions' => $exam->questions()->count(),
        ]);
    }
}
