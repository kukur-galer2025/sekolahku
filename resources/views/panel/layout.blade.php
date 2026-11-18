@php $u = auth()->user(); @endphp
<!DOCTYPE html>
<html lang="id" data-portal="panel">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title>@yield('title') · Panel Parama Tech — MyPresence</title>
<link rel="icon" href="{{ asset('img/icon-panel.png') }}">
@include('panel.pwa-head')
<script src="/js/csp-actions.js?v=1" defer></script></head>
<body>
<input type="checkbox" id="nav" hidden>
<div class="app">
  <label for="nav" class="scrim" aria-hidden="true"></label>
  <nav class="side" aria-label="Menu panel">
    <div class="brand"><span class="brand-logo"><img src="{{ asset('img/logo-vertical.svg') }}" alt="ParamaEdu.Tech" width="584" height="308"></span><div class="brand-copy"><strong>My<span>Presence</span></strong><small>Panel Super-Admin</small></div></div>
    <a class="nv {{ request()->routeIs('panel.schools') || request()->routeIs('panel.schools.show') ? 'on' : '' }}" href="{{ route('panel.schools') }}"><x-ti n="building-community" class="ti ic"/>Sekolah</a>
    <a class="nv {{ request()->routeIs('panel.schools.create') ? 'on' : '' }}" href="{{ route('panel.schools.create') }}"><x-ti n="plus" class="ti ic"/>Buat sekolah</a>
    <a class="nv {{ request()->routeIs('panel.plans*') ? 'on' : '' }}" href="{{ route('panel.plans') }}"><x-ti n="credit-card" class="ti ic"/>Paket &amp; harga</a>
    @php $proofN = \App\Services\Billing\BillingService::invoices()->where('status', 'unpaid')->whereNotNull('transfer_proof_at')->count(); @endphp
    <a class="nv {{ request()->routeIs('panel.billing*') ? 'on' : '' }}" href="{{ route('panel.billing') }}"><x-ti n="receipt" class="ti ic"/>Tagihan @if ($proofN)<span class="pl wr" style="margin-left:auto">{{ $proofN }}</span>@endif</a>
    <a class="nv {{ request()->routeIs('panel.finance*') ? 'on' : '' }}" href="{{ route('panel.finance') }}"><x-ti n="chart-bar" class="ti ic"/>Keuangan</a>
    <a class="nv {{ request()->routeIs('panel.payroll*') ? 'on' : '' }}" href="{{ route('panel.payroll') }}"><x-ti n="users-group" class="ti ic"/>Penggajian</a>
    <div class="side-bottom">
      <div class="side-h">Sistem</div>
      <a class="nv {{ request()->routeIs('panel.profile*') ? 'on' : '' }}" href="{{ route('panel.profile') }}"><x-ti n="user" class="ti ic"/>Profil saya</a>
      <a class="nv {{ request()->routeIs('panel.logs*') ? 'on' : '' }}" href="{{ route('panel.logs') }}"><x-ti n="history" class="ti ic"/>Log aktivitas</a>
      <a class="nv {{ request()->routeIs('panel.ops*') ? 'on' : '' }}" href="{{ route('panel.ops') }}"><x-ti n="heartbeat" class="ti ic"/>Operasional</a>
      <button class="nv" type="button" data-pwa-install hidden data-action="install"><x-ti n="download" class="ti ic"/>Pasang aplikasi</button>
      <form method="post" action="{{ route('panel.logout') }}">@csrf<button class="nv" type="submit"><x-ti n="logout" class="ti ic"/>Keluar</button></form>
    </div>
  </nav>
  <div class="main">
    <header class="top">
      <div class="who"><label for="nav" class="menu" aria-label="Buka menu">☰</label>
        <span class="portal-heading"><b>Panel Super-Admin</b><small>{{ config('tenancy.central_domain') }} · Parama Tech</small></span></div>
      @php $ini = collect(preg_split('/\s+/', trim($u->name)))->filter()->take(2)->map(fn ($w) => mb_strtoupper(mb_substr($w, 0, 1)))->implode('') ?: 'SA'; @endphp
      <a class="who who-link" href="{{ route('panel.profile') }}" title="Profil saya"><span class="chip hm">Super Admin</span>@php $uph = \App\Http\Controllers\Panel\ProfileController::photoUrl($u); @endphp @if ($uph) <img class="av av-img" src="{{ $uph }}" alt="" width="32" height="32"> @else <span class="av" aria-hidden="true">{{ $ini }}</span> @endif<span class="hm"><b style="font-size:13px">{{ $u->name }}</b></span></a>
    </header>
    <main class="ct">
      @if (session('ok'))<div class="alert ok">{{ session('ok') }}</div>@endif
      @if (session('err'))<div class="alert wr">{{ session('err') }}</div>@endif
      @if ($errors->any())<div class="alert er">{{ $errors->first() }}</div>@endif
      @yield('content')
    </main>
  </div>
</div>
<script @nonce>/* Log: ingat buka/tutup per halaman */document.querySelectorAll('details.logbox[data-key]').forEach((d) => { const k = 'mp_log_' + d.dataset.key; try { if (!d.open && localStorage.getItem(k) === '1') d.open = true; } catch (_) {} d.addEventListener('toggle', () => { try { localStorage.setItem(k, d.open ? '1' : '0'); } catch (_) {} }); });</script>
</body>
</html>
