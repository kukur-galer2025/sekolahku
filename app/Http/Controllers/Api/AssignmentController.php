<?php

namespace App\Http\Controllers\Api;

use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Models\Assignment;
use App\Models\AssignmentDone;
use App\Models\Classroom;
use App\Models\Schedule;
use App\Models\Student;
use App\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Penugasan guru → dashboard & "Tugas-Ku" siswa kelas terkait. */
class AssignmentController extends Controller
{
    public function __construct(private TenantContext $tenant) {}

    public function index(Request $request): JsonResponse
    {
        $u = $request->user();
        $q = Assignment::query()->with(['classroom:id,name', 'teacher:id,name'])->withCount(['done', 'done as graded_count' => fn ($x) => $x->whereNotNull('graded_at')])->orderBy('due_date')->orderByDesc('id');

        if ($u->role === Role::Student) {
            $student = $u->student;
            $mine = AssignmentDone::query()->where('student_id', $student?->id ?? 0)->get()->keyBy('assignment_id');

            return response()->json($q->where('classroom_id', $student?->classroom_id ?? 0)
                ->where('due_date', '>=', $this->tenant->get()->localNow()->subDays(30)->toDateString())->limit(200)->get()
                ->map(fn ($a) => $this->present($a) + ['done' => $mine->has($a->id), 'score' => $mine->get($a->id)?->graded_at ? $mine->get($a->id)->score : null,
                    'feedback' => $mine->get($a->id)?->graded_at ? $mine->get($a->id)->feedback : null]));
        }

        $q->when($u->role === Role::Teacher, fn ($b) => $b->where('teacher_id', $u->teacher?->id ?? 0))
            ->when($request->integer('classroom_id'), fn ($b, $id) => $b->where('classroom_id', $id));
        $totals = Student::query()->where('is_active', true)->selectRaw('classroom_id, count(*) n')->groupBy('classroom_id')->pluck('n', 'classroom_id');

        $rows = $q->limit(300)->get();
        $withFiles = \App\Models\AssignmentFile::query()->whereIn('assignment_id', $rows->pluck('id'))->groupBy('assignment_id')
            ->selectRaw('assignment_id, count(distinct student_id) n')->pluck('n', 'assignment_id');

        return response()->json($rows->map(fn ($a) => $this->present($a) + ['done_count' => $a->done_count, 'graded_count' => (int) $a->graded_count,
            'file_count' => (int) ($withFiles[$a->id] ?? 0), 'student_count' => (int) ($totals[$a->classroom_id] ?? 0)]));
    }

    /** Pengumpulan tugas untuk guru: status, waktu kumpul, berkas, nilai & catatan tiap siswa. */
    public function show(Request $request, Assignment $assignment): JsonResponse
    {
        $this->authorizeOwner($request, $assignment);
        $done = $assignment->done()->get()->keyBy('student_id');
        $files = \App\Models\AssignmentFile::query()->where('assignment_id', $assignment->id)->orderBy('created_at')->get()->groupBy('student_id');
        $tf = app(\App\Tenancy\TenantFiles::class);
        $tz = $this->tenant->get()->timezone ?: 'Asia/Jakarta';
        $deadline = $assignment->deadline($tz);
        $students = Student::query()->where('classroom_id', $assignment->classroom_id)->where('is_active', true)->orderBy('name')->get(['id', 'nis', 'name']);

        return response()->json($this->present($assignment->load(['classroom:id,name', 'teacher:id,name'])) + [
            'students' => $students->map(function ($s) use ($done, $files, $tf, $deadline) {
                $d = $done->get($s->id);
                $F = $files[$s->id] ?? collect();
                $at = $F->max('created_at') ?? $d?->done_at; // waktu kumpul = berkas terakhir, atau saat menandai selesai

                return $s->only(['id', 'nis', 'name']) + [
                    'done' => (bool) $d, 'submitted_at' => $at?->toIso8601String(), 'late' => $at ? $at->gt($deadline) : false,
                    'score' => $d?->graded_at ? $d->score : null, 'feedback' => $d?->feedback, 'graded' => (bool) $d?->graded_at,
                    'files' => $F->map(fn ($f) => ['name' => $f->file_name, 'type' => $f->file_type, 'size' => $f->file_size,
                        'at' => $f->created_at?->toIso8601String(), 'url' => $tf->url($f->file_path, 60, $f->file_name)])->values(),
                ];
            }),
        ]);
    }

    /** Nilai & catatan guru untuk satu siswa. Bila siswa mengumpulkan di kertas, nilai sekaligus menandai tugasnya terkumpul. */
    public function grade(Request $request, Assignment $assignment, Student $student): JsonResponse
    {
        $this->authorizeOwner($request, $assignment);
        abort_unless($student->classroom_id === $assignment->classroom_id, 404);
        $d = $request->validate(['score' => ['nullable', 'numeric', 'min:0', 'max:100'], 'feedback' => ['nullable', 'string', 'max:500']], [], ['score' => 'nilai', 'feedback' => 'catatan']);
        $row = AssignmentDone::query()->firstOrNew(['assignment_id' => $assignment->id, 'student_id' => $student->id]);
        $first = ! $row->graded_at;
        $row->done_at ??= now();
        $row->fill(['score' => $d['score'] ?? null, 'feedback' => $d['feedback'] ?? null,
            'graded_by' => $request->user()->id, 'graded_at' => ($d['score'] ?? null) !== null || filled($d['feedback'] ?? null) ? now() : null])->save();
        if ($row->graded_at && $student->user) {
            rescue(fn () => app(\App\Services\Notify\Notifier::class)->send([$student->user], 'assignment',
                ($first ? 'Tugas dinilai: ' : 'Nilai tugas diperbarui: ').$assignment->subject,
                $assignment->title.(($d['score'] ?? null) !== null ? ' · nilai '.rtrim(rtrim(number_format((float) $d['score'], 2, ',', ''), '0'), ',') : ''), '#/tugas'));
        }

        return response()->json(['message' => 'Nilai tersimpan.', 'graded' => (bool) $row->graded_at]);
    }

    /** Rekap pengumpulan & nilai (Excel). */
    public function export(Request $request, Assignment $assignment): \Symfony\Component\HttpFoundation\BinaryFileResponse
    {
        $data = $this->show($request, $assignment)->getData(true);
        $tz = $this->tenant->get()->timezone ?: 'Asia/Jakarta';
        $tmp = tempnam(sys_get_temp_dir(), 'tgs').'.xlsx';
        $w = new \OpenSpout\Writer\XLSX\Writer;
        $w->openToFile($tmp);
        $bold = (new \OpenSpout\Common\Entity\Style\Style)->setFontBold();
        $row = fn (array $v, $st = null) => $w->addRow(\OpenSpout\Common\Entity\Row::fromValues($v, $st));
        $row([$data['title'].' · '.$data['subject'].' · '.($data['classroom']['name'] ?? '').' · tenggat '.$data['due_date'].' '.$data['due_time']]);
        $row(['No', 'NIS', 'Nama', 'Status', 'Waktu kumpul', 'Jumlah berkas', 'Nilai', 'Catatan guru'], $bold);
        foreach ($data['students'] as $i => $s) {
            $row([$i + 1, $s['nis'], $s['name'], ! $s['done'] ? 'Belum' : ($s['late'] ? 'Terlambat' : 'Dikumpulkan'),
                $s['submitted_at'] ? \Illuminate\Support\Carbon::parse($s['submitted_at'])->setTimezone($tz)->format('Y-m-d H:i') : '',
                count($s['files']), $s['score'] ?? '', $s['feedback'] ?? '']);
        }
        $w->close();

        return response()->download($tmp, 'Tugas_'.preg_replace('/[^A-Za-z0-9_-]+/', '_', ($data['classroom']['name'] ?? '').'_'.$data['title']).'.xlsx')->deleteFileAfterSend();
    }

    public function store(Request $request): JsonResponse
    {
        $teacher = $request->user()->teacher;
        abort_unless($teacher, 403, 'Hanya guru yang memberi tugas.');
        $today = $this->tenant->get()->localNow()->toDateString();
        $d = $request->validate([
            'classroom_id' => ['required', 'integer'],
            'subject' => ['nullable', 'string', 'max:120'],
            'title' => ['required', 'string', 'max:160'],
            'description' => ['nullable', 'string', 'max:2000'],
            'due_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:'.$today],
            'due_time' => ['nullable', 'date_format:H:i'],
        ], ['due_date.after_or_equal' => 'Tenggat tidak boleh sebelum hari ini.'], ['title' => 'judul tugas', 'due_date' => 'tanggal tenggat', 'due_time' => 'jam tenggat']);
        $time = ($d['due_time'] ?? null) ?: '23:59';
        if ($d['due_date'] === $today && $time <= $this->tenant->get()->localNow()->format('H:i')) {
            throw \Illuminate\Validation\ValidationException::withMessages(['due_time' => 'Jam tenggat hari ini sudah lewat. Pilih jam yang lebih akhir.']);
        }
        $classroom = Classroom::findOrFail($d['classroom_id']);
        $subject = ($d['subject'] ?? null) ?: (Schedule::query()->where('teacher_id', $teacher->id)->where('classroom_id', $classroom->id)->value('subject') ?? $teacher->subject ?? '-');

        $a = Assignment::create(['teacher_id' => $teacher->id, 'classroom_id' => $classroom->id, 'subject' => $subject,
            'title' => $d['title'], 'description' => $d['description'] ?? null, 'due_date' => $d['due_date'], 'due_time' => $time]);

        rescue(fn () => app(\App\Services\Notify\Notifier::class)->send(
            \App\Models\User::query()->whereIn('id', $classroom->students()->where('is_active', true)->pluck('user_id'))->get(),
            'assignment', 'Tugas baru: '.$subject, $d['title'].' · tenggat '.$this->dueText($a), '#/tugas'));

        return response()->json(['message' => 'Tugas dikirim ke siswa kelas '.$classroom->name.'.', 'assignment' => $this->present($a->load(['classroom:id,name', 'teacher:id,name']))], 201);
    }

    public function destroy(Request $request, Assignment $assignment): JsonResponse
    {
        $this->authorizeOwner($request, $assignment);
        $assignment->delete();

        return response()->json(null, 204);
    }

    /** Siswa menandai tugas selesai (PUT) / belum (DELETE). Hanya tugas kelasnya sendiri. */
    public function markDone(Request $request, Assignment $assignment): JsonResponse
    {
        $student = $this->ownStudent($request, $assignment);
        $row = AssignmentDone::firstOrCreate(['assignment_id' => $assignment->id, 'student_id' => $student->id], ['done_at' => now()]);
        if ($row->wasRecentlyCreated) {
            $assignment->notifySubmitted($student, \App\Models\AssignmentFile::query()->where('assignment_id', $assignment->id)->where('student_id', $student->id)->count());
        }

        return response()->json(['done' => true]);
    }

    public function unmarkDone(Request $request, Assignment $assignment): JsonResponse
    {
        $student = $this->ownStudent($request, $assignment);
        $row = AssignmentDone::query()->where('assignment_id', $assignment->id)->where('student_id', $student->id)->first();
        abort_if($row?->graded_at, 422, 'Tugas ini sudah dinilai guru, tanda selesai tidak bisa dihapus.');
        $row?->delete();

        return response()->json(['done' => false]);
    }

    private function ownStudent(Request $request, Assignment $a): Student
    {
        $s = $request->user()->student;
        abort_unless($s && $s->classroom_id === $a->classroom_id, 404);

        return $s;
    }

    private function authorizeOwner(Request $request, Assignment $a): void
    {
        $u = $request->user();
        abort_unless($u->role === Role::Admin || $a->teacher_id === ($u->teacher?->id ?? -1), 403, 'Tugas ini milik guru lain.');
    }

    /** "Senin, 16 Nov 2026 pukul 14.00" */
    private function dueText(Assignment $a): string
    {
        return $a->due_date->locale('id')->translatedFormat('l, j M Y').' pukul '.str_replace(':', '.', $a->dueHm());
    }

    private function present(Assignment $a): array
    {
        return [
            'id' => $a->id, 'title' => $a->title, 'description' => $a->description, 'subject' => $a->subject,
            'due_date' => $a->due_date->format('Y-m-d'), 'due_time' => $a->dueHm(), 'created_at' => $a->created_at?->toIso8601String(),
            'classroom' => $a->classroom?->only(['id', 'name']), 'teacher' => $a->teacher?->only(['id', 'name']),
        ];
    }
}
