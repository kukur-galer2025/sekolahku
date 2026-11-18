<?php

namespace App\Http\Middleware;

use App\Enums\Role;
use App\Services\Platform\Impersonation;
use App\Services\Platform\Legal;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Admin sekolah wajib verifikasi email lalu menyetujui DPA sebelum mengelola data (kecuali sesi bantuan Parama Tech). */
class EnsureAdminEmailVerified
{
    public function handle(Request $request, Closure $next): Response
    {
        $u = $request->user();

        if ($u && $u->role === Role::Admin && ! $request->session()->has(Impersonation::SESSION_KEY)) {
            if (! $u->email_verified_at) {
                return $request->expectsJson()
                    ? response()->json(['message' => 'Verifikasi email admin terlebih dahulu.'], 403)
                    : redirect()->route('portal.verify.notice');
            }
            // Sekolah (pengendali data) wajib menyetujui Perjanjian Pemrosesan Data versi terbaru.
            if (! Legal::accepted((int) $u->school_id, 'dpa')) {
                return $request->expectsJson()
                    ? response()->json(['message' => 'Admin sekolah perlu menyetujui Perjanjian Pemrosesan Data terlebih dahulu.'], 403)
                    : redirect()->route('portal.dpa');
            }
        }

        return $next($request);
    }
}
