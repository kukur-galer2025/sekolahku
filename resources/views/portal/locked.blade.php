<!DOCTYPE html>
<html lang="id" data-portal="admin">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title>Layanan diblokir sementara · {{ $school->name }} — MyPresence</title>
<link rel="icon" href="/pwa/icons/admin-192.png">
<link rel="stylesheet" href="{{ asset('css/portal.css') }}?v=27">
<script @nonce>try{document.documentElement.dataset.theme=localStorage.getItem('mp_theme')||'vista'}catch(e){document.documentElement.dataset.theme='vista'}</script>
</head>
<body>
<div class="login">
  <div class="lc">
    <div class="brand"><span class="brand-logo"><img src="{{ asset('img/logo-vertical.svg') }}" alt="ParamaEdu.Tech" width="584" height="308"></span><div class="brand-copy"><strong>My<span>Presence</span></strong><small>Portal Admin Sekolah</small></div></div>
    <p class="school-name">{{ $school->name }}</p>
    <div class="alert wr" style="margin-top:14px"><b>Layanan diblokir sementara.</b><br>{{ $message }}</div>
    <p class="hint">Data sekolah tetap tersimpan aman. Layanan aktif kembali otomatis setelah pembayaran diterima.</p>
    @auth
      <form method="post" action="{{ route('portal.logout') }}">@csrf<button class="btn w o" type="submit">Keluar</button></form>
    @else
      <a class="btn w" href="{{ route('portal.login') }}" style="text-align:center;display:block">Masuk sebagai admin</a>
    @endauth
    <p class="foot">Dikembangkan oleh ParamaEdu.Tech · Solusi Digital untuk Sekolah Modern</p>
  </div>
</div>
</body>
</html>
