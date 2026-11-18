<!DOCTYPE html>
<html lang="id" data-portal="admin"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title>@yield('title') · {{ $school->name }} — MyPresence</title><link rel="icon" href="/pwa/icons/admin-192.png"><link rel="stylesheet" href="{{ asset('css/portal.css') }}?v=27"><script @nonce>try{document.documentElement.dataset.theme=localStorage.getItem('mp_theme')||'vista'}catch(e){document.documentElement.dataset.theme='vista'}</script>
<script src="/js/csp-actions.js?v=1" defer></script></head>
<body><div class="login"><div class="lc">
  <div class="brand"><span class="brand-logo"><img src="{{ asset('img/logo-vertical.svg') }}" alt="ParamaEdu.Tech" width="584" height="308"></span><div class="brand-copy"><strong>My<span>Presence</span></strong><small>Portal Admin Sekolah</small></div></div>
  <p class="school-name">{{ $school->name }}</p>
  @if (session('ok'))<div class="alert ok" style="margin-top:12px">{{ session('ok') }}</div>@endif
  @yield('content')
  <p class="hint"><a href="{{ route('portal.login') }}">‹ Kembali ke halaman masuk</a></p>
</div></div></body></html>
