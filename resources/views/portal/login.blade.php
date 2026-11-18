<!DOCTYPE html>
<html lang="id" data-portal="admin">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title>Masuk · {{ $school->name }} — MyPresence</title>
<link rel="icon" href="/pwa/icons/admin-192.png">
<link rel="stylesheet" href="{{ asset('css/portal.css') }}?v=27">
<script @nonce>try{document.documentElement.dataset.theme=localStorage.getItem('mp_theme')||'vista'}catch(e){document.documentElement.dataset.theme='vista'}</script>
</head>
<body>
<div class="login">
  <form class="lc" method="post" action="{{ route('portal.login') }}">
    @csrf
    <div class="brand"><span class="brand-logo"><img src="{{ asset('img/logo-vertical.svg') }}" alt="ParamaEdu.Tech" width="584" height="308"></span><div class="brand-copy"><strong>My<span>Presence</span></strong><small>Portal Admin Sekolah</small></div></div>
    @php $logoUrl = app(\App\Tenancy\TenantFiles::class)->url($school->logo_path, 120); @endphp
    @if ($logoUrl)<img src="{{ $logoUrl }}" alt="Logo {{ $school->name }}" style="display:block;width:64px;height:64px;object-fit:contain;margin:14px auto 0">@endif
    <p class="school-name">{{ $school->name }}</p>
    @if ($school->isVerified())<p class="tag" style="margin:4px 0 0;color:#16a34a;font-weight:600">&#10003; Sekolah terverifikasi Parama Tech</p>@else<p class="tag" style="margin:4px 0 0;color:#b45309">Sekolah belum terverifikasi &middot; hanya admin yang bisa masuk</p>@endif
    <p class="tag">Solusi Digital untuk Sekolah Modern</p>
    @if (session('ok'))<div class="alert ok">{{ session('ok') }}</div>@endif

    <label for="u">Username / NIK / NIS</label>
    <input id="u" name="username" value="{{ old('username') }}" autocomplete="username" autofocus required placeholder="Admin: username · Guru: NIK · Siswa: NIS">
    <label for="p">Password</label>
    <input id="p" name="password" type="password" autocomplete="current-password" required>
    <button class="btn w" type="submit">Masuk</button>
    @error('username')<div class="err" role="alert">{{ $message }}</div>@enderror
    @error('password')<div class="err" role="alert">{{ $message }}</div>@enderror

    <p class="hint"><a href="{{ route('portal.password.forgot') }}">Lupa password?</a></p>
    <p class="hint" style="margin-top:6px">Akun baru: pakai password awal yang dibagikan wali kelas/admin, lalu wajib diganti saat pertama masuk.</p>
    <p class="foot">Dikembangkan oleh ParamaEdu.Tech · Solusi Digital untuk Sekolah Modern</p>
  </form>
</div>
</body>
</html>
