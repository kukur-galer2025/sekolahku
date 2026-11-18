@extends('panel.layout')
@section('title', 'Aktifkan 2FA')
@section('content')
<h2>Aktifkan verifikasi 2 langkah</h2>
<p class="sub">Panel super-admin bisa membuka semua sekolah, jadi login wajib memakai kode dari HP selain password.</p>
<div class="card" style="max-width:720px">
  <div class="pf">
    <div style="width:200px;background:#fff;border-radius:12px;padding:8px;border:1px solid var(--bd)">{!! $qr !!}</div>
    <div>
      <ol style="margin:0 0 0 18px;line-height:1.8">
        <li>Buka Google Authenticator, Microsoft Authenticator, atau Authy di HP.</li>
        <li>Pindai QR di samping. Tidak bisa memindai? Masukkan kunci ini secara manual:<br><span class="secret">{{ $secret }}</span></li>
        <li>Masukkan kode 6 digit yang muncul.</li>
      </ol>
      <form method="post" action="{{ route('panel.2fa.setup') }}" style="margin-top:12px">@csrf
        <label for="code">Kode dari aplikasi</label><input id="code" name="code" inputmode="numeric" autocomplete="one-time-code" required style="max-width:200px;font-size:20px;letter-spacing:5px">
        @error('code')<div class="err">{{ $message }}</div>@enderror
        <button class="btn" type="submit" style="margin-top:12px">Aktifkan</button>
      </form>
    </div>
  </div>
</div>
@endsection
