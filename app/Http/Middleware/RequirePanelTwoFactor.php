<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Super-admin wajib mengaktifkan 2FA sebelum memakai panel (SUPERADMIN_2FA=true, bawaan). */
class RequirePanelTwoFactor
{
    public function handle(Request $request, Closure $next): Response
    {
        $u = $request->user();
        if (config('tenancy.superadmin_2fa') && $u && ! $u->two_factor_confirmed_at) {
            return redirect()->route('panel.2fa.setup');
        }

        return $next($request);
    }
}
