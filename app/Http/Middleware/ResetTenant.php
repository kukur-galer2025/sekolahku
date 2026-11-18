<?php

namespace App\Http\Middleware;

use App\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Middleware global: setiap request dimulai tanpa tenant, lalu IdentifyTenant mengisinya bila perlu. */
class ResetTenant
{
    public function __construct(private TenantContext $tenant) {}

    public function handle(Request $request, Closure $next): Response
    {
        $this->tenant->forget();

        return $next($request);
    }
}
