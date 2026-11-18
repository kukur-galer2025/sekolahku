/* MyPresence service worker
 * - Shell (/app, /admin, /guru, /siswa) network-first → bisa dibuka tanpa sinyal setelah sekali dimuat.
 * - Aset berversi (?v=) cache-first; font & ikon cache-first.
 * - Data baca (jadwal, agenda, tugas, jurnal, dashboard, rekap) network-first → cadangan offline.
 * - POST/PUT/DELETE (scan presensi, simpan jurnal, dll.) TIDAK PERNAH di-cache: presensi wajib online.
 * - Halaman portal Blade, panel, dan webhook tidak disentuh.
 */
const SHELL = 'mp-shell-v11'; // v11: /js/csp-actions.js (CSP tanpa skrip inline)
const DATA = 'mp-data-v1';
const PRECACHE = ['/app', '/fonts/inter-latin-400.woff', '/fonts/inter-latin-500.woff', '/fonts/inter-latin-600.woff', '/fonts/inter-latin-700.woff',
  '/fonts/montserrat-latin-700.woff', '/fonts/montserrat-latin-800.woff', '/img/logo-vertical.svg',
  '/pwa/icons/icon-192.png', '/pwa/icons/badge-96.png', '/img/logo.png', '/manifest.webmanifest', '/pwa/ti.svg?v=2', '/js/csp-actions.js?v=1'];
const APPS = /^\/(admin|guru|siswa)\/?$/; // tiga aplikasi terpisah
const OFFLINE_API = /^\/api\/(me|school|dashboard|timetable|agenda|announcements|journals|assignments|attendance\/mine|homeroom|classrooms|my-class|my-folders|library|class-notes|teacher-attendance\/me)$/;

self.addEventListener('install', (e) => {
  e.waitUntil(caches.open(SHELL).then((c) => c.addAll(PRECACHE)).catch(() => {}));
});

self.addEventListener('activate', (e) => {
  e.waitUntil((async () => {
    for (const k of await caches.keys()) if (![SHELL, DATA].includes(k)) await caches.delete(k);
    await self.clients.claim();
  })());
});

self.addEventListener('message', (e) => {
  if (e.data === 'skip-waiting') self.skipWaiting();
  if (e.data === 'clear-data') e.waitUntil(caches.delete(DATA)); // saat keluar / ganti akun
});

async function networkFirst(req, cacheName, { offlineHeader = false } = {}) {
  const cache = await caches.open(cacheName);
  try {
    const res = await fetch(req);
    if (res.ok) cache.put(req, res.clone());
    return res;
  } catch (err) {
    const hit = await cache.match(req);
    if (!hit) throw err;
    if (!offlineHeader) return hit;
    const h = new Headers(hit.headers);
    h.set('X-MP-Offline', '1');
    return new Response(await hit.blob(), { status: hit.status, headers: h });
  }
}

async function cacheFirst(req) {
  const cache = await caches.open(SHELL);
  const hit = await cache.match(req);
  if (hit) return hit;
  const res = await fetch(req);
  if (res.ok) cache.put(req, res.clone());
  return res;
}

self.addEventListener('fetch', (e) => {
  const req = e.request;
  if (req.method !== 'GET') return;
  const url = new URL(req.url);
  if (url.origin !== self.location.origin) return;
  const p = url.pathname;

  if (req.mode === 'navigate') {
    if (p === '/app' || p === '/') e.respondWith(networkFirst(new Request('/app'), SHELL).catch(() => Response.error()));
    else if (APPS.test(p)) e.respondWith(networkFirst(new Request(p.replace(/\/$/, '')), SHELL).catch(() => Response.error()));
    return;
  }
  if (p.startsWith('/pwa/') || p.startsWith('/fonts/') || p === '/css/portal.css' || p === '/img/logo.png' || p === '/js/csp-actions.js') {
    e.respondWith(cacheFirst(req));
    return;
  }
  if (p === '/manifest.webmanifest' || /^\/(admin|guru|siswa)\/manifest\.webmanifest$/.test(p)) { e.respondWith(networkFirst(req, SHELL)); return; }
  if (OFFLINE_API.test(p)) { e.respondWith(networkFirst(req, DATA, { offlineHeader: true })); return; }
});

// ── Push notification ────────────────────────────────────────────
// Dorongan datang TANPA isi (privasi): ambil pemberitahuan terbaru langsung dari server MyPresence.
self.addEventListener('push', (e) => {
  e.waitUntil((async () => {
    let item = null, unread = 0;
    try {
      const r = await fetch('/api/notifications/latest', { credentials: 'same-origin', headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' }, cache: 'no-store' });
      if (r.ok) { const j = await r.json(); item = j.item; unread = j.unread || 0; }
    } catch (_) {}
    // Wajib selalu menampilkan notifikasi (aturan browser untuk push tanpa isi).
    await self.registration.showNotification(item?.title || 'MyPresence', {
      body: item ? (item.body || '') : 'Ada pemberitahuan baru. Ketuk untuk membuka.',
      icon: '/pwa/icons/icon-192.png',
      badge: '/pwa/icons/badge-96.png', // ikon kecil status bar Android: harus putih transparan
      tag: item ? 'mp-' + item.id : 'mp-baru', renotify: true, vibrate: [120, 60, 120], timestamp: Date.now(),
      data: { url: item?.url || '/app#/notif' },
    });
    // Tanda angka di ikon aplikasi (iPhone 16.4+ & desktop). Android menampilkan titik notifikasi sendiri.
    try { if (self.navigator.setAppBadge) await (unread ? self.navigator.setAppBadge(unread) : self.navigator.setAppBadge()); } catch (_) {}
    // Aplikasi yang sedang terbuka langsung memperbarui lonceng.
    for (const c of await self.clients.matchAll({ type: 'window', includeUncontrolled: true })) c.postMessage({ type: 'push' });
  })());
});

self.addEventListener('notificationclick', (e) => {
  e.notification.close();
  const url = new URL(e.notification.data?.url || '/app#/notif', self.location.origin).href;
  e.waitUntil((async () => {
    const wins = await self.clients.matchAll({ type: 'window', includeUncontrolled: true });
    const same = wins.find((w) => new URL(w.url).pathname === new URL(url).pathname);
    if (same) { await same.focus(); return same.navigate(url); }
    return self.clients.openWindow(url);
  })());
});
