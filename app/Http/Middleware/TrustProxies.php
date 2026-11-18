<?php

namespace App\Http\Middleware;

use Illuminate\Http\Middleware\TrustProxies as Middleware;

/**
 * PENTING untuk cek WiFi sekolah: IP klien diambil dari REMOTE_ADDR.
 * Header X-Forwarded-For hanya dipercaya bila koneksi datang dari proxy yang terdaftar di
 * TRUSTED_PROXIES (mis. daftar IP Cloudflare). Tanpa ini, siswa bisa memalsukan IP lewat header.
 *
 * Daftar dibaca dari config saat request (bukan env() di bootstrap/app.php), sehingga tetap
 * berlaku setelah `php artisan optimize` / config:cache di produksi.
 */
class TrustProxies extends Middleware
{
    protected function proxies()
    {
        $p = trim((string) config('tenancy.trusted_proxies', ''));
        if ($p === '*') {
            return '*';
        }

        return array_values(array_filter(array_map('trim', explode(',', $p)))) ?: null;
    }
}
