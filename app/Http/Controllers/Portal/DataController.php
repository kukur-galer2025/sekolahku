<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Models\Classroom;
use App\Models\Student;
use App\Models\Teacher;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** Halaman Data Guru, Data Siswa, dan Kelas (admin). */
class DataController extends Controller
{
    public function teachers(Request $request): View
    {
        $q = trim((string) $request->query('q'));

        $status = $request->query('status', 'aktif');
        $teachers = Teacher::query()->with(['user:id,last_login_at', 'homeroomClassrooms:id,name,homeroom_teacher_id'])
            ->when(array_key_exists($status, Teacher::STATUSES), fn ($b) => $b->where('status', $status))
            ->when($q, fn ($b) => $b->where(fn ($w) => $w->where('name', 'like', "%$q%")->orWhere('nik', 'like', "%$q%")->orWhere('subject', 'like', "%$q%")))
            ->orderBy('name')->paginate(50)->withQueryString();

        return view('portal.teachers', compact('teachers', 'q', 'status'));
    }

    public function students(Request $request): View
    {
        $q = trim((string) $request->query('q'));
        $classroomId = $request->integer('kelas') ?: null;
        $status = $request->query('status', 'aktif');

        $students = Student::query()->with(['classroom:id,name', 'user:id,last_login_at'])
            ->when($classroomId, fn ($b) => $b->where('classroom_id', $classroomId))
            ->when(array_key_exists($status, Student::STATUSES), fn ($b) => $b->where('status', $status))
            ->when($q, fn ($b) => $b->where(fn ($w) => $w->where('name', 'like', "%$q%")->orWhere('nis', 'like', "%$q%")->orWhere('nisn', 'like', "%$q%")))
            ->orderBy('classroom_id')->orderBy('name')->paginate(50)->withQueryString();

        return view('portal.students', [
            'students' => $students, 'q' => $q, 'classroomId' => $classroomId, 'status' => $status,
            'classrooms' => Classroom::orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function classrooms(\App\Tenancy\TenantContext $tenant): View
    {
        $school = $tenant->get();
        $order = array_flip($school->grades());

        return view('portal.classrooms', [
            'school' => $school, 'grades' => $school->grades(),
            'classrooms' => Classroom::query()->with('homeroomTeacher:id,name')
                ->withCount(['students' => fn ($q) => $q->where('is_active', true), 'schedules'])
                ->get()->sortBy([fn ($a, $b) => ($order[$a->grade] ?? 99) <=> ($order[$b->grade] ?? 99), ['name', 'asc']])->values(),
        ]);
    }

    /** Tambah satu kelas: tingkat sesuai jenjang + nama rombel. */
    public function storeClassroom(Request $request, \App\Tenancy\TenantContext $tenant, \App\Services\Platform\AuditLogger $audit): \Illuminate\Http\RedirectResponse
    {
        $school = $tenant->get();
        $d = $request->validate([
            'grade' => ['required', \Illuminate\Validation\Rule::in($school->grades())],
            'name' => ['required', 'string', 'max:64', \Illuminate\Validation\Rule::unique('classrooms')->where('school_id', $school->id)],
        ], ['name.unique' => 'Nama kelas ini sudah ada.'], ['grade' => 'tingkat', 'name' => 'nama kelas']);

        $c = Classroom::create(['name' => trim($d['name']), 'grade' => $d['grade']]);
        $audit->log('classroom.created', "Kelas {$c->name} (tingkat {$c->grade}) dibuat", $school);

        return back()->with('ok', "Kelas {$c->name} ditambahkan.");
    }

    /**
     * Buat kelas sekaligus untuk semua tingkat: mis. 3 rombel huruf → 7A–7C, 8A–8C, 9A–9C (SMP)
     * atau X-1…XII-3 / X-FKK 1… dengan jurusan (SMA/SMK). Nama yang sudah ada dilewati.
     */
    public function bulkClassrooms(Request $request, \App\Tenancy\TenantContext $tenant, \App\Services\Platform\AuditLogger $audit): \Illuminate\Http\RedirectResponse
    {
        $school = $tenant->get();
        $d = $request->validate([
            'count' => ['required', 'integer', 'min:1', 'max:20'],
            'style' => ['required', \Illuminate\Validation\Rule::in(['letter', 'number'])],
            'program' => ['nullable', 'string', 'max:24', 'regex:/^[A-Za-z0-9 ]+$/'],
            'grades' => ['required', 'array', 'min:1'],
            'grades.*' => [\Illuminate\Validation\Rule::in($school->grades())],
        ], ['program.regex' => 'Jurusan hanya huruf, angka, dan spasi.'], ['count' => 'jumlah rombel', 'grades' => 'tingkat']);

        $existing = Classroom::query()->pluck('name')->map(fn ($n) => strtoupper($n))->all();
        $program = strtoupper(trim((string) ($d['program'] ?? '')));
        $made = [];
        foreach ($school->grades() as $g) {
            if (! in_array($g, $d['grades'], true)) {
                continue;
            }
            for ($i = 1; $i <= $d['count']; $i++) {
                $tag = $d['style'] === 'letter' ? chr(64 + $i) : (string) $i;
                $name = match (true) {
                    $program !== '' => "$g-$program $i",
                    ctype_digit($g) && $d['style'] === 'letter' => $g.$tag,   // 7A
                    default => "$g-$tag",                                     // X-A, X-1, 7-1
                };
                if (in_array(strtoupper($name), $existing, true)) {
                    continue;
                }
                Classroom::create(['name' => $name, 'grade' => $g]);
                $existing[] = strtoupper($name);
                $made[] = $name;
            }
        }

        if ($made) {
            $audit->log('classroom.bulk', count($made).' kelas dibuat otomatis: '.implode(', ', $made), $school);
        }

        return back()->with('ok', $made ? count($made).' kelas dibuat: '.implode(', ', $made).'.' : 'Tidak ada kelas baru: semua nama sudah ada.');
    }

    /** Hapus kelas yang belum dipakai (tanpa siswa dan tanpa jadwal). */
    public function destroyClassroom(Classroom $classroom, \App\Services\Platform\AuditLogger $audit, \App\Tenancy\TenantContext $tenant): \Illuminate\Http\RedirectResponse
    {
        if ($classroom->students()->exists() || $classroom->schedules()->exists()) {
            return back()->withErrors(['kelas' => "Kelas {$classroom->name} masih punya siswa atau jadwal, jadi tidak bisa dihapus."]);
        }
        $name = $classroom->name;
        $classroom->delete();
        $audit->log('classroom.deleted', "Kelas $name dihapus", $tenant->get());

        return back()->with('ok', "Kelas $name dihapus.");
    }
}
