@extends('central.layout')
@section('title', 'Daftar sekolah')
@section('content')
<h2>Daftarkan sekolahmu</h2>
<p class="sub">Trial gratis {{ config('tenancy.trial_days') }} hari, hingga {{ config('tenancy.trial_student_quota') }} siswa. Setelah mendaftar, portal sekolah langsung aktif di alamatnya sendiri.</p>
@if ($errors->any())<div class="alert er"><b>Periksa kembali isian berikut:</b><ul>@foreach ($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul></div>@endif
<form class="card" method="post" action="{{ route('central.register') }}" novalidate>
  @csrf
  <input type="text" name="website" value="" tabindex="-1" autocomplete="off" aria-hidden="true" style="position:absolute;left:-9999px">
  <b>Sekolah</b>
  <div class="form-grid">
    <div class="full"><label for="name">Nama sekolah</label><input id="name" name="name" value="{{ old('name') }}" placeholder="SMK Negeri 1 Contoh" required></div>
    <div><label for="npsn">NPSN (opsional)</label><input id="npsn" name="npsn" value="{{ old('npsn') }}" inputmode="numeric" maxlength="8" placeholder="8 digit"></div>
    <div><label for="level">Jenjang</label><select id="level" name="level"><option value="">Otomatis dari nama sekolah</option>@foreach (\App\Support\SchoolLevel::LABELS as $v => $l)<option value="{{ $v }}" @selected(old('level') === $v)>{{ $l }}</option>@endforeach</select></div>
    <div><label for="timezone">Zona waktu</label><select id="timezone" name="timezone">@foreach ($timezones as $v => $l)<option value="{{ $v }}" @selected(old('timezone', 'Asia/Jakarta') === $v)>{{ $l }}</option>@endforeach</select></div>
    <div class="full"><label for="address">Alamat sekolah</label><input id="address" name="address" value="{{ old('address') }}" maxlength="255" placeholder="Jl. Pendidikan No. 1, Kel./Desa, Kecamatan, Kabupaten/Kota"></div>
    <div class="full"><label for="subdomain">Link portal sekolah</label>
      <div class="suffix"><input id="subdomain" name="subdomain" value="{{ old('subdomain') }}" placeholder="smkn1-contoh" required autocomplete="off" pattern="[a-z0-9-]{3,63}"><span>.{{ config('tenancy.central_domain') }}</span></div>
      <div class="fieldmsg mu" id="sdmsg">Huruf kecil, angka, dan tanda hubung. Contoh: smkn1-bandung</div></div>
  </div>
  <b style="display:block;margin-top:18px">Admin / operator sekolah</b>
  <p class="mu" style="margin:2px 0 0">Data orang yang mengelola MyPresence di sekolah (bukan data sekolah).</p>
  <div class="form-grid">
    <div><label for="admin_name">Nama lengkap admin</label><input id="admin_name" name="admin_name" value="{{ old('admin_name') }}" required placeholder="mis. Budi Santoso, S.Kom."></div>
    <div><label for="contact_phone">No. HP / WhatsApp</label><input id="contact_phone" name="contact_phone" value="{{ old('contact_phone') }}" type="tel" placeholder="08xxxxxxxxxx" required></div>
    <div class="full"><label for="admin_email">Email (untuk verifikasi & lupa password)</label><input id="admin_email" name="admin_email" type="email" value="{{ old('admin_email') }}" required></div>
    <div><label for="admin_password">Password</label><input id="admin_password" name="admin_password" type="password" autocomplete="new-password" required><div class="fieldmsg mu">Minimal 8 karakter, berisi huruf dan angka.</div></div>
    <div><label for="admin_password_confirmation">Ulangi password</label><input id="admin_password_confirmation" name="admin_password_confirmation" type="password" autocomplete="new-password" required></div>
  </div>
  <label class="check"><input type="checkbox" name="agree" value="1" @checked(old('agree'))><span>Saya menyetujui <a href="{{ route('legal.show', 'syarat-layanan') }}" target="_blank">Syarat Layanan</a> dan <a href="{{ route('legal.show', 'kebijakan-privasi') }}" target="_blank">Kebijakan Privasi</a> MyPresence. Perjanjian Pemrosesan Data disetujui admin sekolah setelah masuk.</span></label>
  <button class="btn w" type="submit">Daftarkan sekolah</button>
</form>
<script @nonce>
(() => {
  const i = document.getElementById('subdomain'), m = document.getElementById('sdmsg'); let t;
  i.addEventListener('input', () => {
    i.value = i.value.toLowerCase().replace(/[^a-z0-9-]/g, '');
    clearTimeout(t); if (i.value.length < 3) { m.className = 'fieldmsg mu'; return; }
    t = setTimeout(async () => {
      try { const r = await (await fetch('{{ route('central.register.check') }}?subdomain=' + encodeURIComponent(i.value), { headers: { Accept: 'application/json' } })).json();
        m.textContent = r.available ? i.value + '.{{ config('tenancy.central_domain') }} tersedia.' : r.message; m.className = 'fieldmsg ' + (r.available ? 'ok' : 'er');
      } catch (e) {}
    }, 350);
  });
})();
</script>
@endsection
