<?php

namespace App\Providers;

use App\Tenancy\TenantContext;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Satu konteks tenant per request/job (aman juga untuk Octane & queue worker).
        $this->app->scoped(TenantContext::class);
    }

    public function boot(): void
    {
        // <script @nonce> → nonce="…" untuk CSP (lihat SecurityHeaders). Di luar request HTTP (email, PDF) kosong.
        \Illuminate\Support\Facades\Blade::directive('nonce', fn () => "<?php echo app()->bound(\App\Http\Middleware\SecurityHeaders::NONCE) ? 'nonce=\"'.e(app(\App\Http\Middleware\SecurityHeaders::NONCE)).'\"' : ''; ?>");

        // Login dibatasi tiga lapis:
        // 1) 5 percobaan/menit per (sekolah, username, IP),
        // 2) 10 percobaan/15 menit per (sekolah, username) dari IP mana pun — menahan tebakan NIS/kode lewat banyak IP,
        // 3) 30 percobaan/menit per IP — menahan satu perangkat yang mencoba banyak akun.
        RateLimiter::for('login', function (Request $r) {
            $account = $r->getHost().'|'.strtolower(preg_replace('/\s+/', '', (string) $r->input('username')));

            return [
                Limit::perMinute(5)->by('login:'.$account.'|'.$r->ip()),
                Limit::perMinutes(15, 10)->by('login-account:'.$account),
                Limit::perMinute(30)->by('login-ip:'.$r->ip()),
            ];
        });
    }
}
