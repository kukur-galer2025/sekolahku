<!DOCTYPE html>
<html lang="id"@if ($app) data-portal="{{ $app }}"@endif>
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title>MyPresence{{ $appLabel ? ' '.$appLabel : '' }} · {{ $school->name }}</title>
<meta name="description" content="Presensi QR, jadwal, jurnal, dan tugas {{ $school->name }}">
<meta name="theme-color" content="{{ $app ? \App\Http\Controllers\Portal\PwaController::APPS[$app]['color'] : '#0B2D6B' }}" media="(prefers-color-scheme: light)">
<meta name="theme-color" content="#061430" media="(prefers-color-scheme: dark)">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="default">
<meta name="apple-mobile-web-app-title" content="MyPresence">
<link rel="manifest" href="{{ $app ? '/'.$app.'/manifest.webmanifest' : '/manifest.webmanifest' }}">
<link rel="icon" href="{{ \App\Http\Controllers\Portal\PwaController::icon($app, '192') }}">
<link rel="apple-touch-icon" href="{{ \App\Http\Controllers\Portal\PwaController::icon($app, 'apple') }}">
<link rel="preload" href="/fonts/inter-latin-400.woff" as="font" type="font/woff" crossorigin>
<link rel="stylesheet" href="/css/portal.css?v={{ $version }}">
<link rel="stylesheet" href="/pwa/app.css?v={{ $version }}">
<script @nonce>try{document.documentElement.dataset.theme=localStorage.getItem('mp_theme')||'vista'}catch(e){document.documentElement.dataset.theme='vista'}</script>
<script src="/js/csp-actions.js?v=1" defer></script></head>
<body>
<div id="root"><div class="boot"><img src="{{ \App\Http\Controllers\Portal\PwaController::icon($app, '192') }}" alt="" width="72" height="72"><p>Memuat MyPresence…</p></div></div>
<div id="toast" role="status" aria-live="polite"></div>
<noscript><p style="padding:24px">MyPresence membutuhkan JavaScript. Aktifkan JavaScript di browser lalu muat ulang halaman.</p></noscript>
<script @nonce id="boot" type="application/json">@json($boot)</script>
<script type="module" src="/pwa/app.js?v={{ $version }}"></script>
</body>
</html>
