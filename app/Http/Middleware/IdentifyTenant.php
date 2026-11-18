<?php

namespace App\Http\Middleware;

use App\Models\School;
use App\Tenancy\Subdomain;
use App\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\Response;

/**
 * Mengenali sekolah dari subdomain: smkn1.mypresence.id → schools.subdomain = 'smkn1'.
 *
 * Rute sekolah didaftarkan dengan Route::domain('{tenant}.'.central_domain),
 * jadi parameter {tenant} berasal dari host, bukan dari body/query yang bisa diubah klien.
 */
class IdentifyTenant
{
    public function __construct(private TenantContext $tenant) {}

    public function handle(Request $request, Closure $next): Response
    {
        $subdomain = strtolower((string) $request->route('tenant'));
        $request->route()?->forgetParameter('tenant');

        // Subdomain cadangan (admin, api, mail, ...) tidak pernah menjadi sekolah, tanpa perlu query.
        if (Subdomain::isReserved($subdomain) || ! Subdomain::isValidFormat($subdomain)) {
            return $this->deny($request, 404, 'Sekolah tidak ditemukan. Periksa alamat yang Anda buka.');
        }

        // Dicache 60 detik per subdomain; dihapus otomatis saat data sekolah berubah (School::booted).
        $school = School::cachedBySubdomain($subdomain);

        if (! $school) {
            return $this->deny($request, 404, 'Sekolah tidak ditemukan. Periksa alamat yang Anda buka.');
        }

        // Diblokir karena tagihan: sekolah tetap dikenali agar admin bisa masuk dan membayar.
        if ($school->isSuspended() && ! $school->isBillingLocked()) {
            return $this->deny($request, 403, 'Layanan untuk sekolah ini sedang ditangguhkan. Hubungi admin sekolah atau Parama Tech.');
        }

        $this->tenant->set($school);

        // route('portal.dashboard') dll. otomatis memakai subdomain sekolah ini.
        URL::defaults(['tenant' => $school->subdomain]);

        // Sesi terikat ke satu sekolah. Sesi yang dibuat di sekolah lain langsung dibatalkan.
        if ($request->hasSession()) {
            $bound = $request->session()->get('tenant_id');
            if ($bound !== null && (int) $bound !== $school->id) {
                Auth::logout();
                $request->session()->invalidate();
            }
            $request->session()->put('tenant_id', $school->id);
        }

        // Pertahanan berlapis: akun milik sekolah lain tidak pernah dianggap login di sini.
        if (Auth::hasUser() && (int) Auth::user()->school_id !== $school->id) {
            Auth::logout();
        }

        if ($school->isBillingLocked() && ! $request->is(...self::OPEN_WHILE_LOCKED)) {
            return $this->locked($request, $school);
        }

        return $next($request);
    }

    /** Rute yang tetap terbuka saat layanan diblokir karena langganan berakhir. */
    private const OPEN_WHILE_LOCKED = [
        'masuk', 'masuk/2fa', 'keluar', 'langganan', 'langganan/*', 'lupa-password', 'reset-password/*', 'verifikasi', 'verifikasi/*',
        'berkas/*', 'bantuan/*', 'persetujuan-dpa', 'api/school', 'api/auth/login', 'api/auth/2fa', 'api/auth/logout', 'api/me', 'api/me/password', 'api/me/2fa',
        'app', 'manifest.webmanifest', 'admin', 'guru', 'siswa', 'admin/manifest.webmanifest', 'guru/manifest.webmanifest', 'siswa/manifest.webmanifest',
    ];

    private function locked(Request $request, School $school): Response
    {
        $msg = 'Layanan MyPresence sekolah ini diblokir sementara karena masa langganan berakhir. Hubungi admin sekolah.';
        if ($request->expectsJson() || $request->is('api/*')) {
            return response()->json(['message' => $msg, 'billing_locked' => true], 402);
        }
        if (Auth::user()?->role === \App\Enums\Role::Admin) {
            return redirect()->route('portal.billing');
        }

        return response()->view('portal.locked', ['school' => $school, 'message' => $msg], 402);
    }

    private function deny(Request $request, int $status, string $message): Response
    {
        if ($request->expectsJson() || $request->is('api/*')) {
            return response()->json(['message' => $message], $status);
        }

        return response($message, $status);
    }
}
