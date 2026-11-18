<?php

namespace App\Http\Controllers\Portal;

use App\Enums\AttendanceStatus;
use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Models\Attendance;
use App\Models\Classroom;
use App\Models\DataImport;
use App\Models\Schedule;
use App\Models\Student;
use App\Models\Teacher;
use App\Services\AttendanceService;
use App\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __invoke(Request $request, TenantContext $tenant, AttendanceService $attendance): View
    {
        $user = $request->user();
        $school = $tenant->get();
        $today = $school->localNow()->startOfDay();
        $date = $today->toDateString();
        $data = ['school' => $school, 'today' => $today, 'cutoff' => $attendance->cutoff($school, $today)->format('H:i')];

        if ($user->role === Role::Admin) {
            $records = Attendance::query()->whereDate('date', $date)->selectRaw('status, count(*) n')->groupBy('status')->pluck('n', 'status');
            $data += [
                'students' => Student::where('is_active', true)->count(),
                'teachers' => Teacher::count(),
                'classrooms' => Classroom::count(),
                'present' => (int) ($records[AttendanceStatus::Present->value] ?? 0),
                'absent' => (int) ($records[AttendanceStatus::Absent->value] ?? 0),
                'permit' => (int) ($records[AttendanceStatus::Sick->value] ?? 0) + (int) ($records[AttendanceStatus::Permit->value] ?? 0),
                'imports' => DataImport::latest()->limit(5)->get(),
                'onboarding' => $this->onboarding($user, $school),
            ];
        } elseif ($user->role === Role::Teacher) {
            $teacher = $user->teacher;
            $data += [
                'teacher' => $teacher,
                'lessonsToday' => $teacher ? Schedule::with('classroom:id,name')->where('teacher_id', $teacher->id)->where('day', $today->dayOfWeekIso)->orderBy('period_start')->get() : collect(),
                'lessonsWeek' => $teacher ? (int) Schedule::where('teacher_id', $teacher->id)->sum('period_count') : 0,
                'homerooms' => $teacher ? $teacher->homeroomClassrooms()->withCount('students')->get() : collect(),
            ];
        } else {
            $student = $user->student?->load('classroom');
            $data += [
                'student' => $student,
                'record' => $student ? Attendance::where('student_id', $student->id)->whereDate('date', $date)->first() : null,
                'windows' => $student?->classroom ? $attendance->lessonWindows($school, $student->classroom, $today) : [],
            ];
        }

        return view('portal.dashboard', $data);
    }

    /** Langkah onboarding sekolah baru; disembunyikan setelah semuanya selesai. */
    private function onboarding($user, $school): array
    {
        $steps = [
            ['Verifikasi email admin', (bool) $user->email_verified_at, null],
            ['Impor Jam KBM', \App\Models\LessonPeriod::exists(), route('portal.imports')],
            ['Impor data siswa (kelas terbentuk otomatis)', Student::exists(), route('portal.imports')],
            ['Impor data guru & wali kelas', Teacher::exists() && Classroom::whereNotNull('homeroom_teacher_id')->exists(), route('portal.imports')],
            ['Impor jadwal pelajaran', Schedule::exists(), route('portal.imports')],
            ['Daftarkan jaringan WiFi sekolah (untuk scan QR)', ! empty($school->setting('attendance.networks')), null],
        ];

        return ['steps' => $steps, 'done' => collect($steps)->where(1, true)->count(), 'total' => count($steps)];
    }
}
