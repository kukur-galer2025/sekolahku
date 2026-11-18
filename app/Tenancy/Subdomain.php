<?php

namespace App\Tenancy;

/**
 * Aturan nama subdomain sekolah: {subdomain}.mypresence.id
 *
 * - Huruf kecil, angka, dan tanda hubung; 3–63 karakter; tidak diawali/diakhiri tanda hubung
 *   (aturan label DNS, RFC 1035).
 * - Tidak boleh memakai nama cadangan (www, admin, api, ...) di config/tenancy.php.
 */
class Subdomain
{
    public const PATTERN = '[a-z0-9](?:[a-z0-9-]{1,61}[a-z0-9])';

    public static function normalize(string $value): string
    {
        return strtolower(trim($value));
    }

    public static function isValidFormat(string $value): bool
    {
        return (bool) preg_match('/^'.self::PATTERN.'$/', $value) && ! str_contains($value, '--');
    }

    /**
     * Bentuk "kerangka" untuk mendeteksi alamat tiruan: tanda hubung dibuang dan huruf/angka yang
     * mirip disamakan (0→o, 1→l, i→l, rn→m, vv→w). smppemda1-kes, smp-pemda1kes, dan smppemdalkes
     * semuanya menjadi "smppemdalkes", jadi tidak bisa dipakai untuk menyaru sekolah lain.
     */
    public static function canonical(string $value): string
    {
        $v = str_replace('-', '', self::normalize($value));
        $v = strtr($v, ['0' => 'o', '1' => 'l', 'i' => 'l']);

        return str_replace(['rn', 'vv'], ['m', 'w'], $v);
    }

    public static function isReserved(string $value): bool
    {
        return in_array(self::normalize($value), config('tenancy.reserved_subdomains', []), true);
    }

    /** Alamat domain pusat, mis. https://mypresence.id/panel */
    public static function centralUrl(string $path = ''): string
    {
        return self::origin(config('tenancy.central_domain'), $path);
    }

    /** Alamat lengkap sekolah, mis. https://smkn1.mypresence.id */
    public static function url(string $subdomain, string $path = ''): string
    {
        return self::origin($subdomain.'.'.config('tenancy.central_domain'), $path);
    }

    /**
     * Skema & port: dari request yang sedang berjalan bila request itu ke domain MyPresence (paling akurat,
     * mis. php artisan serve :8000 atau HTTPS di balik proxy tepercaya); selain itu (email, antrean, cron) dari APP_URL.
     */
    private static function origin(string $host, string $path): string
    {
        $scheme = parse_url((string) config('app.url'), PHP_URL_SCHEME) ?: 'https';
        $port = parse_url((string) config('app.url'), PHP_URL_PORT);

        $central = (string) config('tenancy.central_domain');
        $request = app()->runningInConsole() ? null : request();
        if ($request && $central !== '' && ($request->getHost() === $central || str_ends_with($request->getHost(), '.'.$central))) {
            $scheme = $request->getScheme();
            $port = $request->getPort();
        }
        $default = ($scheme === 'https' && (int) $port === 443) || ($scheme === 'http' && (int) $port === 80);

        return $scheme.'://'.$host.($port && ! $default ? ':'.$port : '').($path !== '' ? '/'.ltrim($path, '/') : '');
    }
}
