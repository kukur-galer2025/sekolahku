<?php

namespace App\Services;

use App\Enums\Role;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * Satu pintu login untuk API (PWA) dan portal HTML:
 * akun sekolah aktif → password → pengikatan perangkat → rehash → sesi baru.
 */
class LoginService
{
    public function __construct(private TenantContext $tenant, private DeviceBinding $devices) {}

    /** @param  list<string>|null  $roles  peran yang boleh masuk lewat aplikasi ini (aplikasi Siswa hanya untuk siswa, dst.) */
    public function attempt(Request $request, string $username, string $password, ?array $roles = null, ?string $wrongAppMessage = null): User
    {
        $username = preg_replace('/\s+/', '', $username);

        // User::query() otomatis terbatas pada sekolah dari subdomain.
        // Admin boleh masuk dengan username atau email.
        $user = User::query()
            ->whereIn('role', [Role::Admin->value, Role::Teacher->value, Role::Student->value])
            ->where(fn ($q) => $q->where('username', $username)
                ->orWhere(fn ($e) => $e->where('role', Role::Admin->value)->whereNotNull('email')->where('email', strtolower($username))))
            ->orderByRaw('username = ? desc', [$username])
            ->first();

        if (! $user) {
            // Samakan waktu respons dengan akun yang ada (hash bcrypt), supaya NIS/NIK terdaftar tidak bisa ditebak dari lamanya jawaban.
            Hash::make($password);
        }
        if (! $user || ! $user->is_active || ! Hash::check($password, $user->password)) {
            throw ValidationException::withMessages(['username' => 'Username/NIK/NIS atau password salah.']);
        }

        // Aplikasi terpisah: akun guru tidak masuk lewat aplikasi Siswa, dst. Dicek setelah password benar
        // supaya tidak membocorkan peran akun kepada orang yang tidak tahu password-nya.
        if ($roles !== null && ! in_array($user->role->value, $roles, true)) {
            $app = \App\Http\Controllers\Portal\PwaController::appForRole($user->role->value);
            throw ValidationException::withMessages(['username' => $wrongAppMessage
                ?? 'Akun ini akun '.\App\Http\Controllers\Portal\PwaController::APPS[$app]['label'].'. Masuk lewat aplikasi '.\App\Http\Controllers\Portal\PwaController::APPS[$app]['label'].': '.$this->tenant->get()->url($app)]);
        }

        // Sekolah daftar mandiri yang belum diverifikasi Parama Tech: hanya admin yang boleh masuk.
        $school = $this->tenant->get();
        if ($user->role !== Role::Admin && ! $school->isVerified()) {
            throw ValidationException::withMessages(['username' => 'Sekolah ini masih menunggu verifikasi Parama Tech. Akun guru dan siswa bisa dipakai setelah sekolah terverifikasi.']);
        }

        // Siswa: 1 HP. Guru: 2 perangkat. Ditolak di sini sebelum sesi dibuat.
        $this->devices->authorizeLogin($school, $user, $request);

        // Password awal hasil impor di-hash dengan cost lebih rendah; naikkan ke cost penuh saat login.
        if (Hash::needsRehash($user->password)) {
            $user->forceFill(['password' => $password])->save();
        }

        // Admin dengan 2FA aktif: simpan "menunggu kode", sesi login belum dibuat.
        if ($user->two_factor_confirmed_at) {
            $request->session()->put(self::PENDING, ['id' => $user->id, 'at' => now()->getTimestamp()]);
            throw new TwoFactorRequired;
        }

        return $this->finish($request, $user);
    }

    /**
     * Masuk dengan passkey (sidik jari / Face ID) yang sudah diverifikasi WebAuthn.
     * Aturan sama dengan password (akun aktif, aplikasi sesuai peran, sekolah terverifikasi, batas HP).
     * Passkey = faktor ganda (HP + sidik jari), jadi kode authenticator 2FA admin tidak diminta lagi.
     * @param  list<string>|null  $roles
     */
    public function attemptPasskey(Request $request, User $user, ?array $roles = null): User
    {
        if (! $user->is_active) {
            throw ValidationException::withMessages(['passkey' => 'Akun ini tidak aktif. Hubungi admin sekolah.']);
        }
        if ($roles !== null && ! in_array($user->role->value, $roles, true)) {
            $app = \App\Http\Controllers\Portal\PwaController::appForRole($user->role->value);
            throw ValidationException::withMessages(['passkey' => 'Sidik jari ini untuk akun '.\App\Http\Controllers\Portal\PwaController::APPS[$app]['label'].'. Masuk lewat aplikasi '.\App\Http\Controllers\Portal\PwaController::APPS[$app]['label'].'.']);
        }
        $school = $this->tenant->get();
        if ($user->role !== Role::Admin && ! $school->isVerified()) {
            throw ValidationException::withMessages(['passkey' => 'Sekolah ini masih menunggu verifikasi Parama Tech.']);
        }
        try {
            $this->devices->authorizeLogin($school, $user, $request);
        } catch (ValidationException $e) {
            throw ValidationException::withMessages(['passkey' => collect($e->errors())->flatten()->first()]);
        }

        return $this->finish($request, $user);
    }

    public const PENDING = 'portal.2fa.pending';

    /** Langkah kedua login: kode 6 digit dari aplikasi authenticator atau kode cadangan. */
    public function completeTwoFactor(Request $request, string $code): User
    {
        $pending = $request->session()->get(self::PENDING);
        $user = is_array($pending) && now()->getTimestamp() - (int) ($pending['at'] ?? 0) <= 300
            ? User::query()->find($pending['id'] ?? 0) : null; // User::query() terbatas pada sekolah ini

        if (! $user || ! $user->is_active || ! $user->two_factor_confirmed_at) {
            $request->session()->forget(self::PENDING);
            throw ValidationException::withMessages(['code' => 'Sesi verifikasi berakhir. Masuk ulang dengan username dan password.']);
        }

        $code = strtolower(preg_replace('/\s+/', '', $code));
        $totp = app(\App\Services\Platform\Totp::class);
        $step = $totp->verify((string) $user->two_factor_secret, $code, $user->two_factor_last_step);
        $recovery = $user->two_factor_recovery_codes ?? [];

        if ($step !== null) {
            $user->forceFill(['two_factor_last_step' => $step])->save();
        } elseif ($code !== '' && in_array($code, $recovery, true)) {
            $user->forceFill(['two_factor_recovery_codes' => array_values(array_diff($recovery, [$code]))])->save();
            app(\App\Services\Platform\AuditLogger::class)->log('2fa.recovery_used', "{$user->name} masuk memakai kode cadangan 2FA", $this->tenant->get(), $user);
        } else {
            throw ValidationException::withMessages(['code' => 'Kode salah atau sudah dipakai. Masukkan kode terbaru dari aplikasi authenticator.']);
        }

        $request->session()->forget(self::PENDING);

        return $this->finish($request, $user);
    }

    private function finish(Request $request, User $user): User
    {
        Auth::login($user);
        $request->session()->regenerate();
        $user->forceFill(['last_login_at' => now()])->save();

        return $user;
    }

    /** Admin sekolah wajib mengaktifkan 2FA sebelum memakai portal (SCHOOL_ADMIN_2FA=true). */
    public static function needsTwoFactorSetup(User $user): bool
    {
        return config('tenancy.school_admin_2fa') && $user->role === Role::Admin && ! $user->two_factor_confirmed_at;
    }

    public function logout(Request $request): void
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
    }
}
