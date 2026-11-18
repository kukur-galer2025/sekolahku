<?php

namespace App\Http\Controllers\Api;

use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Models\Classroom;
use App\Models\Student;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class StudentController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $q = trim((string) $request->query('q'));

        $page = Student::query()->with('classroom:id,name')
                ->when($request->integer('classroom_id'), fn ($b, $id) => $b->where('classroom_id', $id))
                // Bawaan hanya siswa aktif; lulus/mutasi dilihat dengan ?status=lulus atau ?status=semua.
                ->when(($st = $request->query('status', 'aktif')) !== 'semua', fn ($b) => $b->where('status', array_key_exists($st, Student::STATUSES) ? $st : 'aktif'))
                ->when($q, fn ($b) => $b->where(fn ($w) => $w->where('name', 'like', "%$q%")->orWhere('nis', 'like', "%$q%")))
                ->orderBy('name')->paginate(100);
        $av = \App\Support\Avatar::map($page->pluck('user_id'));

        return response()->json($page->through(fn ($s) => $s->toArray() + ['photo_url' => $av[$s->user_id] ?? null]));
    }

    /** Membuat siswa beserta akunnya. Password awal acak (dikembalikan sekali), wajib diganti saat login pertama. */
    public function store(Request $request, TenantContext $tenant): JsonResponse
    {
        $school = $tenant->get();
        $data = $this->validated($request, $tenant);

        // Paket per jumlah siswa: tolak bila kuota habis.
        $quota = $school->effectiveStudentQuota();
        if ($quota !== null && Student::query()->where('is_active', true)->count() >= $quota) {
            return response()->json(['message' => $school->isVerified()
                ? "Kuota paket sudah penuh ({$quota} siswa). Tingkatkan paket untuk menambah siswa."
                : "Sekolah belum terverifikasi: maksimal {$quota} siswa. Lengkapi NPSN di Profil Sekolah dan tunggu verifikasi Parama Tech."], 422);
        }

        $initial = strtolower(\Illuminate\Support\Str::password(8, symbols: false));

        $student = DB::transaction(function () use ($data, $initial) {
            $user = User::create([
                'role' => Role::Student, 'username' => $data['nis'], 'name' => $data['name'],
                'password' => $initial, 'must_change_password' => true,
            ]);

            return Student::create(['user_id' => $user->id] + $data);
        });

        return response()->json($student->load('classroom:id,name')->toArray() + ['initial_password' => $initial], 201);
    }

    public function update(Request $request, TenantContext $tenant, Student $student): JsonResponse
    {
        $data = $this->validated($request, $tenant, $student);
        $student->update($data);
        $student->user->update(['name' => $data['name'], 'username' => $data['nis']]);

        return response()->json($student->load('classroom:id,name'));
    }

    /** Riwayat kelas per tahun ajaran (terbaru dulu). */
    public function history(Student $student): JsonResponse
    {
        return response()->json($student->enrollments()->orderByDesc('academic_year')
            ->get(['academic_year', 'classroom_name', 'grade', 'status']));
    }

    public function destroy(Student $student): JsonResponse
    {
        $student->user->delete();

        return response()->json(null, 204);
    }

    private function validated(Request $request, TenantContext $tenant, ?Student $s = null): array
    {
        $request->merge(['nis' => preg_replace('/\s+/', '', (string) $request->input('nis'))]);

        $data = $request->validate([
            'nis' => ['required', 'string', 'max:32', Rule::unique('students')->where('school_id', $tenant->id())->ignore($s?->id)],
            'nisn' => ['nullable', 'string', 'max:32'],
            'name' => ['required', 'string', 'max:255'],
            'classroom_id' => ['nullable', 'integer'],
            'birth_date' => ['nullable', 'date_format:Y-m-d'],
            'class_role' => ['nullable', Rule::in(array_keys(Student::CLASS_ROLES))],
        ]);

        if (! empty($data['classroom_id']) && ! Classroom::find($data['classroom_id'])) {
            abort(422, 'Kelas tidak ditemukan di sekolah ini.');
        }

        return $data;
    }
}
