<?php

namespace App\Http\Controllers\Api;

use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Models\Classroom;
use App\Models\Student;
use App\Services\LegerService;
use App\Services\Platform\AuditLogger;
use App\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Leger rapor: master & distribusi (admin), pengisian (wali kelas), pantau baca-saja (admin). */
class LegerController extends Controller
{
    public function __construct(private LegerService $leger, private TenantContext $tenant, private AuditLogger $audit) {}

    private function canView(Request $r, Classroom $c): bool
    {
        $u = $r->user();

        return $u->role === Role::Admin || ($u->teacher && $c->homeroom_teacher_id === $u->teacher->id);
    }

    private function canEdit(Request $r, Classroom $c): bool
    {
        $u = $r->user();

        return $u->role === Role::Teacher && $u->teacher && $c->homeroom_teacher_id === $u->teacher->id && $this->leger->isActive($this->tenant->get(), $c);
    }

    public function index(Request $request): JsonResponse
    {
        $school = $this->tenant->get();
        $u = $request->user();
        $base = ['settings' => $this->leger->settings($school), 'term' => $this->leger->term($school), 'structure' => $this->leger->structure($school)];

        if ($u->role === Role::Admin) {
            return response()->json($base + ['classes' => $this->leger->classesForAdmin($school)]);
        }
        $mine = Classroom::query()->where('homeroom_teacher_id', $u->teacher?->id ?? 0)->orderBy('name')->get();

        return response()->json($base + ['classes' => $mine->map(fn ($c) => ['id' => $c->id, 'name' => $c->name,
            'active' => $this->leger->isActive($school, $c), 'distributed_at' => $this->leger->distribution($school, $c)?->distributed_at?->toIso8601String()])]);
    }

    public function saveSettings(Request $request): JsonResponse
    {
        $d = $request->validate([
            'semester' => ['required', Rule::in(['Gasal', 'Genap'])], 'year' => ['required', 'regex:/^\d{4}\/\d{4}$/'],
            'principal_name' => ['nullable', 'string', 'max:120'], 'principal_nip' => ['nullable', 'string', 'max:40'], 'city' => ['nullable', 'string', 'max:60'],
        ], ['year.regex' => 'Tahun ajaran ditulis seperti 2026/2027.'], ['year' => 'tahun ajaran']);
        $this->leger->saveSettings($this->tenant->get(), $d);

        return response()->json(['message' => 'Pengaturan leger disimpan. Periode aktif: '.$d['semester'].' '.$d['year'].'.']);
    }

    public function uploadMaster(Request $request): JsonResponse
    {
        $d = $request->validate(['file' => ['required', 'file', 'max:5120']], [], ['file' => 'master leger']);
        $st = $this->leger->uploadMaster($this->tenant->get(), $d['file']);
        $this->audit->log('leger.master', 'Master leger diganti: '.$d['file']->getClientOriginalName(), $this->tenant->get());

        return response()->json(['message' => 'Master leger diganti: '.count($st['subjects']).' mapel, '.count($st['extracurriculars']).' ekstrakurikuler.']);
    }

    public function resetMaster(): JsonResponse
    {
        $this->leger->resetMaster($this->tenant->get());

        return response()->json(['message' => 'Master kembali ke struktur bawaan.']);
    }

    public function distribute(Request $request): JsonResponse
    {
        $d = $request->validate(['classroom_id' => ['nullable', 'integer']]);
        $school = $this->tenant->get();
        if (! empty($d['classroom_id'])) {
            $c = Classroom::query()->findOrFail($d['classroom_id']);
            $this->leger->distribute($school, $c);

            return response()->json(['message' => 'Leger kelas '.$c->name.' didistribusikan ke wali kelas.']);
        }
        $n = 0;
        Classroom::query()->whereNotNull('homeroom_teacher_id')->each(function ($c) use ($school, &$n) {
            $this->leger->distribute($school, $c);
            $n++;
        });
        $this->audit->log('leger.distributed', "Leger {$this->leger->term($school)} didistribusikan ke $n wali kelas", $school);

        return response()->json(['message' => "Leger didistribusikan ke $n wali kelas."]);
    }

    public function withdraw(Classroom $classroom): JsonResponse
    {
        $this->leger->withdraw($this->tenant->get(), $classroom);

        return response()->json(['message' => 'Leger kelas '.$classroom->name.' ditarik.']);
    }

    public function sheet(Request $request, Classroom $classroom): JsonResponse
    {
        abort_unless($this->canView($request, $classroom), 403, 'Leger hanya untuk wali kelas ini atau admin.');
        $school = $this->tenant->get();
        $active = $this->leger->isActive($school, $classroom);
        abort_if($request->user()->role === Role::Teacher && ! $active, 403, 'Leger kelas ini belum didistribusikan admin.');

        return response()->json($this->leger->sheet($school, $classroom) + [
            'classroom' => ['id' => $classroom->id, 'name' => $classroom->name, 'phase' => $this->leger->phase($classroom)],
            'school' => ['name' => $school->name, 'address' => $school->address],
            'homeroom' => $classroom->homeroomTeacher?->name, 'editable' => $this->canEdit($request, $classroom), 'active' => $active,
        ]);
    }

    public function saveEntry(Request $request, Classroom $classroom, Student $student): JsonResponse
    {
        abort_unless($this->canEdit($request, $classroom), 403, 'Hanya wali kelas pada leger yang sudah didistribusikan.');
        $d = $request->validate(['scores' => ['sometimes', 'array'], 'competencies' => ['sometimes', 'array'], 'extras' => ['sometimes', 'array'], 'absences' => ['sometimes', 'array']]);
        $this->leger->saveEntry($this->tenant->get(), $classroom, $student, $d, $request->user()->id);
        $row = collect($this->leger->sheet($this->tenant->get(), $classroom)['rows']);

        return response()->json(['message' => 'Tersimpan.', 'ranks' => $row->map(fn ($r) => ['id' => $r['student']['id'], 'average' => $r['average'], 'sum' => $r['sum'], 'rank' => $r['rank']])]);
    }

    public function applyCompetency(Request $request, Classroom $classroom): JsonResponse
    {
        abort_unless($this->canEdit($request, $classroom), 403);
        $d = $request->validate(['subject' => ['required', 'integer', 'min:0']]);
        $n = $this->leger->applyCompetency($this->tenant->get(), $classroom, $d['subject'], $request->user()->id);

        return response()->json(['message' => "Teks siswa pertama diterapkan ke $n siswa."]);
    }
}
