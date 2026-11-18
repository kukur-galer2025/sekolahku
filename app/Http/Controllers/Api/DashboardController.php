<?php

namespace App\Http\Controllers\Api;

use App\Enums\AttendanceStatus;
use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Models\Assignment;
use App\Models\AssignmentDone;
use App\Models\Attendance;
use App\Models\Classroom;
use App\Models\Schedule;
use App\Models\Student;
use App\Models\Teacher;
use App\Services\Timetable;
use App\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Ringkasan dashboard PWA sesuai peran. */
class DashboardController extends Controller
{
    public function __invoke(Request $request, TenantContext $tenant, Timetable $tt): JsonResponse
    {
        $school = $tenant->get();
        $now = $school->localNow();
        $today = $now->toDateString();
        $u = $request->user();
        $base = ['date' => $today, 'day' => $now->dayOfWeekIso, 'day_name' => Timetable::DAYS[$now->dayOfWeekIso], 'is_school_day' => $school->isSchoolDay($now)];

        if ($u->role === Role::Student) {
            $s = $u->student;
            $att = $s ? Attendance::query()->where('student_id', $s->id)->whereDate('date', $today)->first() : null;
            $done = AssignmentDone::query()->where('student_id', $s?->id ?? 0)->pluck('assignment_id');
            $pending = Assignment::query()->where('classroom_id', $s?->classroom_id ?? 0)->where('due_date', '>=', $now->subDays(7)->toDateString())
                ->whereNotIn('id', $done)->orderBy('due_date')->get(['id', 'subject', 'title', 'due_date', 'due_time']);

            return response()->json($base + [
                'attendance' => $att ? ['status' => $att->status->value, 'label' => $att->status->label(), 'time' => $att->scanned_at?->setTimezone($school->timezone)->format('H:i'), 'is_late' => $att->is_late] : null,
                'pending_tasks' => $pending->map(fn ($a) => ['id' => $a->id, 'subject' => $a->subject, 'title' => $a->title, 'due_date' => $a->due_date->format('Y-m-d'), 'due_time' => $a->dueHm()]),
                'today_lessons' => $s?->classroom ? $tt->blocks($s->classroom, null, $now->dayOfWeekIso) : [],
            ]);
        }

        if ($u->role === Role::Teacher) {
            $t = $u->teacher;
            $blocks = $t ? $tt->blocks(null, $t) : collect();

            return response()->json($base + [
                'subject' => $t?->subject,
                'today_lessons' => $blocks->where('day', $now->dayOfWeekIso)->values(),
                'hours_today' => $blocks->where('day', $now->dayOfWeekIso)->sum('period_count'),
                'hours_week' => $blocks->sum('period_count'),
                'homerooms' => $t ? $t->homeroomClassrooms()->orderBy('name')->get(['id', 'name']) : [],
                'my_classrooms' => $blocks->pluck('classroom')->filter()->unique('id')->sortBy('name')->values(),
                'my_check_in' => ($ci = $t ? \App\Models\TeacherAttendance::query()->where('teacher_id', $t->id)->whereDate('date', $today)->first() : null)
                    ? $ci->checked_in_at->setTimezone($school->timezone)->format('H:i') : null,
            ]);
        }

        // Admin
        $students = Student::query()->where('is_active', true)->selectRaw('classroom_id, count(*) n')->groupBy('classroom_id')->pluck('n', 'classroom_id');
        $att = Attendance::query()->whereDate('date', $today)->selectRaw('classroom_id, status, count(*) n')->groupBy('classroom_id', 'status')->get();
        $per = Classroom::query()->orderBy('name')->get(['id', 'name'])->filter(fn ($c) => ($students[$c->id] ?? 0) > 0)->map(function ($c) use ($students, $att) {
            $row = ['id' => $c->id, 'name' => $c->name, 'total' => (int) $students[$c->id]];
            foreach (AttendanceStatus::cases() as $st) {
                $row[$st->value] = (int) $att->where('classroom_id', $c->id)->where('status', $st)->sum('n');
            }
            $row['belum'] = $row['total'] - $row['H'] - $row['S'] - $row['I'] - $row['A'];

            return $row;
        })->values();

        return response()->json($base + [
            'students' => (int) $students->sum(), 'teachers' => Teacher::query()->count(), 'classrooms' => Classroom::query()->count(),
            'present' => (int) $per->sum('H'), 'hours_today' => (int) Schedule::query()->where('day', $now->dayOfWeekIso)->sum('period_count'),
            'per_classroom' => $per,
            'teacher_attendance' => ($r = app(\App\Services\TeacherAttendanceService::class)->report($school, $now))['summary'],
            'teacher_rows' => collect($r['teachers'])->filter(fn ($x) => $x['blocks'] > 0 || $x['check_in'])->values(),
        ]);
    }
}
