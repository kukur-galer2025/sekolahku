{{-- PWA panel: manifest, ikon, warna, dan pendaftaran service worker (scope /panel). --}}
<link rel="manifest" href="/panel/manifest.webmanifest">
<meta name="theme-color" content="#0B2D6B">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-title" content="MP Panel">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<link rel="apple-touch-icon" href="/pwa/icons/panel-apple-touch.png">
<link rel="stylesheet" href="{{ asset('css/portal.css') }}?v=30">
<script @nonce>
  if ('serviceWorker' in navigator) {
    window.addEventListener('load', () => navigator.serviceWorker.register('/panel/sw.js', { scope: '/panel' }).catch(() => {}));
  }
  // Tombol "Pasang aplikasi" (Chrome/Edge/Android) muncul hanya bila browser mengizinkan pemasangan.
  window.addEventListener('beforeinstallprompt', (e) => {
    e.preventDefault(); window.__mpInstall = e;
    document.querySelectorAll('[data-pwa-install]').forEach((b) => (b.hidden = false));
  });
  window.addEventListener('appinstalled', () => document.querySelectorAll('[data-pwa-install]').forEach((b) => (b.hidden = true)));
  window.mpInstall = async () => { const p = window.__mpInstall; if (!p) return; p.prompt(); await p.userChoice; window.__mpInstall = null;
    document.querySelectorAll('[data-pwa-install]').forEach((b) => (b.hidden = true)); };
</script>
