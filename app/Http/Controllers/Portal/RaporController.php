<?php

namespace App\Http\Controllers\Portal;

use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Models\Classroom;
use App\Services\LegerService;
use App\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** RAPORT siap cetak (satu siswa atau semua siswa satu kelas), dari leger wali kelas. */
class RaporController extends Controller
{
    public function __invoke(Request $request, Classroom $classroom, LegerService $leger, TenantContext $tenant): View
    {
        $u = $request->user();
        abort_unless($u->role === Role::Admin || ($u->teacher && $classroom->homeroom_teacher_id === $u->teacher->id), 403);
        $school = $tenant->get();
        $sheet = $leger->sheet($school, $classroom);
        $rows = collect($sheet['rows']);
        if ($id = $request->integer('siswa')) {
            $rows = $rows->where('student.id', $id)->values();
            abort_if($rows->isEmpty(), 404);
        }

        return view('pwa.rapor', [
            'school' => $school, 'classroom' => $classroom, 'phase' => $leger->phase($classroom), 'sheet' => $sheet, 'rows' => $rows,
            'homeroom' => $classroom->homeroomTeacher?->name, 'date' => $school->localNow()->locale('id')->translatedFormat('j F Y'),
        ]);
    }
}
