<!DOCTYPE html>
<html lang="id" data-portal="admin">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title>Verifikasi 2 langkah · {{ $school->name }} — MyPresence</title>
<link rel="icon" href="/pwa/icons/admin-192.png">
<link rel="stylesheet" href="{{ asset('css/portal.css') }}?v=27">
<script @nonce>try{document.documentElement.dataset.theme=localStorage.getItem('mp_theme')||'vista'}catch(e){document.documentElement.dataset.theme='vista'}</script>
</head>
<body>
<div class="login">
  <form class="lc" method="post" action="{{ route('portal.2fa') }}">
    @csrf
    <div class="brand"><span class="brand-logo"><img src="{{ asset('img/logo-vertical.svg') }}" alt="ParamaEdu.Tech" width="584" height="308"></span><div class="brand-copy"><strong>My<span>Presence</span></strong><small>Portal Admin Sekolah</small></div></div>
    <p class="school-name">{{ $school->name }}</p>
    <p class="tag" style="margin-top:12px">Masukkan kode 6 digit dari aplikasi authenticator, atau salah satu kode cadangan.</p>
    <label for="code">Kode verifikasi</label>
    <input id="code" name="code" inputmode="numeric" autocomplete="one-time-code" autofocus required style="font-size:22px;letter-spacing:6px;text-align:center">
    <button class="btn w" type="submit">Verifikasi</button>
    @error('code')<div class="err" role="alert">{{ $message }}</div>@enderror
    <p class="hint"><a href="{{ route('portal.login') }}">Kembali ke halaman masuk</a></p>
    <p class="foot">Dikembangkan oleh ParamaEdu.Tech · Solusi Digital untuk Sekolah Modern</p>
  </form>
</div>
</body>
</html>
