<?php

namespace App\Http\Middleware;

use App\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Pemakaian: ->middleware('role:admin,teacher')
 * Juga memastikan pengguna memang milik sekolah yang sedang dibuka.
 */
class RequireRole
{
    public function __construct(private TenantContext $tenant) {}

    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();

        $json = $request->expectsJson() || $request->is('api/*');

        if (! $user) {
            return $json
                ? response()->json(['message' => 'Silakan masuk terlebih dahulu.'], 401)
                : redirect()->guest(route($this->tenant->check() ? 'portal.login' : 'panel.login'));
        }

        if ($this->tenant->check() && (int) $user->school_id !== $this->tenant->id()) {
            return $json ? response()->json(['message' => 'Akun ini bukan milik sekolah ini.'], 403) : abort(403, 'Akun ini bukan milik sekolah ini.');
        }

        if ($roles && ! in_array($user->role->value, $roles, true)) {
            return $json ? response()->json(['message' => 'Anda tidak punya akses ke fitur ini.'], 403) : abort(403, 'Anda tidak punya akses ke halaman ini.');
        }

        // Password awal wajib diganti dulu. Ditegakkan di server, bukan hanya di tampilan PWA.
        if ($this->mustChangePasswordFirst($request, $user)) {
            return $json
                ? response()->json(['message' => 'Ganti password awal terlebih dahulu.', 'must_change_password' => true], 403)
                : redirect('/'.\App\Http\Controllers\Portal\PwaController::appForRole($user->role->value));
        }

        // Admin sekolah wajib mengaktifkan 2FA (aplikasi authenticator) sebelum memakai portal.
        if ($this->tenant->check() && ! $this->impersonating($request) && \App\Services\LoginService::needsTwoFactorSetup($user)
            && ! $request->is(...self::OPEN_BEFORE_PASSWORD_CHANGE, ...self::OPEN_BEFORE_TWO_FACTOR)) {
            return $json
                ? response()->json(['message' => 'Aktifkan verifikasi 2 langkah (2FA) terlebih dahulu.', 'two_factor_setup_required' => true], 403)
                : redirect('/'.\App\Http\Controllers\Portal\PwaController::appForRole($user->role->value));
        }

        // Alumni (siswa lulus): hanya boleh melihat, tidak mengubah apa pun selain password & langganan push.
        if ($this->tenant->check() && $user->role === \App\Enums\Role::Student && ! $request->isMethodSafe()
            && ! $request->is(...self::OPEN_FOR_ALUMNI) && $user->student?->status === 'lulus') {
            return $json
                ? response()->json(['message' => 'Akun alumni hanya bisa melihat rekap kehadiran.', 'alumni' => true], 403)
                : abort(403, 'Akun alumni hanya bisa melihat rekap kehadiran.');
        }

        return $next($request);
    }

    /** Alumni tetap boleh keluar, ganti password, menandai notifikasi, dan mengatur push. */
    private const OPEN_FOR_ALUMNI = ['api/auth/logout', 'keluar', 'api/me/password', 'api/notifications/read', 'api/push/subscriptions'];

    /** Rute yang tetap boleh dibuka selama password awal belum diganti. */
    private const OPEN_BEFORE_PASSWORD_CHANGE = [
        'api/me', 'api/me/password', 'api/auth/logout', 'keluar', 'bantuan/selesai',
    ];

    /** Tambahan rute yang boleh dibuka selama 2FA admin belum aktif. */
    private const OPEN_BEFORE_TWO_FACTOR = ['api/me/2fa', 'verifikasi', 'verifikasi/*'];

    private function impersonating(Request $request): bool
    {
        return $request->hasSession() && $request->session()->has(\App\Services\Platform\Impersonation::SESSION_KEY);
    }

    private function mustChangePasswordFirst(Request $request, $user): bool
    {
        if (! $user->must_change_password || ! $this->tenant->check()) {
            return false;
        }

        // Super-admin yang sedang membantu sekolah (impersonasi) tidak dipaksa mengganti password pengguna.
        if ($request->hasSession() && $request->session()->has(\App\Services\Platform\Impersonation::SESSION_KEY)) {
            return false;
        }

        return ! $request->is(...self::OPEN_BEFORE_PASSWORD_CHANGE);
    }
}
