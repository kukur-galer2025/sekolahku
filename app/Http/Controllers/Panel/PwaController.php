<?php

namespace App\Http\Controllers\Panel;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

/**
 * Panel super-admin sebagai aplikasi terpasang (PWA) di parama.sbs/panel.
 * Service worker sengaja minimalis: halaman panel (data sekolah, keuangan, gaji) TIDAK PERNAH disimpan di cache;
 * yang di-cache hanya aset statis (CSS, font, ikon) + halaman "sedang offline".
 */
class PwaController extends Controller
{
    /** Naikkan bila daftar aset / logika SW berubah. */
    public const VERSION = 'panel-v2'; // v2: /js/csp-actions.js (CSP tanpa skrip inline)

    public function manifest(): JsonResponse
    {
        return response()->json([
            'id' => '/panel',
            'name' => 'MyPresence Panel · Parama Tech',
            'short_name' => 'MP Panel',
            'description' => 'Panel super-admin MyPresence: sekolah, tagihan, keuangan, dan penggajian.',
            'lang' => 'id',
            'start_url' => '/panel?pwa=1',
            'scope' => '/panel',
            'display' => 'standalone',
            'display_override' => ['window-controls-overlay', 'standalone'],
            'orientation' => 'any',
            'background_color' => '#0A245C',
            'theme_color' => '#0B2D6B',
            'categories' => ['business', 'finance', 'productivity'],
            'icons' => [
                ['src' => '/pwa/icons/panel-192.png', 'sizes' => '192x192', 'type' => 'image/png'],
                ['src' => '/pwa/icons/panel-512.png', 'sizes' => '512x512', 'type' => 'image/png'],
                ['src' => '/pwa/icons/panel-maskable-512.png', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'maskable'],
            ],
            'shortcuts' => [
                ['name' => 'Tagihan', 'url' => '/panel/tagihan', 'icons' => [['src' => '/pwa/icons/panel-192.png', 'sizes' => '192x192']]],
                ['name' => 'Keuangan', 'url' => '/panel/keuangan', 'icons' => [['src' => '/pwa/icons/panel-192.png', 'sizes' => '192x192']]],
                ['name' => 'Mutasi bank', 'url' => '/panel/keuangan/mutasi', 'icons' => [['src' => '/pwa/icons/panel-192.png', 'sizes' => '192x192']]],
                ['name' => 'Penggajian', 'url' => '/panel/penggajian', 'icons' => [['src' => '/pwa/icons/panel-192.png', 'sizes' => '192x192']]],
            ],
        ], 200, ['Content-Type' => 'application/manifest+json', 'Cache-Control' => 'public, max-age=3600']);
    }

    public function serviceWorker(): Response
    {
        $css = '/css/portal.css?v='.self::cssVersion();
        $precache = json_encode(['/panel/offline', $css, '/js/csp-actions.js?v=1', '/pwa/icons/panel-192.png', '/pwa/icons/panel-512.png', '/img/logo-vertical.svg', '/img/icon-panel.png',
            '/fonts/inter-latin-400.woff', '/fonts/inter-latin-500.woff', '/fonts/inter-latin-600.woff', '/fonts/inter-latin-700.woff',
            '/fonts/montserrat-latin-700.woff', '/fonts/montserrat-latin-800.woff'], JSON_UNESCAPED_SLASHES);
        $cache = 'mp-'.self::VERSION.'-'.self::cssVersion();

        $js = <<<JS
/* MyPresence Panel service worker (scope /panel).
 * - Halaman panel: selalu dari jaringan, TIDAK di-cache (data keuangan & gaji rahasia). Offline → halaman "sedang offline".
 * - Aset statis (css, font, ikon, logo): cache-first.
 * - POST/PUT/DELETE & unduhan tidak disentuh.
 */
const CACHE = '$cache';
const PRECACHE = $precache;
const STATIC = /^\/(css|fonts|img|js|pwa\/icons)\//;

self.addEventListener('install', (e) => {
  // Satu per satu: satu file gagal (mis. font tidak ada) tidak menggagalkan pemasangan.
  e.waitUntil(caches.open(CACHE).then((c) => Promise.allSettled(PRECACHE.map((u) => c.add(u)))).then(() => self.skipWaiting()));
});
self.addEventListener('activate', (e) => {
  e.waitUntil((async () => {
    for (const k of await caches.keys()) if (k.startsWith('mp-panel') && k !== CACHE) await caches.delete(k);
    await self.clients.claim();
  })());
});
self.addEventListener('fetch', (e) => {
  const r = e.request;
  if (r.method !== 'GET') return;
  const u = new URL(r.url);
  if (u.origin !== location.origin) return;
  if (r.mode === 'navigate') {
    e.respondWith(fetch(r).catch(async () => (await caches.match('/panel/offline')) || new Response('Offline', { status: 503 })));
    return;
  }
  if (STATIC.test(u.pathname)) {
    e.respondWith(caches.match(r).then((hit) => hit || fetch(r).then((res) => {
      if (res.ok && res.type === 'basic') { const copy = res.clone(); caches.open(CACHE).then((c) => c.put(r, copy)); }
      return res;
    })));
  }
});
JS;

        return response($js, 200, ['Content-Type' => 'application/javascript; charset=utf-8', 'Cache-Control' => 'no-cache', 'Service-Worker-Allowed' => '/panel']);
    }

    public function offline(): Response
    {
        return response()->view('panel.offline', [], 200, ['Cache-Control' => 'public, max-age=86400']);
    }

    /** Versi portal.css yang dipakai panel (dibaca dari pwa-head) (supaya SW meng-cache file yang sama). */
    public static function cssVersion(): string
    {
        $layout = @file_get_contents(resource_path('views/panel/pwa-head.blade.php')) ?: '';

        return preg_match("/portal\\.css'\\) \\}\\}\\?v=(\\d+)/", $layout, $m) ? $m[1] : '1';
    }
}
