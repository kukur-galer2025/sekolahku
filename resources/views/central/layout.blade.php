<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title>@yield('title', 'MyPresence') — ParamaEdu.Tech</title>
<link rel="icon" href="{{ asset('img/logo.png') }}">
<link rel="stylesheet" href="{{ asset('css/portal.css') }}?v=9">
<script src="/js/csp-actions.js?v=1" defer></script></head>
<body>
<div class="pub">
  <header class="pub-top">
    <a href="{{ route('central.home') }}" style="text-decoration:none;color:inherit"><div class="brand"><span class="brand-logo"><img src="{{ asset('img/logo-vertical.svg') }}" alt="ParamaEdu.Tech" width="584" height="308"></span><div class="brand-copy"><strong>My<span>Presence</span></strong><small>Presensi & administrasi sekolah</small></div></div></a>
    <nav class="row" style="flex:none;gap:8px"><a class="btn o nf" href="{{ route('central.code', 'app') }}">Masuk ke sekolah</a><a class="btn nf" href="{{ route('central.register') }}">Daftar sekolah</a></nav>
  </header>
  @yield('content')
  <p class="foot">© {{ date('Y') }} ParamaEdu.Tech · Solusi Digital untuk Sekolah Modern ·
    @foreach (config('legal.documents') as $d)<a href="{{ route('legal.show', $d['slug']) }}">{{ $d['title'] }}</a>@if (! $loop->last) · @endif @endforeach</p>
</div>
</body>
</html>
