<?php

namespace App\Http\Middleware;

use App\Models\LibraryItem;
use App\Services\Library\LibraryProtection;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Pemakaian: ->middleware('library.protect')       → header anti-cache/anti-embed untuk semua respons MP-Library.
 *            ->middleware('library.protect:ticket') → juga wajib tiket sekali pakai yang sah (pengambilan isi berkas).
 *
 * Tiket yang sah disimpan di $request->attributes('library_ticket') untuk controller.
 * Tiket tidak sah dicatat di log audit (ticket_invalid) lalu ditolak 403.
 */
class ProtectLibraryContent
{
    public function __construct(private LibraryProtection $guard) {}

    public function handle(Request $request, Closure $next, ?string $mode = null): Response
    {
        if ($mode === 'ticket') {
            $item = $request->route('item');
            $user = $request->user();
            if (! $item instanceof LibraryItem || ! $user) {
                abort(404);
            }
            $t = $this->guard->consumeTicket($request->query('t'), $item, $user);
            if (! $t) {
                $this->guard->log($item, $user, 'ticket_invalid', null, 'Tiket kedaluwarsa, sudah dipakai, atau bukan milik akun ini', $request);

                return response()->json(['message' => 'Akses berkas kedaluwarsa. Tutup lalu buka lagi bacaannya.'], 403);
            }
            // Tiket baca tidak boleh dipakai sebagai unduhan (dan sebaliknya).
            if ($request->query('as') !== null && $request->query('as') !== $t['purpose']) {
                return response()->json(['message' => 'Tiket tidak sesuai.'], 403);
            }
            $request->attributes->set('library_ticket', $t);
        }

        $response = $next($request);
        $h = $response->headers;
        $h->set('Cache-Control', 'no-store, no-cache, must-revalidate, private, max-age=0');
        $h->set('Pragma', 'no-cache');
        $h->set('Expires', '0');
        $h->set('X-Content-Type-Options', 'nosniff');
        $h->set('X-Frame-Options', 'SAMEORIGIN');
        $h->set('Content-Security-Policy', "default-src 'none'; frame-ancestors 'self'; sandbox");
        $h->set('Referrer-Policy', 'no-referrer');
        $h->set('X-Robots-Tag', 'noindex, nofollow, noarchive');
        $h->set('Cross-Origin-Resource-Policy', 'same-origin');

        return $response;
    }
}
