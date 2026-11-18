<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\LoginService;
use App\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\Password;

class AuthController extends Controller
{
    /** Identitas sekolah untuk halaman login (tanpa login). */
    public function school(TenantContext $tenant): JsonResponse
    {
        $s = $tenant->get();

        return response()->json([
            'name' => $s->name,
            'subdomain' => $s->subdomain,
            'logo_url' => app(\App\Tenancy\TenantFiles::class)->url($s->logo_path, 60),
            'timezone' => $s->timezone,
            'verified' => $s->isVerified(),
        ]);
    }

    /**
     * Satu pintu login untuk admin, guru, dan siswa.
     * - Admin: username + password
     * - Guru: NIK + password
     * - Siswa: NIS + password (awal = acak dari daftar akun hasil impor, wajib diganti setelah masuk)
     */
    public function login(Request $request, LoginService $login): JsonResponse
    {
        $data = $request->validate([
            'username' => ['required', 'string', 'max:64'],
            'password' => ['required', 'string', 'max:255'],
            'app' => ['nullable', 'string', \Illuminate\Validation\Rule::in(array_keys(\App\Http\Controllers\Portal\PwaController::APPS))],
        ]);
        $roles = ! empty($data['app']) ? \App\Http\Controllers\Portal\PwaController::APPS[$data['app']]['roles'] : null;

        try {
            $user = $login->attempt($request, $data['username'], $data['password'], $roles);
        } catch (\App\Services\TwoFactorRequired) {
            return response()->json(['two_factor' => true]);
        }

        return response()->json(['user' => $this->present($user)]);
    }

    /** Langkah kedua login admin: kode dari aplikasi authenticator. */
    public function twoFactor(Request $request, LoginService $login): JsonResponse
    {
        $data = $request->validate(['code' => ['required', 'string', 'max:20']], [], ['code' => 'kode']);

        return response()->json(['user' => $this->present($login->completeTwoFactor($request, $data['code']))]);
    }

    /** Mulai pendaftaran 2FA admin: rahasia baru + QR (belum aktif sampai dikonfirmasi). */
    public function twoFactorSetup(Request $request, \App\Services\Platform\Totp $totp, TenantContext $tenant): JsonResponse
    {
        $u = $request->user();
        abort_unless($u->role === \App\Enums\Role::Admin, 403);
        if ($u->two_factor_confirmed_at) {
            return response()->json(['message' => '2FA sudah aktif.'], 409);
        }
        $secret = $request->session()->get('portal.2fa.secret') ?? $totp->secret();
        $request->session()->put('portal.2fa.secret', $secret);
        $uri = $totp->uri(($u->email ?: $u->username).' · '.$tenant->get()->subdomain, $secret, 'MyPresence');

        return response()->json(['secret' => trim(chunk_split($secret, 4, ' ')), 'qr_svg' => $totp->qrSvg($uri)]);
    }

    /** Konfirmasi 2FA admin dengan kode pertama; kembalikan kode cadangan (ditampilkan sekali). */
    public function twoFactorConfirm(Request $request, \App\Services\Platform\Totp $totp, \App\Services\Platform\AuditLogger $audit, TenantContext $tenant): JsonResponse
    {
        $request->validate(['code' => ['required', 'string', 'max:10']], [], ['code' => 'kode']);
        $u = $request->user();
        abort_unless($u->role === \App\Enums\Role::Admin, 403);
        $secret = $request->session()->get('portal.2fa.secret');
        $step = $secret ? $totp->verify($secret, preg_replace('/\s+/', '', (string) $request->input('code'))) : null;
        if ($step === null) {
            return response()->json(['message' => 'Kode tidak cocok. Pastikan jam HP otomatis, lalu masukkan kode terbaru.'], 422);
        }

        $codes = $totp->recoveryCodes();
        $u->forceFill(['two_factor_secret' => $secret, 'two_factor_confirmed_at' => now(), 'two_factor_last_step' => $step, 'two_factor_recovery_codes' => $codes])->save();
        $request->session()->forget('portal.2fa.secret');
        $audit->log('2fa.enabled', "{$u->name} mengaktifkan 2FA admin sekolah", $tenant->get(), $u);

        return response()->json(['recovery_codes' => $codes, 'user' => $this->present($u)]);
    }

    public function logout(Request $request, LoginService $login): JsonResponse
    {
        $login->logout($request);

        return response()->json(['message' => 'Sampai jumpa.']);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json(['user' => $this->present($request->user())]);
    }

    public function changePassword(Request $request): JsonResponse
    {
        $data = $request->validate([
            'current_password' => ['required', 'current_password'],
            'password' => ['required', 'confirmed', Password::min(8)],
        ]);

        $request->user()->forceFill(['password' => $data['password'], 'must_change_password' => false])->save();

        return response()->json(['message' => 'Password diperbarui.']);
    }

    /**
     * PUT /api/me/name — admin sekolah mengubah nama lengkapnya sendiri (mis. salah isi saat mendaftar).
     * Nama guru & siswa berasal dari data master (Data Guru / Data Siswa), jadi diubah oleh admin di sana.
     */
    public function updateName(Request $request, \App\Services\Platform\AuditLogger $audit, TenantContext $tenant): JsonResponse
    {
        $u = $request->user();
        abort_unless($u->role === \App\Enums\Role::Admin, 403, 'Nama guru & siswa diubah oleh admin di Data Guru / Data Siswa.');
        $d = $request->validate(['name' => ['required', 'string', 'min:3', 'max:120', 'regex:/\pL/u']], [
            'name.regex' => 'Nama harus berisi huruf.',
        ], ['name' => 'nama lengkap']);
        $name = trim(preg_replace('/\s+/', ' ', $d['name']));
        $before = $u->name;
        if ($name !== $before) {
            $u->forceFill(['name' => $name])->save();
            $audit->log('account.name_changed', "Nama admin diubah dari \"$before\" menjadi \"$name\"", $tenant->get(), $u);
        }

        return response()->json(['message' => 'Nama diperbarui.', 'user' => $this->present($u)]);
    }

    /** Dipakai login passkey agar bentuk data pengguna sama dengan login password. */
    public function presentUser(User $u): array
    {
        return $this->present($u);
    }

    private function present(User $u): array
    {
        $u->loadMissing(['teacher', 'student.classroom']);

        return [
            'id' => $u->id,
            'role' => $u->role->value,
            'role_label' => $u->role->label(),
            'username' => $u->username,
            'name' => $u->name,
            'email' => $u->email,
            'phone' => $u->phone,
            'must_change_password' => $u->must_change_password,
            'two_factor_setup_required' => LoginService::needsTwoFactorSetup($u),
            'two_factor_enabled' => (bool) $u->two_factor_confirmed_at,
            'photo_url' => \App\Support\Avatar::of($u),
            'teacher' => $u->teacher ? $u->teacher->only(['id', 'nik', 'subject', 'position']) + [
                // Menu Wali Kelas & Leger Rapor hanya untuk guru yang menjadi wali kelas (alur prototipe).
                'is_homeroom' => \App\Models\Classroom::query()->where('homeroom_teacher_id', $u->teacher->id)->exists(),
            ] : null,
            'student' => $u->student ? [
                'id' => $u->student->id,
                'nis' => $u->student->nis,
                'classroom' => $u->student->classroom?->only(['id', 'name']),
                'status' => $u->student->status,
                'alumni_until' => $u->student->alumniUntil()?->toDateString(),
            ] : null,
        ];
    }
}
