<?php

namespace App\Http\Controllers\Api;

use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Models\Attendance;
use App\Models\Classroom;
use App\Models\Student;
use App\Models\UserDevice;
use App\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Wali kelas: guru melihat kelas perwaliannya; admin menetapkan wali & jabatan pengurus. */
class HomeroomController extends Controller
{
    public function index(Request $request, TenantContext $tenant): JsonResponse
    {
        $u = $request->user();
        $today = $tenant->get()->localNow()->toDateString();
        $q = Classroom::query()->with('homeroomTeacher:id,name')->orderBy('name');
        if ($u->role === Role::Teacher) {
            $q->where('homeroom_teacher_id', $u->teacher?->id ?? 0);
        } elseif ($request->integer('classroom_id')) {
            $q->whereKey($request->integer('classroom_id'));
        } else {
            // Admin tanpa filter: daftar kelas + wali saja (tanpa siswa), untuk penetapan wali.
            return response()->json(['classrooms' => $q->withCount(['students' => fn ($b) => $b->where('is_active', true)])->get()
                ->map(fn ($c) => ['id' => $c->id, 'name' => $c->name, 'students' => $c->students_count, 'homeroom' => $c->homeroomTeacher?->only(['id', 'name'])])]);
        }

        return response()->json(['classrooms' => $q->get()->map(function (Classroom $c) use ($today) {
            $students = $c->students()->where('is_active', true)->orderBy('name')->get();
            $att = Attendance::query()->whereIn('student_id', $students->pluck('id'))->whereDate('date', $today)->get()->keyBy('student_id');
            $devices = UserDevice::query()->whereIn('user_id', $students->pluck('user_id'))->whereNull('revoked_at')->selectRaw('user_id, count(*) n')->groupBy('user_id')->pluck('n', 'user_id');
            $logins = \App\Models\User::query()->whereIn('id', $students->pluck('user_id'))->get(['id', 'last_login_at', 'must_change_password', 'photo_path'])->keyBy('id');

            return [
                'id' => $c->id, 'name' => $c->name, 'homeroom' => $c->homeroomTeacher?->only(['id', 'name']),
                'summary' => collect(['H', 'S', 'I', 'A'])->mapWithKeys(fn ($s) => [$s => $att->filter(fn ($a) => $a->status->value === $s)->count()])->all()
                    + ['belum' => $students->count() - $att->count(), 'total' => $students->count()],
                'students' => $students->map(fn (Student $s) => [
                    'id' => $s->id, 'user_id' => $s->user_id, 'nis' => $s->nis, 'name' => $s->name, 'class_role' => $s->class_role,
                    'devices' => (int) ($devices[$s->user_id] ?? 0),
                    // Status akun untuk kartu login: belum pernah masuk / masih memakai password sementara / aktif.
                    'account' => ! ($l = $logins->get($s->user_id)) ? 'none' : (! $l->last_login_at ? 'belum' : ($l->must_change_password ? 'sementara' : 'aktif')),
                    'photo_url' => $l ? \App\Support\Avatar::url($l->id, $l->photo_path) : null,
                    'attendance' => ($a = $att->get($s->id)) ? ['status' => $a->status->value, 'label' => $a->status->label(), 'time' => $a->scanned_at?->setTimezone(app(TenantContext::class)->get()->timezone)->format('H:i')] : null,
                ]),
            ];
        }), 'class_roles' => Student::CLASS_ROLES, 'core_roles' => Student::CORE_ROLES]);
    }

    /** Jabatan pengurus kelas: admin, atau wali kelas untuk siswa perwaliannya. */
    public function setRole(Request $request, Student $student): JsonResponse
    {
        $u = $request->user();
        abort_unless($u->role === Role::Admin || ($student->classroom && $student->classroom->homeroom_teacher_id === ($u->teacher?->id ?? -1)), 403, 'Hanya wali kelas siswa ini.');
        $d = $request->validate(['class_role' => ['nullable', Rule::in(array_keys(Student::CLASS_ROLES))]]);
        if (! empty($d['class_role'])) {
            // Satu jabatan satu orang per kelas.
            Student::query()->where('classroom_id', $student->classroom_id)->where('class_role', $d['class_role'])->where('id', '!=', $student->id)->update(['class_role' => null]);
        }
        $before = $student->class_role;
        $student->forceFill(['class_role' => $d['class_role'] ?? null])->save();
        if (! empty($d['class_role']) && $d['class_role'] !== $before && $student->user) {
            $bendahara = str_starts_with($d['class_role'], 'bendahara');
            rescue(fn () => app(\App\Services\Notify\Notifier::class)->send([$student->user], 'class_role', 'Kamu ditetapkan sebagai '.Student::CLASS_ROLES[$d['class_role']],
                'Kelas '.($student->classroom?->name ?? '').' · ditetapkan wali kelas.'.($bendahara ? ' Kamu mencatat Kas Kelas.' : ''), $bendahara ? '#/kas' : '#/struktur'));
        }

        return response()->json(['message' => 'Jabatan diperbarui.']);
    }
}
