@extends('panel.layout')
@section('title', 'Profil saya')
@section('content')
@php
  $ini = collect(preg_split('/\s+/', trim($u->name)))->filter()->take(2)->map(fn ($w) => mb_strtoupper(mb_substr($w, 0, 1)))->implode('') ?: 'SA';
  $tz = 'Asia/Jakarta';
  $err = fn ($k) => $errors->has($k) ? '<div class="ferr">'.e($errors->first($k)).'</div>' : '';
@endphp
<h2>Profil saya</h2>
<p class="sub">Akun super-admin Parama Tech: data akun, password, 2FA, dan perangkat yang sedang login.</p>

@php $photo = \App\Http\Controllers\Panel\ProfileController::photoUrl($u); @endphp
<div class="card prof-head">
  <div class="prof-pic">
    @if ($photo) <img class="prof-av" src="{{ $photo }}" alt="Foto {{ $u->name }}" width="96" height="96"> @else <span class="prof-av" aria-hidden="true">{{ $ini }}</span> @endif
    <form method="post" action="{{ route('panel.profile.photo.upload') }}" enctype="multipart/form-data" id="pf-photo">@csrf
      <label class="prof-cam" title="Unggah foto (JPG)"><x-ti n="camera"/><span class="sr">Unggah foto</span>
        <input type="file" name="photo" accept=".jpg,.jpeg,image/jpeg" hidden></label>
    </form>
  </div>
  <div class="prof-id">
    <b>{{ $u->name }}</b>
    <span class="mu">{{ '@'.$u->username }} @if ($u->email) · {{ $u->email }} @endif @if ($u->phone) · {{ $u->phone }} @endif</span>
    <div class="prof-chips">
      <span class="pl in">Super Admin</span>
      @if ($u->two_factor_confirmed_at) <span class="pl ok"><x-ti n="shield-lock"/> 2FA aktif</span> @else <span class="pl er">2FA belum aktif</span> @endif
      @if ($u->last_login_at) <span class="pl mu">Login terakhir {{ $u->last_login_at->timezone($tz)->translatedFormat('d M Y H:i') }} WIB</span> @endif
    </div>
    <div class="prof-photo-act">
      <button class="btn o" type="button" data-action="click" data-target="#pf-photo input"><x-ti n="camera"/> {{ $photo ? 'Ganti foto' : 'Unggah foto' }}</button>
      @if ($photo)
        <form method="post" action="{{ route('panel.profile.photo.delete') }}" data-confirm="Hapus foto profil?">@csrf @method('DELETE')<button class="btn o" type="submit">Hapus foto</button></form>
      @endif
      <span class="mu" style="font-size:12px">Format JPG/JPEG, maks. 2 MB. Otomatis dipotong persegi.</span>
    </div>
    {!! $err('photo') !!}
  </div>
</div>

<div class="two">
  <form class="card" method="post" action="{{ route('panel.profile.update') }}">@csrf @method('PUT')
    <b><x-ti n="user"/> Data akun</b>
    <label for="pn">Nama lengkap</label><input id="pn" name="name" required maxlength="120" value="{{ old('name', $u->name) }}">{!! $err('name') !!}
    <div class="row">
      <div><label for="pu">Username login</label><input id="pu" name="username" required maxlength="64" autocomplete="username" value="{{ old('username', $u->username) }}">{!! $err('username') !!}</div>
      <div><label for="pp">No. HP / WhatsApp</label><input id="pp" name="phone" maxlength="20" inputmode="tel" value="{{ old('phone', $u->phone) }}">{!! $err('phone') !!}</div>
    </div>
    <label for="pe">Email</label><input id="pe" name="email" type="email" maxlength="255" autocomplete="email" value="{{ old('email', $u->email) }}">{!! $err('email') !!}
    <details class="prof-confirm" @if ($errors->has('current_password') && ! old('password')) open @endif>
      <summary class="mu">Mengubah username atau email? Isi password saat ini</summary>
      <label for="pc1">Password saat ini</label><input id="pc1" name="current_password" type="password" autocomplete="current-password">
      @if (! old('password')) {!! $err('current_password') !!} @endif
    </details>
    <button class="btn" type="submit" style="margin-top:12px">Simpan profil</button>
    <p class="mu" style="font-size:12px;margin-top:8px">Username atau email dipakai untuk login panel.</p>
  </form>

  <form class="card" method="post" action="{{ route('panel.profile.password') }}">@csrf @method('PUT')
    <b><x-ti n="key"/> Ganti password</b>
    <label for="pc2">Password saat ini</label><input id="pc2" name="current_password" type="password" required autocomplete="current-password">
    @if (old('password') !== null) {!! $err('current_password') !!} @endif
    <label for="pw1">Password baru</label><input id="pw1" name="password" type="password" required minlength="10" autocomplete="new-password">{!! $err('password') !!}
    <label for="pw2">Ulangi password baru</label><input id="pw2" name="password_confirmation" type="password" required minlength="10" autocomplete="new-password">
    <p class="mu" style="font-size:12px;margin:8px 0 0">Minimal 10 karakter, berisi huruf dan angka. Setelah diganti, semua perangkat lain otomatis keluar.</p>
    <button class="btn" type="submit" style="margin-top:12px">Ganti password</button>
  </form>
</div>

<div class="card">
  <b><x-ti n="shield-lock"/> Verifikasi dua langkah (2FA)</b>
  @if ($u->two_factor_confirmed_at)
    <p class="mu" style="margin-top:4px">Aktif sejak {{ $u->two_factor_confirmed_at->timezone($tz)->translatedFormat('d F Y') }}. Login panel meminta kode 6 digit dari aplikasi authenticator.
      Sisa kode cadangan: <b style="color:{{ $recoveryLeft <= 3 ? 'var(--er)' : 'var(--tx)' }}">{{ $recoveryLeft }}</b>.</p>
    <div class="two" style="margin-top:6px">
      <form method="post" action="{{ route('panel.profile.recovery') }}" class="prof-sub">@csrf
        <b>Buat ulang kode cadangan</b>
        <p class="mu">10 kode baru; kode lama langsung tidak berlaku. Simpan di pengelola password.</p>
        <label for="pc3">Password saat ini</label><input id="pc3" name="current_password" type="password" required autocomplete="current-password">
        <button class="btn o" type="submit" style="margin-top:8px">Buat kode baru</button>
      </form>
      <form method="post" action="{{ route('panel.profile.2fa_reset') }}" class="prof-sub" data-confirm="2FA lama akan dimatikan dan kamu langsung diminta memindai QR baru. Lanjutkan?">@csrf
        <b>Ganti HP / aplikasi authenticator</b>
        <p class="mu">Matikan 2FA lama lalu aktifkan ulang di perangkat baru.</p>
        <div class="row">
          <div><label for="pc4">Password saat ini</label><input id="pc4" name="current_password" type="password" required autocomplete="current-password"></div>
          <div><label for="p2c">Kode 2FA / cadangan</label><input id="p2c" name="code" required maxlength="20" inputmode="numeric" autocomplete="one-time-code">{!! $err('code') !!}</div>
        </div>
        <button class="btn o" type="submit" style="margin-top:8px">Atur ulang 2FA</button>
      </form>
    </div>
  @else
    <p class="mu" style="margin-top:4px">2FA belum aktif. Akun super-admin bisa mengakses semua sekolah dan data keuangan — aktifkan sekarang.</p>
    <a class="btn" href="{{ route('panel.2fa.setup') }}" style="margin-top:8px">Aktifkan 2FA</a>
  @endif
</div>

<div class="card">
  <b><x-ti n="device-desktop"/> Perangkat yang sedang login</b>
  @if ($sessions)
    <div class="sc" style="margin-top:8px"><table><tr><th>Perangkat</th><th>IP</th><th>Aktif terakhir</th><th></th></tr>
      @foreach ($sessions as $s)
        <tr><td>{{ $s['device'] }}</td><td class="mu">{{ $s['ip'] }}</td><td>{{ $s['last']->diffForHumans() }} <span class="mu">({{ $s['last']->format('d/m H:i') }})</span></td>
          <td>@if ($s['current']) <span class="pl ok">Perangkat ini</span> @endif</td></tr>
      @endforeach
    </table></div>
    <form method="post" action="{{ route('panel.profile.sessions') }}" class="row" style="align-items:flex-end;margin-top:10px">@csrf
      <div><label for="pc5">Password saat ini</label><input id="pc5" name="current_password" type="password" required autocomplete="current-password"></div>
      <div style="flex:none"><button class="btn o" type="submit"><x-ti n="logout"/> Keluarkan perangkat lain</button></div>
    </form>
  @else
    <p class="mu" style="margin-top:4px">Daftar perangkat tidak tersedia (sesi tidak disimpan di database).</p>
  @endif
</div>

<div class="two">
  <div class="card">
    <b><x-ti n="history"/> Aktivitas terakhir saya</b>
    <details class="logbox" data-key="panel-my-activity">
    <summary><span class="lb-t">{{ $activity->count() }} aktivitas terakhir</span><span class="mu lb-hint" style="font-size:12px">disembunyikan · klik Tampilkan untuk melihat</span><span class="lb-b"><span class="lb-show">Tampilkan</span><span class="lb-hide">Sembunyikan</span></span></summary>
    <div class="sc" style="margin-top:8px"><table>
      @forelse ($activity as $l)
        @php [, $cl, $ct] = $l->category(); @endphp
        <tr><td style="white-space:nowrap" class="mu">{{ $l->created_at->timezone($tz)->format('d/m H:i') }}</td><td><span class="pl {{ $ct }}">{{ $cl }}</span></td><td class="wrap">{{ $l->description }}</td></tr>
      @empty <tr><td class="mu">Belum ada aktivitas.</td></tr> @endforelse
    </table></div>
    <p style="margin:8px 0 0"><a class="mu" href="{{ route('panel.logs', ['q' => $u->name]) }}">Lihat semua di Log aktivitas →</a></p>
    </details>
  </div>
  <div class="card">
    <b>Akun super-admin</b>
    <div class="sc" style="margin-top:8px"><table><tr><th>Nama</th><th>Login terakhir</th><th>2FA</th></tr>
      @foreach ($admins as $a)
        @php $ap = \App\Http\Controllers\Panel\ProfileController::photoUrl($a); @endphp
        <tr style="{{ $a->is_active ? '' : 'opacity:.55' }}"><td>@if ($ap) <img class="mini-av" src="{{ $ap }}" alt="" width="24" height="24"> @endif <b>{{ $a->name }}</b> @if ($a->id === $u->id) <span class="mu">(saya)</span> @endif <div class="mu" style="font-size:11px">{{ '@'.$a->username }}</div></td>
          <td>{{ $a->last_login_at?->timezone($tz)->format('d/m/Y H:i') ?? '—' }}</td>
          <td>@if ($a->two_factor_confirmed_at) <span class="pl ok">Aktif</span> @else <span class="pl er">Belum</span> @endif</td></tr>
      @endforeach
    </table></div>
    <p class="mu" style="font-size:12px;margin-top:8px">Akun super-admin baru dibuat dari Terminal server: <code>php artisan platform:superadmin username email "Nama"</code>.</p>
  </div>
</div>
<script @nonce>
(() => {
  const f = document.querySelector('#pf-photo input');
  f?.addEventListener('change', () => {
    const x = f.files[0]; if (!x) return;
    if (!/\.(jpe?g)$/i.test(x.name) || (x.type && x.type !== 'image/jpeg')) { alert('Foto harus berformat JPG/JPEG.'); f.value = ''; return; }
    if (x.size > 2 * 1024 * 1024) { alert('Ukuran foto maksimal 2 MB.'); f.value = ''; return; }
    f.form.submit();
  });
})();
</script>
@endsection
