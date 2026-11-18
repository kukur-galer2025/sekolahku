<?php

namespace App\Http\Controllers\Api;

use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Models\Teacher;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class TeacherController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $q = trim((string) $request->query('q'));

        $list = Teacher::query()
            ->when($q, fn ($b) => $b->where(fn ($w) => $w->where('name', 'like', "%$q%")->orWhere('nik', 'like', "%$q%")->orWhere('subject', 'like', "%$q%")))
            ->orderBy('name')->get();
        $logins = \App\Models\User::query()->whereIn('id', $list->pluck('user_id')->filter())->get(['id', 'last_login_at', 'must_change_password', 'photo_path'])->keyBy('id');

        // Status akun untuk kartu login: none (belum punya akun) / belum (belum pernah masuk) / sementara (password sementara) / aktif.
        return response()->json($list->map(fn ($t) => $t->toArray() + [
            'account' => ! ($l = $logins->get($t->user_id)) ? 'none' : (! $l->last_login_at ? 'belum' : ($l->must_change_password ? 'sementara' : 'aktif')),
            'photo_url' => $l ? \App\Support\Avatar::url($l->id, $l->photo_path) : null,
        ])->values());
    }

    /** Membuat guru beserta akunnya. Password awal dikembalikan SEKALI untuk dibagikan admin. */
    public function store(Request $request, TenantContext $tenant): JsonResponse
    {
        $data = $request->validate([
            'nik' => ['required', 'string', 'max:32', Rule::unique('teachers')->where('school_id', $tenant->id())],
            'name' => ['required', 'string', 'max:255'],
            'subject' => ['nullable', 'string', 'max:255'],
            'position' => ['nullable', 'string', 'max:255'],
            'email' => ['nullable', 'email'],
            'phone' => ['nullable', 'string', 'max:20'],
        ]);

        $nik = preg_replace('/\s+/', '', $data['nik']);
        $initial = Str::password(10, symbols: false);

        $teacher = DB::transaction(function () use ($data, $nik, $initial) {
            $user = User::create([
                'role' => Role::Teacher, 'username' => $nik, 'name' => $data['name'],
                'email' => $data['email'] ?? null, 'phone' => $data['phone'] ?? null,
                'password' => $initial, 'must_change_password' => true,
            ]);

            return Teacher::create(['user_id' => $user->id, 'nik' => $nik] + collect($data)->only(['name', 'subject', 'position'])->all());
        });

        return response()->json(['teacher' => $teacher, 'initial_password' => $initial], 201);
    }

    public function update(Request $request, Teacher $teacher): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'subject' => ['nullable', 'string', 'max:255'],
            'position' => ['nullable', 'string', 'max:255'],
        ]);

        $teacher->update($data);
        $teacher->user->update(['name' => $data['name']]);

        return response()->json($teacher);
    }

    public function destroy(Teacher $teacher): JsonResponse
    {
        $teacher->user->delete(); // cascade ke baris guru

        return response()->json(null, 204);
    }
}
