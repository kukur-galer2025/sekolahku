@php
    $u = auth()->user();
    $school = app(\App\Tenancy\TenantContext::class)->get();
    $nav = match ($u->role) {
        \App\Enums\Role::Admin => [
            ['portal.dashboard', 'Dashboard', 'home'], ['portal.teachers', 'Data Guru', 'chalkboard-teacher'], ['portal.students', 'Data Siswa', 'users'],
            ['portal.share', 'Bagikan Aplikasi', 'share'], ['portal.classrooms', 'Kelas & Wali', 'building'], ['portal.year', 'Tahun Ajaran', 'school'], ['portal.imports', 'Impor Data', 'file-import'], ['portal.privacy', 'Data & Privasi', 'shield-lock'], ['portal.billing', 'Langganan', 'credit-card'], ['portal.school', 'Profil Sekolah', 'id-badge-2'], ['portal.audit', 'Log Akses', 'history'],
        ],
        default => [['portal.dashboard', 'Dashboard', 'home']],
    };
    // Sidebar admin berkelompok — sama dengan ADMIN_MENU di public/pwa/app.js.
    // [href, label, ikon, pola rute aktif]; href '#x' = halaman aplikasi /admin#/x.
    $locked = \App\Support\Plan::locked($school->plan);
    $academic = collect([['jurnal', 'Jurnal', 'notebook'], ['wali', 'Wali Kelas', 'presentation'], ['leger', 'Leger Rapor', 'chart-bar'],
        ['cbt', 'CBT', 'device-desktop'], ['pembiasaan', 'Pembiasaan', 'books'], ['library', 'MP-Library', 'book']])->reject(fn ($m) => in_array($m[0], $locked, true));
    $adminMenu = [
        [null, [['#dash', 'Dashboard', 'home', 'portal.dashboard'], ['#qr', 'Absensi Masuk Kelas', 'camera', null], ['#jadwal', 'Jadwal KBM', 'calendar-week', null], ['#info', 'Agenda', 'calendar-event', null]]],
        ['Data sekolah', [['#guru', 'Data Guru', 'chalkboard-teacher', 'portal.teachers'], ['#kartuguru', 'Kartu Login Guru', 'id-badge-2', 'portal.teachers'], ['#siswa', 'Data Siswa', 'users', 'portal.students'], [route('portal.classrooms'), 'Kelas & Rombel', 'building', 'portal.classrooms'], [route('portal.imports'), 'Impor Data', 'file-import', 'portal.imports']]],
        ['Akademik', $academic->map(fn ($m) => ['#'.$m[0], $m[1], $m[2], null])->all()],
        ['Pengaturan', [['#setelan', 'Pengaturan Sekolah', 'settings', ['portal.school', 'portal.share', 'portal.year', 'portal.privacy', 'portal.audit']], [route('portal.billing'), 'Langganan', 'credit-card', 'portal.billing']]],
    ];
    // Menu & istilah per sekolah (panel): ganti nama '#rute' untuk portal admin & sembunyikan menu fitur.
    $menuSet = \App\Support\SchoolMenu::of($school);
    $adminMenu = array_map(fn ($g) => [$g[0], array_values(array_filter(array_map(function ($it) use ($menuSet) {
        if (! is_string($it[0]) || ! str_starts_with($it[0], '#')) {
            return $it;
        }
        $k = substr($it[0], 1);
        if (in_array($k, $menuSet['hidden'], true)) {
            return null;
        }
        $it[1] = $menuSet['names'][$k]['admin'] ?? $it[1];

        return $it;
    }, $g[1])))], $adminMenu);
    $lockedN = count(array_intersect($locked, ['jurnal', 'wali', 'leger', 'cbt', 'pembiasaan', 'library']));
    $logoUrl = app(\App\Tenancy\TenantFiles::class)->url($school->logo_path, 120);
    // Banner langganan untuk admin: ≤14 hari lagi berakhir, masa tenggang, atau diblokir.
    $billBanner = null;
    if ($u->role === \App\Enums\Role::Admin && ! request()->routeIs('portal.billing*')) {
        $exp = $school->expiresAt();
        $what = $school->status === \App\Enums\SchoolStatus::Trial ? 'Masa trial' : 'Langganan';
        if ($school->isBillingLocked()) {
            $billBanner = ['er', 'Layanan diblokir karena langganan berakhir. Guru & siswa tidak bisa memakai aplikasi sampai pembayaran diterima.'];
        } elseif ($exp && $exp->isPast()) {
            $billBanner = ['er', $what.' sudah berakhir. Masa tenggang sampai '.$school->graceEndsAt()->timezone($school->timezone)->format('d/m/Y').', setelah itu layanan diblokir.'];
        } elseif ($exp && $exp->lte(now()->addDays(14))) {
            $billBanner = ['wr', $what.' berakhir '.$exp->timezone($school->timezone)->format('d/m/Y').' ('.max(1, (int) ceil(now()->floatDiffInDays($exp))).' hari lagi).'];
        }
    }
    $initials = collect(explode(' ', $u->name))->filter()->take(2)->map(fn ($w) => mb_substr($w, 0, 1))->implode('');
@endphp
<!DOCTYPE html>
<html lang="id" data-portal="admin">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title>@yield('title') · {{ $school->name }} — MyPresence</title>
<link rel="icon" href="/pwa/icons/admin-192.png">
<link rel="stylesheet" href="{{ asset('css/portal.css') }}?v=27">
<script @nonce>try{document.documentElement.dataset.theme=localStorage.getItem('mp_theme')||'vista'}catch(e){document.documentElement.dataset.theme='vista'}</script>
<script src="/js/csp-actions.js?v=1" defer></script></head>
<body>
@if ($imp = session(\App\Services\Platform\Impersonation::SESSION_KEY))
<div class="imp-bar" role="status"><span><x-ti n="lifebuoy"/> <b>Mode bantuan Parama Tech</b> · {{ $imp['name'] }} masuk sebagai {{ $u->name }} · alasan: {{ $imp['reason'] }}</span>
  <form method="post" action="{{ route('portal.impersonate.end') }}">@csrf<button type="submit">Akhiri sesi bantuan</button></form></div>
@endif
<input type="checkbox" id="nav" hidden>
<div class="app">
  <label for="nav" class="scrim" aria-hidden="true"></label>
  <nav class="side" aria-label="Menu utama">
    <div class="brand"><span class="brand-logo"><img src="{{ asset('img/logo-vertical.svg') }}" alt="ParamaEdu.Tech" width="584" height="308"></span><div class="brand-copy"><strong>My<span>Presence</span></strong><small>Portal Admin Sekolah</small></div></div>
    @if ($u->role === \App\Enums\Role::Admin)
      @foreach ($adminMenu as [$title, $items])
        @if (count($items))
          @if ($title)<div class="grp">{{ $title }}</div>@endif
          @foreach ($items as [$href, $label, $icon, $active])
            <a class="nv {{ $active && request()->routeIs(...array_map(fn ($r) => $r.'*', (array) $active)) ? 'on' : '' }}" href="{{ str_starts_with($href, '#') ? '/admin#/'.substr($href, 1) : $href }}"><x-ti :n="$icon" class="ti ic"/>{{ $label }}</a>
          @endforeach
        @endif
      @endforeach
      @if ($lockedN)<a class="nv nv-locked" href="{{ route('portal.billing') }}"><x-ti n="lock" class="ti ic"/><span>Fitur Pro & All Service<small>{{ $lockedN }} fitur · lihat paket</small></span></a>@endif
    @else
      @foreach ($nav as [$route, $label, $icon])
        <a class="nv {{ request()->routeIs($route.'*') ? 'on' : '' }}" href="{{ route($route) }}"><x-ti :n="$icon" class="ti ic"/>{{ $label }}</a>
      @endforeach
      <div class="side-bottom">
        <form method="post" action="{{ route('portal.logout') }}">@csrf<button class="nv" type="submit"><x-ti n="logout" class="ti ic"/>Keluar</button></form>
      </div>
    @endif
  </nav>
  <div class="main">
    <header class="top">
      <div class="who">
        <label for="nav" class="menu" aria-label="Buka menu"><x-ti n="menu-2"/></label>
        @if ($logoUrl)<img src="{{ $logoUrl }}" alt="" style="width:34px;height:34px;object-fit:contain;border-radius:8px;background:#fff;flex:none">@endif
        <span class="portal-heading"><b>{{ $school->name }}</b><small>{{ $school->subdomain }}.{{ config('tenancy.central_domain') }} · Portal Sekolah</small></span>
      </div>
      <div class="who">
        <span class="chip hm">{{ $u->role->label() }}</span>
        @php $unread = \App\Models\UserNotification::query()->where('user_id', $u->id)->whereNull('read_at')->count(); @endphp
        <a class="top-bell" href="/{{ \App\Http\Controllers\Portal\PwaController::appForRole($u->role->value) }}#/notif" aria-label="Pemberitahuan{{ $unread ? ', '.$unread.' belum dibaca' : '' }}"><x-ti n="bell"/>@if ($unread)<span class="top-bell-n">{{ $unread > 99 ? '99+' : $unread }}</span>@endif</a>
        <details class="um"><summary aria-label="Menu akun"><span class="av">{{ $initials }}</span></summary><div class="um-pop" role="menu">
          <div class="um-head"><b>{{ $u->name }}</b><span class="mu">{{ $u->role->label() }}</span></div>
          <a href="/{{ \App\Http\Controllers\Portal\PwaController::appForRole($u->role->value) }}#/profil" role="menuitem"><x-ti n="user" class="ti ic"/>Profil saya</a>
          <form method="post" action="{{ route('portal.logout') }}">@csrf<button type="submit" role="menuitem"><x-ti n="logout" class="ti ic"/>Keluar</button></form></div></details>
      </div>
    </header>
    <main class="ct">
      @if (session('ok'))<div class="alert ok">{{ session('ok') }}</div>@endif
      @if (session('temp_password'))<div class="alert ok"><b>Password sementara untuk {{ session('temp_password.name') }}</b> (username {{ session('temp_password.username') }}): <span class="secret">{{ session('temp_password.password') }}</span> · ditampilkan sekali, wajib diganti saat login.</div>@endif
      @if ($u->must_change_password && ! session()->has(\App\Services\Platform\Impersonation::SESSION_KEY))<div class="alert wr">Kamu masih memakai password awal. Ganti lewat <a href="/app#/profil">aplikasi → Profil</a>.</div>@endif
      @if (! $school->isVerified() && $u->role === \App\Enums\Role::Admin)<div class="alert wr">Sekolah belum terverifikasi Parama Tech: maksimal {{ config('tenancy.unverified_student_quota') }} siswa dan guru/siswa belum bisa masuk. Isi NPSN di <a href="{{ route('portal.school') }}">Profil Sekolah</a>.</div>@endif
      @if ($billBanner)<div class="alert {{ $billBanner[0] }} billbar"><span>{{ $billBanner[1] }}</span><a class="btn nf" href="{{ route('portal.billing') }}">Buka Langganan</a></div>@endif
      @yield('content')
      <p class="foot">MyPresence oleh ParamaEdu.Tech · Solusi Digital untuk Sekolah Modern</p>
    </main>
  </div>
</div>
<script @nonce>document.addEventListener('click', (e) => document.querySelectorAll('details.um[open]').forEach((d) => { if (!d.contains(e.target)) d.open = false; }));</script>
<script @nonce>/* Log: ingat buka/tutup per halaman */document.querySelectorAll('details.logbox[data-key]').forEach((d) => { const k = 'mp_log_' + d.dataset.key; try { if (!d.open && localStorage.getItem(k) === '1') d.open = true; } catch (_) {} d.addEventListener('toggle', () => { try { localStorage.setItem(k, d.open ? '1' : '0'); } catch (_) {} }); });</script>
</body>
</html>
