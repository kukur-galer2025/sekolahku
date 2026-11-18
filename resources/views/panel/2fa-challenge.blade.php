<!DOCTYPE html>
<html lang="id" data-portal="panel"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title>Verifikasi 2 langkah · Panel Parama Tech</title><link rel="icon" href="{{ asset('img/icon-panel.png') }}">@include('panel.pwa-head')</head>
<body><div class="login"><form class="lc" method="post" action="{{ route('panel.2fa') }}">@csrf
  <div class="brand"><span class="brand-logo"><img src="{{ asset('img/logo-vertical.svg') }}" alt="ParamaEdu.Tech" width="584" height="308"></span><div class="brand-copy"><strong>My<span>Presence</span></strong><small>Panel Super-Admin</small></div></div>
  <p class="tag" style="margin-top:12px">Masukkan kode 6 digit dari aplikasi authenticator, atau salah satu kode cadangan.</p>
  <label for="code">Kode verifikasi</label><input id="code" name="code" inputmode="numeric" autocomplete="one-time-code" autofocus required style="font-size:22px;letter-spacing:6px;text-align:center">
  <button class="btn w" type="submit">Verifikasi</button>
  @error('code')<div class="err" role="alert">{{ $message }}</div>@enderror
</form></div></body></html>
