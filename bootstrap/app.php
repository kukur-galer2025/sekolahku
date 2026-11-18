<?php

use App\Http\Middleware\IdentifyTenant;
use App\Http\Middleware\RequireRole;
use App\Http\Middleware\ResetTenant;
use App\Tenancy\TenantViolation;
use Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        commands: __DIR__.'/../routes/console.php',
        using: function () {
            $central = config('tenancy.central_domain');

            // Cek "aplikasi hidup" untuk load balancer/monitor (semua host, tanpa sesi/DB).
            // (Parameter withRouting(health:) diabaikan Laravel bila memakai using:, jadi didaftarkan di sini.)
            Route::get('up', fn () => response('OK', 200, ['Content-Type' => 'text/plain', 'Cache-Control' => 'no-store']))->name('up');

            // Domain pusat dulu, supaya tidak tertangkap pola {tenant}.
            Route::middleware('web')->domain($central)->group(base_path('routes/web.php'));
            // www.mypresence.id → mypresence.id (satu alamat kanonik)
            Route::domain('www.'.$central)->get('{any?}', fn (Request $r) => redirect()->away(
                $r->getScheme().'://'.$central.$r->getRequestUri(), 301
            ))->where('any', '.*');

            // Semua subdomain lain = sekolah.
            Route::middleware(['web', 'tenant', 'plan'])
                ->domain('{tenant}.'.$central)
                ->where(['tenant' => '[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?'])
                ->group(base_path('routes/tenant.php'));

            // Lokal saja: 127.0.0.1:8000 / localhost:8000 (php artisan serve) → domain pusat dari APP_URL.
            // Aplikasi mengenali sekolah dari nama domain, jadi alamat IP selalu 404 tanpa pengalihan ini.
            if (config('app.env') === 'local' && ! in_array($central, ['127.0.0.1', 'localhost'], true)) {
                foreach (['127.0.0.1', 'localhost'] as $host) {
                    // Port diambil dari request (bukan APP_URL), jadi tetap benar walau env terminal berbeda.
                    Route::domain($host)->get('{any?}', fn (Request $r) => redirect()->away(
                        $r->getScheme().'://'.$central.(in_array($r->getPort(), [80, 443], true) ? '' : ':'.$r->getPort()).$r->getRequestUri()
                    ))->where('any', '.*');
                }
            }
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->prepend(ResetTenant::class);

        // Proxy tepercaya (Cloudflare, dll.) dibaca dari config('tenancy.trusted_proxies') saat request,
        // jadi tetap berlaku setelah config:cache. Lihat App\Http\Middleware\TrustProxies.
        $middleware->replace(\Illuminate\Http\Middleware\TrustProxies::class, \App\Http\Middleware\TrustProxies::class);
        $middleware->alias(['tenant' => IdentifyTenant::class, 'role' => RequireRole::class, 'admin.verified' => \App\Http\Middleware\EnsureAdminEmailVerified::class, 'panel.ip' => \App\Http\Middleware\RestrictPanelIp::class, 'panel.2fa' => \App\Http\Middleware\RequirePanelTwoFactor::class, 'plan' => \App\Http\Middleware\EnforcePlanFeatures::class, 'library.protect' => \App\Http\Middleware\ProtectLibraryContent::class]);
        $middleware->append(\App\Http\Middleware\SecurityHeaders::class);
        // Webhook gateway pembayaran tidak membawa token CSRF; keasliannya diverifikasi di BillingService.
        $middleware->validateCsrfTokens(except: ['webhooks/*']);
        // Tenant harus dikenali sebelum autentikasi membaca pengguna dari sesi.
        $middleware->prependToPriorityList(AuthenticatesRequests::class, IdentifyTenant::class);
        $middleware->redirectGuestsTo(fn (Request $r) => $r->is('api/*') ? null : '/');
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(fn (Request $r) => $r->is('api/*') || $r->expectsJson());
        // Pelanggaran batas tenant adalah bug: jangan bocorkan detail ke klien.
        $exceptions->render(fn (TenantViolation $e, Request $r) => response()->json(['message' => 'Permintaan ditolak.'], 403));
        // Produksi: error tak terduga dikirim ke email tim (dibatasi 1x/15 menit per jenis error).
        $exceptions->reportable(fn (\Throwable $e) => \App\Services\Ops\ErrorAlert::report($e));
    })->create();
