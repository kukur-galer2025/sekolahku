<?php

namespace App\Http\Controllers\Api;

use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Models\Classroom;
use App\Models\Student;
use App\Models\StudentProfile;
use App\Models\User;
use App\Services\LegerService;
use App\Services\Platform\AuditLogger;
use App\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Biodata peserta didik (format "Keterangan tentang diri peserta didik" buku rapor).
 * Diisi siswa di Profil, bisa dilengkapi/diperbaiki admin & wali kelas, dan dicetak sebagai halaman identitas rapor.
 */
class StudentProfileController extends Controller
{
    public function __construct(private TenantContext $tenant) {}

    /** Siswa: dirinya sendiri. Admin: semua. Guru: hanya siswa di kelas perwaliannya. */
    private function authorizeFor(User $u, Student $s): void
    {
        $ok = match ($u->role) {
            Role::Admin => true,
            Role::Student => $u->student?->id === $s->id,
            Role::Teacher => $u->teacher && $s->classroom_id && Classroom::query()->whereKey($s->classroom_id)->where('homeroom_teacher_id', $u->teacher->id)->exists(),
            default => false,
        };
        abort_unless($ok, 403, 'Biodata siswa ini hanya bisa dibuka siswa itu sendiri, wali kelasnya, atau admin.');
    }

    private function present(Student $s, User $viewer): array
    {
        $p = $s->profile;

        return [
            'student' => ['id' => $s->id, 'name' => $s->name, 'nis' => $s->nis, 'nisn' => $s->nisn, 'gender' => $s->gender,
                'birth_date' => $s->birth_date?->format('Y-m-d'), 'classroom' => $s->classroom?->name, 'classroom_id' => $s->classroom_id],
            'profile' => collect(StudentProfile::KEYS)->mapWithKeys(fn ($k) => [$k => $k === 'accepted_date' ? $p?->accepted_date?->format('Y-m-d') : $p?->{$k}]),
            'fields' => collect(StudentProfile::FIELDS)->map(fn ($f) => ['label' => $f[0], 'max' => $f[1]]),
            'updated_at' => $p?->updated_at?->toIso8601String(), 'updated_by' => $p?->editor?->name,
            'can_edit_identity' => $viewer->role !== Role::Student, // nama, NIS, NISN diubah admin/wali
            'print_url' => '/biodata/cetak?siswa='.$s->id,
        ];
    }

    public function mine(Request $request): JsonResponse
    {
        $s = $request->user()->student ?? abort(403, 'Akun ini belum terhubung dengan data siswa.');

        return response()->json($this->present($s->load(['profile.editor', 'classroom']), $request->user()));
    }

    public function show(Request $request, Student $student): JsonResponse
    {
        $this->authorizeFor($request->user(), $student);

        return response()->json($this->present($student->load(['profile.editor', 'classroom']), $request->user()));
    }

    public function updateMine(Request $request, AuditLogger $audit): JsonResponse
    {
        $s = $request->user()->student ?? abort(403, 'Akun ini belum terhubung dengan data siswa.');

        return $this->save($request, $s, $audit);
    }

    public function update(Request $request, Student $student, AuditLogger $audit): JsonResponse
    {
        $this->authorizeFor($request->user(), $student);

        return $this->save($request, $student, $audit);
    }

    private function save(Request $request, Student $s, AuditLogger $audit): JsonResponse
    {
        $u = $request->user();
        $rules = collect(StudentProfile::FIELDS)->map(fn ($f, $k) => $k === 'accepted_date' ? ['nullable', 'date_format:Y-m-d'] : ['nullable', 'string', 'max:'.$f[1]])->all();
        $rules['birth_date'] = ['nullable', 'date_format:Y-m-d', 'before:today'];
        $rules['gender'] = ['nullable', Rule::in(['L', 'P'])];
        if ($u->role !== Role::Student) {
            $rules['name'] = ['sometimes', 'required', 'string', 'max:120'];
            $rules['nisn'] = ['nullable', 'string', 'max:20'];
        }
        $d = $request->validate($rules, ['birth_date.before' => 'Tanggal lahir tidak valid.'],
            collect(StudentProfile::FIELDS)->map(fn ($f) => strtolower($f[0]))->all() + ['birth_date' => 'tanggal lahir', 'gender' => 'jenis kelamin']);

        $s->profile()->updateOrCreate([], collect($d)->only(StudentProfile::KEYS)->map(fn ($v) => is_string($v) ? trim($v) ?: null : $v)->all() + ['updated_by' => $u->id]);
        $s->fill(collect($d)->only(array_merge(['birth_date', 'gender'], $u->role === Role::Student ? [] : ['name', 'nisn']))->all())->save();
        if ($u->role !== Role::Student) {
            $audit->log('student.biodata', 'Biodata siswa diperbarui: '.$s->name, $this->tenant->get(), $u);
        }

        return response()->json(['message' => 'Biodata tersimpan.'] + $this->present($s->fresh(['profile.editor', 'classroom']), $u));
    }

    /** Halaman cetak: satu siswa (?siswa=) atau satu kelas (?kelas=, admin/wali). */
    public function print(Request $request, LegerService $leger): View
    {
        $u = $request->user();
        $school = $this->tenant->get();
        if ($cid = $request->integer('kelas')) {
            abort_if($u->role === Role::Student, 403);
            $classroom = Classroom::query()->findOrFail($cid);
            abort_unless($u->role === Role::Admin || ($u->teacher && $classroom->homeroom_teacher_id === $u->teacher->id), 403);
            $students = Student::query()->with(['profile', 'classroom'])->where('classroom_id', $cid)->where('is_active', true)->orderBy('name')->get();
        } else {
            $s = $u->role === Role::Student ? ($u->student ?? abort(403)) : Student::query()->findOrFail($request->integer('siswa'));
            $this->authorizeFor($u, $s);
            $students = collect([$s->load(['profile', 'classroom'])]);
        }
        $set = $leger->settings($school);

        return view('pwa.biodata', [
            'school' => $school, 'students' => $students, 'set' => $set,
            'date' => $school->localNow()->locale('id')->translatedFormat('j F Y'),
        ]);
    }
}
