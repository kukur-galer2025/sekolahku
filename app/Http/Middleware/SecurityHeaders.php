<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Header keamanan untuk semua respons. Nginx juga memasang sebagian; dobel tidak masalah.
 * CSP: semua skrip, gaya, font, dan gambar dari domain sendiri (font Poppins disajikan sendiri).
 */
class SecurityHeaders
{
    /** Kunci container untuk nonce CSP request ini; dicetak di view lewat directive Blade @nonce. */
    public const NONCE = 'csp.nonce';

    public function handle(Request $request, Closure $next): Response
    {
        // Nonce dibuat sebelum view dirender: hanya <script> milik aplikasi (bertanda @nonce) yang boleh jalan,
        // jadi skrip sisipan (XSS) dan atribut onclick=... ditolak browser.
        $nonce = base64_encode(random_bytes(16));
        app()->instance(self::NONCE, $nonce);

        $response = $next($request);
        $h = $response->headers;

        $h->set('X-Content-Type-Options', 'nosniff');
        $h->set('X-Frame-Options', 'SAMEORIGIN');
        $h->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $h->set('Permissions-Policy', 'camera=(self), microphone=(), geolocation=(), payment=()');
        $h->set('Cross-Origin-Opener-Policy', 'same-origin');

        if ($request->isSecure()) {
            $h->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }

        if (! $h->has('Content-Security-Policy') && str_contains((string) $h->get('Content-Type'), 'text/html')) {
            $domain = config('tenancy.central_domain');
            $img = "'self' data: blob:".(config('tenancy.files_disk') !== 'local' ? ' https:' : '');
            $h->set('Content-Security-Policy', implode('; ', [
                "default-src 'self'",
                "img-src $img",
                "style-src 'self' 'unsafe-inline'",
                "script-src 'self' 'nonce-$nonce'",
                "font-src 'self'",
                "connect-src 'self'",
                "object-src 'none'",
                "base-uri 'self'",
                "frame-ancestors 'self'",
                // formulir panel "Masuk sebagai sekolah" dialihkan ke subdomain sekolah;
                // tombol "Bayar" dialihkan ke halaman pembayaran Midtrans/Xendit.
                "form-action 'self' $domain:* *.$domain:* https://app.midtrans.com https://app.sandbox.midtrans.com https://checkout.xendit.co https://checkout-staging.xendit.co",
            ]));
        }

        if ($request->user() || str_starts_with($request->path(), 'panel')) {
            $h->set('Cache-Control', 'no-store, private'); // halaman berisi data pribadi tidak disimpan cache peramban/proxy
        }

        return $response;
    }
}
