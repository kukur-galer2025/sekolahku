<?php

namespace App\Http\Controllers\Api;

use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Models\Classroom;
use App\Services\Timetable;
use App\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TimetableController extends Controller
{
    /**
     * GET /api/timetable
     *  siswa: jadwal kelasnya · guru: jadwal mengajarnya (atau ?classroom_id=) · admin: ?classroom_id= / ?teacher_id=
     */
    public function __invoke(Request $request, Timetable $tt, TenantContext $tenant): JsonResponse
    {
        $u = $request->user();
        $classroom = null;
        $teacher = null;

        if ($u->role === Role::Student) {
            $classroom = $u->student?->classroom;
            abort_unless($classroom, 404, 'Kamu belum masuk kelas mana pun. Hubungi wali kelas.');
        } elseif ($id = $request->integer('classroom_id')) {
            $classroom = Classroom::findOrFail($id);
        } elseif ($u->role === Role::Teacher) {
            $teacher = $u->teacher;
        } elseif ($tid = $request->integer('teacher_id')) {
            $teacher = \App\Models\Teacher::findOrFail($tid);
        }

        $school = $tenant->get();

        return response()->json([
            'today' => $school->localNow()->dayOfWeekIso,
            'school_days' => $school->schoolDays(),
            'days' => Timetable::DAYS,
            'periods' => $tt->periods(),
            'classroom' => $classroom?->only(['id', 'name']),
            'teacher' => $teacher?->only(['id', 'name']),
            'blocks' => ($classroom || $teacher) ? $tt->blocks($classroom, $teacher) : [],
        ]);
    }
}
