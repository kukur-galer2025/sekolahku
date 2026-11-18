<?php

namespace App\Http\Controllers\Api;

use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\UserDevice;
use App\Services\DeviceBinding;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Lihat dan reset perangkat terdaftar sebuah akun.
 * - Admin: semua akun di sekolahnya.
 * - Guru: hanya siswa di kelas yang ia ampu sebagai wali kelas.
 */
class DeviceController extends Controller
{
    public function __construct(private DeviceBinding $devices) {}

    public function index(Request $request, User $user): JsonResponse
    {
        $this->authorizeManage($request->user(), $user);

        return response()->json([
            'user' => $user->only(['id', 'name', 'username', 'role']),
            'limit' => $this->devices->limitFor($request->user()->school, $user),
            'devices' => UserDevice::query()->where('user_id', $user->id)->latest()->get()
                ->map(fn (UserDevice $d) => [
                    'id' => $d->id, 'name' => $d->name, 'ip' => $d->ip,
                    'registered_at' => $d->created_at?->toIso8601String(),
                    'last_seen_at' => $d->last_seen_at?->toIso8601String(),
                    'active' => $d->revoked_at === null,
                ]),
        ]);
    }

    /** "Reset perangkat": akun bisa login lagi dari HP baru. */
    public function reset(Request $request, User $user): JsonResponse
    {
        $this->authorizeManage($request->user(), $user);
        $n = $this->devices->revokeAll($user, $request->user());

        return response()->json(['message' => "Perangkat {$user->name} direset ({$n}). Akun bisa masuk dari HP baru.", 'revoked' => $n]);
    }

    private function authorizeManage(User $actor, User $target): void
    {
        if ($actor->role === Role::Admin) {
            return;
        }

        $isHomeroom = $actor->role === Role::Teacher
            && $target->role === Role::Student
            && $target->student?->classroom?->homeroom_teacher_id === ($actor->teacher?->id ?? -1)
            && $actor->teacher !== null;

        abort_unless($isHomeroom, 403, 'Hanya admin atau wali kelas siswa ini yang bisa mengelola perangkatnya.');
    }
}
