<?php

namespace App\Http\Controllers\Api;

use App\Enums\AttendanceStatus;
use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Models\Assignment;
use App\Models\Attendance;
use App\Models\Classroom;
use App\Models\Journal;
use App\Models\Schedule;
use App\Services\Timetable;
use App\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Jurnal mengajar. Rentang jam mengikuti jadwal, jumlah hadir/tidak hadir diambil dari presensi QR (snapshot).
 * Guru: jurnalnya sendiri · admin: semua · siswa: jurnal kelasnya ("Jurnal Kelas").
 */
class JournalController extends Controller
{
    public function __construct(private TenantContext $tenant, private Timetable $tt) {}

    public function index(Request $request): JsonResponse
    {
        $u = $request->user();
        $q = Journal::query()->with(['classroom:id,name', 'teacher:id,name'])->latest('date')->latest('id');

        match ($u->role) {
            Role::Student => $q->where('classroom_id', $u->student?->classroom_id ?? 0),
            Role::Teacher => $q->where('teacher_id', $u->teacher?->id ?? 0),
            default => $q->when($request->integer('classroom_id'), fn ($b, $id) => $b->where('classroom_id', $id))
                ->when($request->integer('teacher_id'), fn ($b, $id) => $b->where('teacher_id', $id)),
        };
        $q->when($request->query('date'), fn ($b, $d) => $b->whereDate('date', $d));

        return response()->json($q->paginate(50)->through(fn (Journal $j) => $this->present($j)));
    }

    /** Isian otomatis form jurnal: jam dari jadwal hari ini + presensi kelas hari ini. */
    public function prefill(Request $request): JsonResponse
    {
        $teacher = $request->user()->teacher;
        abort_unless($teacher, 403, 'Akun ini bukan guru.');
        $classroom = Classroom::findOrFail($request->integer('classroom_id'));
        $today = $this->tenant->get()->localNow();
        $block = Schedule::query()->where('teacher_id', $teacher->id)->where('classroom_id', $classroom->id)
            ->where('day', $today->dayOfWeekIso)->orderBy('period_start')->first();

        return response()->json([
            'date' => $today->toDateString(),
            'period_start' => $block?->period_start,
            'period_end' => $block ? $block->period_start + max(1, $block->period_count) - 1 : null,
            'subject' => $block?->subject ?? $teacher->subject,
            'attendance' => $this->counts($classroom, $today->toDateString()),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $teacher = $request->user()->teacher;
        abort_unless($teacher, 403, 'Hanya guru yang mengisi jurnal.');
        $d = $request->validate([
            'classroom_id' => ['required', 'integer'],
            'period_start' => ['required', 'integer', 'min:1', 'max:20'],
            'period_end' => ['required', 'integer', 'gte:period_start', 'max:20'],
            'subject' => ['nullable', 'string', 'max:120'],
            'topic' => ['required', 'string', 'max:500'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'task_title' => ['nullable', 'string', 'max:160'],
            'task_due' => ['nullable', 'required_with:task_title', 'date_format:Y-m-d'],
        ], [], ['topic' => 'materi', 'period_end' => 'sampai jam ke-', 'task_due' => 'tenggat tugas']);

        $classroom = Classroom::findOrFail($d['classroom_id']); // ter-scope: kelas sekolah lain → 404
        $date = $this->tenant->get()->localNow()->toDateString();
        if (! empty($d['task_due']) && $d['task_due'] < $date) {
            abort(422, 'Tenggat tugas tidak boleh sebelum hari ini.');
        }
        $subject = ($d['subject'] ?? null) ?: (Schedule::query()->where('teacher_id', $teacher->id)->where('classroom_id', $classroom->id)->value('subject') ?? $teacher->subject ?? '-');
        $c = $this->counts($classroom, $date);

        $journal = DB::transaction(function () use ($teacher, $classroom, $date, $d, $subject, $c) {
            $j = Journal::create([
                'teacher_id' => $teacher->id, 'classroom_id' => $classroom->id, 'date' => $date,
                'period_start' => $d['period_start'], 'period_end' => $d['period_end'], 'subject' => $subject,
                'topic' => $d['topic'], 'notes' => $d['notes'] ?? null,
                'present' => $c['H'], 'sick' => $c['S'], 'permit' => $c['I'], 'absent' => $c['A'], 'total' => $c['total'],
            ]);
            if (! empty($d['task_title'])) {
                Assignment::create(['teacher_id' => $teacher->id, 'classroom_id' => $classroom->id, 'journal_id' => $j->id,
                    'subject' => $subject, 'title' => $d['task_title'], 'due_date' => $d['task_due']]);
            }

            return $j;
        });

        return response()->json(['message' => empty($d['task_title']) ? 'Jurnal disimpan.' : 'Jurnal dan tugas disimpan.', 'journal' => $this->present($journal->load(['classroom:id,name', 'teacher:id,name']))], 201);
    }

    public function destroy(Request $request, Journal $journal): JsonResponse
    {
        $u = $request->user();
        abort_unless($u->role === Role::Admin || $journal->teacher_id === ($u->teacher?->id ?? -1), 403, 'Hanya guru pembuat atau admin yang bisa menghapus jurnal ini.');
        $journal->delete();

        return response()->json(null, 204);
    }

    /** @return array{H:int,S:int,I:int,A:int,belum:int,total:int} */
    private function counts(Classroom $classroom, string $date): array
    {
        $ids = $classroom->students()->where('is_active', true)->pluck('id');
        $by = Attendance::query()->whereIn('student_id', $ids)->whereDate('date', $date)->get()->countBy(fn ($a) => $a->status->value);
        $c = ['H' => (int) ($by[AttendanceStatus::Present->value] ?? 0), 'S' => (int) ($by[AttendanceStatus::Sick->value] ?? 0),
            'I' => (int) ($by[AttendanceStatus::Permit->value] ?? 0), 'A' => (int) ($by[AttendanceStatus::Absent->value] ?? 0), 'total' => $ids->count()];
        $c['belum'] = $c['total'] - $c['H'] - $c['S'] - $c['I'] - $c['A'];

        return $c;
    }

    private function present(Journal $j): array
    {
        [$from, $to] = $this->tt->span($j->period_start, $j->period_end - $j->period_start + 1);

        return [
            'id' => $j->id, 'date' => $j->date->format('Y-m-d'), 'period_start' => $j->period_start, 'period_end' => $j->period_end,
            'from' => $from, 'to' => $to, 'subject' => $j->subject, 'topic' => $j->topic, 'notes' => $j->notes,
            'present' => $j->present, 'sick' => $j->sick, 'permit' => $j->permit, 'absent' => $j->absent, 'total' => $j->total,
            'classroom' => $j->classroom?->only(['id', 'name']), 'teacher' => $j->teacher?->only(['id', 'name']),
        ];
    }
}
