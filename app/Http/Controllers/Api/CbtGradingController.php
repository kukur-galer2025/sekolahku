<?php

namespace App\Http\Controllers\Api;

use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Models\CbtAnswer;
use App\Models\CbtAttempt;
use App\Models\CbtExamClass;
use App\Models\CbtExamQuestion;
use App\Models\Student;
use App\Services\Cbt\CbtService;
use App\Services\Notify\Notifier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Penilaian essay oleh guru penilai yang ditunjuk admin per kelas (admin boleh mengoreksi). */
class CbtGradingController extends Controller
{
    public function __construct(private CbtService $cbt) {}

    private function authorizeClass(Request $request, CbtExamClass $class): void
    {
        $u = $request->user();
        abort_unless($u->role === Role::Admin || ($u->teacher && $class->essay_grader_id === $u->teacher->id), 403, 'Anda bukan guru penilai essay kelas ini.');
        abort_if($u->role === Role::Admin && $class->exam?->isUh(), 403, 'Essay Ulangan Harian dinilai guru pembuatnya.');
        abort_unless($class->exam?->status === 'terbit', 404);
    }

    public function index(Request $request): JsonResponse
    {
        $u = $request->user();
        $q = CbtExamClass::query()->with(['exam', 'classroom:id,name', 'grader:id,name'])
            ->whereHas('exam', fn ($e) => $e->where('status', 'terbit')->whereHas('questions', fn ($x) => $x->where('type', 'essay')));
        if ($u->role !== Role::Admin) {
            $q->where('essay_grader_id', $u->teacher?->id ?? 0);
        } else {
            $q->whereHas('exam', fn ($e) => $e->where('kind', '!=', 'uh'));
        }
        $rows = $q->get()->sortByDesc(fn ($c) => $c->exam->starts_at)->values();

        return response()->json($rows->map(function (CbtExamClass $c) {
            $this->cbt->expireExam($c->exam);
            $at = CbtAttempt::query()->where('cbt_exam_id', $c->cbt_exam_id)->where('classroom_id', $c->classroom_id)->where('status', 'selesai')->get();

            return ['id' => $c->id, 'exam' => $c->exam->title, 'code' => $c->exam->code, 'subject' => $c->exam->subject, 'class' => $c->classroom?->name,
                'grader' => $c->grader?->name, 'phase' => $c->exam->phase(), 'starts_at' => $c->exam->starts_at->toIso8601String(),
                'participants' => Student::query()->where('classroom_id', $c->classroom_id)->where('is_active', true)->count(),
                'finished' => $at->count(), 'pending' => $at->filter(fn ($a) => $a->essayPending())->count()];
        }));
    }

    public function show(Request $request, CbtExamClass $class): JsonResponse
    {
        $this->authorizeClass($request, $class);
        $exam = $class->exam;
        $this->cbt->expireExam($exam);
        $qs = $exam->questions()->where('type', 'essay')->get();
        $attempts = CbtAttempt::query()->with('student:id,name,nis')->where('cbt_exam_id', $exam->id)->where('classroom_id', $class->classroom_id)
            ->where('status', 'selesai')->get()->sortBy(fn ($a) => $a->student?->name)->values();
        $answers = CbtAnswer::query()->whereIn('cbt_attempt_id', $attempts->pluck('id'))->whereIn('cbt_exam_question_id', $qs->pluck('id'))->get()
            ->groupBy('cbt_attempt_id');

        return response()->json([
            'id' => $class->id, 'exam' => $exam->title, 'code' => $exam->code, 'class' => $class->classroom?->name,
            'questions' => $qs->map(fn ($q) => $this->cbt->presentQuestion($q)),
            'students' => $attempts->map(fn ($a) => ['attempt_id' => $a->id, 'name' => $a->student?->name, 'nis' => $a->student?->nis,
                'pending' => $a->essayPending(), 'final' => $a->final_score, 'pg' => $a->pgPercent(),
                'answers' => ($answers[$a->id] ?? collect())->map(fn ($x) => ['id' => $x->id, 'question_id' => $x->cbt_exam_question_id, 'text' => $x->text,
                    'score' => $x->score, 'note' => $x->note, 'graded' => $x->graded_at !== null])->values()]),
        ]);
    }

    public function grade(Request $request, CbtAnswer $answer, Notifier $notifier): JsonResponse
    {
        $attempt = $answer->attempt;
        $q = CbtExamQuestion::query()->findOrFail($answer->cbt_exam_question_id);
        $class = CbtExamClass::query()->where('cbt_exam_id', $attempt->cbt_exam_id)->where('classroom_id', $attempt->classroom_id)->firstOrFail();
        $this->authorizeClass($request, $class);
        abort_unless($q->type === 'essay' && $attempt->status === 'selesai', 422, 'Jawaban ini tidak bisa dinilai.');
        $d = $request->validate(['score' => ['required', 'numeric', 'min:0', 'max:'.$q->score], 'note' => ['nullable', 'string', 'max:300']],
            ['score.max' => 'Skor maksimal '.rtrim(rtrim(number_format($q->score, 2, ',', ''), '0'), ',').'.'], ['score' => 'skor']);
        $wasFinal = $attempt->final_score;
        $old = $answer->score;
        $answer->update(['score' => $d['score'], 'note' => $d['note'] ?? null, 'graded_by' => $request->user()->id, 'graded_at' => now()]);
        $this->cbt->recompute($attempt);
        $attempt->refresh();
        if ($wasFinal !== null && (float) $old !== (float) $d['score']) {
            $this->cbt->log($attempt->exam, 'nilai_diubah', 'Essay no. '.$q->number.': '.$old.' → '.$d['score'], $attempt->student_id, $request->user());
        }
        if ($attempt->final_score !== null && ($wasFinal === null || $wasFinal !== $attempt->final_score) && ($user = $attempt->student?->user)) {
            $notifier->send([$user], 'cbt', $wasFinal === null ? 'Nilai ujian keluar' : 'Nilai ujian diperbarui', $attempt->exam->title.': '.rtrim(rtrim(number_format($attempt->final_score, 2, ',', ''), '0'), ','), '#/cbt');
        }

        return response()->json(['message' => 'Skor tersimpan.', 'final' => $attempt->final_score, 'pending' => $attempt->essayPending()]);
    }
}
