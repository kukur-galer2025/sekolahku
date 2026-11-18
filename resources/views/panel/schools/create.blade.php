@extends('panel.layout')
@section('title', 'Buat sekolah')
@section('content')
<h2>Buat sekolah baru</h2>
<p class="sub">Untuk sekolah yang mendaftar lewat tim sales. Akun admin dibuat dengan password sementara dan email verifikasi dikirim.</p>
<form class="card" method="post" action="{{ route('panel.schools.store') }}">
  @csrf
  <div class="form-grid">
    <div class="full"><label for="name">Nama sekolah</label><input id="name" name="name" value="{{ old('name') }}" required></div>
    <div><label for="npsn">NPSN</label><input id="npsn" name="npsn" value="{{ old('npsn') }}" maxlength="8" inputmode="numeric"></div>
    <div><label for="level">Jenjang</label><select id="level" name="level"><option value="">Otomatis dari nama sekolah</option>@foreach (\App\Support\SchoolLevel::LABELS as $v => $l)<option value="{{ $v }}" @selected(old('level') === $v)>{{ $l }}</option>@endforeach</select></div>
    <div><label for="timezone">Zona waktu</label><select id="timezone" name="timezone">@foreach ($timezones as $v => $l)<option value="{{ $v }}" @selected(old('timezone', 'Asia/Jakarta') === $v)>{{ $l }}</option>@endforeach</select></div>
    <div class="full"><label for="address">Alamat sekolah</label><input id="address" name="address" value="{{ old('address') }}" placeholder="Jl. …, Kel./Desa, Kecamatan, Kabupaten/Kota"></div>
    <div class="full"><label for="access_code">Kode sekolah (opsional)</label><input id="access_code" name="access_code" value="{{ old('access_code') }}" maxlength="20" autocomplete="off" style="text-transform:uppercase" placeholder="mis. SMPN1KSG · kosongkan untuk kode acak">
      <span class="mu" style="font-size:13px">4–12 huruf/angka. Dipakai guru & siswa di {{ \App\Models\School::codeEntryUrl('app') }}.</span>@error('access_code')<div class="err">{{ $message }}</div>@enderror</div>
    <div class="full"><label for="subdomain">Link portal</label><div class="suffix"><input id="subdomain" name="subdomain" value="{{ old('subdomain') }}" required autocomplete="off"><span>.{{ config('tenancy.central_domain') }}</span></div></div>
    <div><label for="status">Status awal</label><select id="status" name="status"><option value="trial" @selected(old('status') === 'trial')>Trial {{ config('tenancy.trial_days') }} hari</option><option value="active" @selected(old('status') === 'active')>Aktif (berbayar)</option></select></div>
    <div><label for="active_until">Aktif sampai (untuk status Aktif)</label><input id="active_until" name="active_until" type="date" value="{{ old('active_until') }}"></div>
    <div><label for="plan">Paket langganan</label><select id="plan" name="plan">@foreach (\App\Support\Plan::all() as $k => $p)<option value="{{ $k }}" @selected(old('plan', \App\Support\Plan::default()) === $k)>{{ $p['label'] }} · {{ \App\Support\Plan::priceLabel($k) }}/siswa/bulan</option>@endforeach</select></div>
    <div><label for="student_quota">Kuota siswa (paket per jumlah siswa)</label><input id="student_quota" name="student_quota" type="number" min="1" value="{{ old('student_quota', config('tenancy.trial_student_quota')) }}" required></div>
  </div>
  <b style="display:block;margin-top:18px">Admin / operator sekolah</b>
  <div class="form-grid">
    <div><label for="admin_name">Nama lengkap admin</label><input id="admin_name" name="admin_name" value="{{ old('admin_name') }}" required></div>
    <div><label for="contact_phone">No. HP</label><input id="contact_phone" name="contact_phone" value="{{ old('contact_phone') }}"></div>
    <div class="full"><label for="admin_email">Email admin</label><input id="admin_email" name="admin_email" type="email" value="{{ old('admin_email') }}" required></div>
  </div>
  <button class="btn w" type="submit">Buat sekolah</button>
</form>
@endsection
