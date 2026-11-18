<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\IpUtils;
use Symfony\Component\HttpFoundation\Response;

/** Opsional: panel super-admin hanya bisa dibuka dari IP kantor (PANEL_ALLOWED_IPS). */
class RestrictPanelIp
{
    public function handle(Request $request, Closure $next): Response
    {
        $allowed = config('tenancy.panel_allowed_ips', []);
        abort_if($allowed && ! IpUtils::checkIp((string) $request->ip(), $allowed), 404);

        return $next($request);
    }
}
