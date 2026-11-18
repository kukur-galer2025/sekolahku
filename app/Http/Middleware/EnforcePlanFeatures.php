<?php

namespace App\Http\Middleware;

use App\Support\Plan;
use App\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Menolak fitur yang tidak termasuk paket langganan sekolah (config billing.features & billing.feature_paths).
 * Dipasang untuk semua rute sekolah di bootstrap/app.php, setelah tenant dikenali.
 * Menu di PWA juga disembunyikan, tapi penegakan sebenarnya ada di sini.
 */
class EnforcePlanFeatures
{
    public function __construct(private TenantContext $tenant) {}

    public function handle(Request $request, Closure $next): Response
    {
        $school = $this->tenant->get();
        $feature = $school ? Plan::featureForPath($request->path()) : null;

        if ($feature && ! Plan::allows($school->plan, $feature)) {
            $msg = Plan::lockedMessage($feature);

            return $request->expectsJson() || $request->is('api/*')
                ? response()->json(['message' => $msg, 'code' => 'plan_locked', 'feature' => $feature, 'plan' => Plan::normalize($school->plan)], 403)
                : abort(403, $msg);
        }

        return $next($request);
    }
}
