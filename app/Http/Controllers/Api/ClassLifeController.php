<?php

namespace App\Http\Controllers\Api;

use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Models\Classroom;
use App\Models\ClassNote;
use App\Models\Student;
use App\Services\DutyRosterService;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantFiles;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Kehidupan kelas (alur prototipe):
 * - Struktur Kelas : wali kelas, pengurus inti (ketua, wakil, sekretaris 1-2, bendahara 1-2), seksi-seksi, anggota.
 * - Jadwal Piket   : disusun wali kelas (acak / impor Excel / ubah), dilihat siswa.
 * - Jurnal Kelas   : catatan kegiatan kelas yang diisi pengurus kelas; dibaca seluruh kelas, wali, guru, admin.
 */
class ClassLifeController extends Controller
{
    public const ROLES = Student::CLASS_ROLES;

    public function __construct(private TenantContext $tenant, private DutyRosterService $roster) {}

    private function myStudent(Request $request): Student
    {
        $s = $request->user()->student;
        abort_unless($s && $s->classroom_id, 404, 'Kamu belum terdaftar di kelas mana pun.');

        return $s;
    }

    /** Wali kelas kelas ini, atau admin. */
    private function authorizeHomeroom(Request $request, Classroom $c): void
    {
        $u = $request->user();
        abort_unless($u->role === Role::Admin || ($u->teacher && $c->homeroom_teacher_id === $u->teacher->id), 403, 'Hanya wali kelas ini atau admin.');
    }

    // ── Siswa ────────────────────────────────────────────────────────

    public function myClass(Request $request, TenantFiles $files): JsonResponse
    {
        $me = $this->myStudent($request);
        $c = Classroom::query()->with('homeroomTeacher')->findOrFail($me->classroom_id);
        $members = $this->roster->students($c);
        $full = Student::query()->whereIn('id', $members->pluck('id'))->get(['id', 'class_role', 'user_id'])->keyBy('id');
        $av = \App\Support\Avatar::map($full->pluck('user_id'));
        $photo = fn ($s) => $s ? ($av[$full[$s->id]->user_id ?? 0] ?? null) : null;
        $v = $this->roster->view($this->tenant->get(), $c);
        $myDays = collect($v['grid'])->filter(fn ($L) => collect($L)->contains('id', $me->id))->keys()->map(fn ($d) => $v['days'][$d])->values();

        $w = $c->homeroomTeacher;

        return response()->json([
            'classroom' => $c->only(['id', 'name']),
            'homeroom' => $w ? ['name' => $w->name, 'subject' => $w->subject, 'photo_url' => \App\Support\Avatar::of($w->user)] : null,
            'officers' => collect(self::ROLES)->map(fn ($label, $key) => ['role' => $key, 'label' => $label, 'group' => in_array($key, Student::CORE_ROLES, true) ? 'inti' : 'seksi',
                'name' => ($o = $members->first(fn ($s) => $full[$s->id]->class_role === $key))?->name, 'is_me' => $o?->id === $me->id, 'photo_url' => $photo($o)])->values(),
            'members' => $members->map(fn ($s) => ['name' => $s->name, 'role' => self::ROLES[$full[$s->id]->class_role ?? ''] ?? 'Anggota', 'is_me' => $s->id === $me->id, 'photo_url' => $photo($s)])->values(),
            'my_role' => self::ROLES[$me->class_role ?? ''] ?? 'Anggota',
            'can_write_notes' => in_array($me->class_role, Student::CORE_ROLES, true),
            'roster' => $v + ['mine' => $myDays, 'today' => $this->tenant->get()->localNow()->dayOfWeekIso],
        ]);
    }

    // ── Jadwal piket (wali kelas / admin) ────────────────────────────

    public function roster(Request $request, Classroom $classroom): JsonResponse
    {
        $this->authorizeHomeroom($request, $classroom);

        return response()->json($this->roster->view($this->tenant->get(), $classroom) + [
            'students' => $this->roster->students($classroom)->map(fn ($s) => $s->only(['id', 'nis', 'name'])),
        ]);
    }

    public function saveRoster(Request $request, Classroom $classroom): JsonResponse
    {
        $this->authorizeHomeroom($request, $classroom);
        $d = $request->validate(['grid' => ['present', 'array'], 'grid.*' => ['array'], 'grid.*.*' => ['integer']]);
        $this->roster->save($this->tenant->get(), $classroom, $d['grid'], 'Diubah manual.', $request->user()->id);

        return response()->json(['message' => 'Jadwal piket disimpan.']);
    }

    public function randomRoster(Request $request, Classroom $classroom): JsonResponse
    {
        $this->authorizeHomeroom($request, $classroom);
        $n = $this->roster->random($this->tenant->get(), $classroom, $request->user()->id);

        return response()->json(['message' => "Jadwal piket disusun acak dari $n siswa."]);
    }

    public function importRoster(Request $request, Classroom $classroom): JsonResponse
    {
        $this->authorizeHomeroom($request, $classroom);
        $d = $request->validate(['file' => ['required', 'file', 'max:10240']], [], ['file' => 'file Excel']);
        $ext = strtolower($d['file']->getClientOriginalExtension());
        abort_unless(in_array($ext, ['xlsx', 'csv'], true), 422, 'Pilih file Excel .xlsx atau CSV.');
        $r = $this->roster->import($this->tenant->get(), $classroom, $d['file']->getRealPath(), $ext, $request->user()->id);

        return response()->json(['message' => $r['random'] ? 'Format masih kosong: jadwal piket diisi acak dari daftar siswa.'
            : 'Jadwal piket diimpor: '.$r['placed'].' petugas'.($r['missing'] ? ', '.count($r['missing']).' nama tidak cocok dan dilewati.' : '.'), 'missing' => $r['missing']]);
    }

    public function exportRoster(Request $request, Classroom $classroom): BinaryFileResponse
    {
        $this->authorizeHomeroom($request, $classroom);

        return response()->download($this->roster->export($this->tenant->get(), $classroom), 'JADWAL_PIKET_'.preg_replace('/\W+/', '_', $classroom->name).'.xlsx')->deleteFileAfterSend();
    }

    // ── Jurnal kelas oleh pengurus ───────────────────────────────────

    public function notes(Request $request): JsonResponse
    {
        $u = $request->user();
        $classroomId = $u->role === Role::Student ? $this->myStudent($request)->classroom_id : $request->validate(['classroom_id' => ['required', 'integer']])['classroom_id'];
        Classroom::query()->findOrFail($classroomId);

        return response()->json(ClassNote::query()->where('classroom_id', $classroomId)->latest('date')->latest('id')->limit(200)->get()
            ->map(fn ($n) => ['id' => $n->id, 'date' => $n->date->format('Y-m-d'), 'author' => $n->author_name, 'role' => $n->author_role, 'note' => $n->note]));
    }

    public function storeNote(Request $request): JsonResponse
    {
        $me = $this->myStudent($request);
        abort_unless(in_array($me->class_role, Student::CORE_ROLES, true), 403, 'Jurnal kelas hanya bisa diisi pengurus inti kelas (ketua, wakil, sekretaris, bendahara).');
        $today = $this->tenant->get()->localNow()->toDateString();
        $d = $request->validate(['date' => ['required', 'date_format:Y-m-d', 'before_or_equal:'.$today], 'note' => ['required', 'string', 'max:1500']], [], ['note' => 'catatan', 'date' => 'tanggal']);
        ClassNote::create(['classroom_id' => $me->classroom_id, 'student_id' => $me->id, 'author_name' => $me->name,
            'author_role' => self::ROLES[$me->class_role], 'date' => $d['date'], 'note' => trim($d['note'])]);

        return response()->json(['message' => 'Jurnal kelas disimpan.'], 201);
    }
}
