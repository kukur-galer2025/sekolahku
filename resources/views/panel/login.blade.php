<!DOCTYPE html>
<html lang="id" data-portal="panel"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title>Masuk · Panel Parama Tech — MyPresence</title><link rel="icon" href="{{ asset('img/icon-panel.png') }}">@include('panel.pwa-head')</head>
<body><div class="login"><form class="lc" method="post" action="{{ route('panel.login') }}">@csrf
  <div class="brand"><span class="brand-logo"><img src="{{ asset('img/logo-vertical.svg') }}" alt="ParamaEdu.Tech" width="584" height="308"></span><div class="brand-copy"><strong>My<span>Presence</span></strong><small>Panel Super-Admin</small></div></div>
  <p class="tag" style="margin-top:12px">Khusus tim Parama Tech</p>
  <label for="u">Username atau email</label><input id="u" name="username" value="{{ old('username') }}" autocomplete="username" required autofocus>
  <label for="p">Password</label><input id="p" name="password" type="password" autocomplete="current-password" required>
  <button class="btn w" type="submit">Masuk ke panel</button>
  @error('username')<div class="err" role="alert">{{ $message }}</div>@enderror
</form></div></body></html>
