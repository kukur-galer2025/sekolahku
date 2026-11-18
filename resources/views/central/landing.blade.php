@extends('central.layout')
@section('title', 'MyPresence')
@section('content')
<section class="hero">
  <div>
    <span class="chip">Presensi QR · Jadwal · Jurnal · Agenda</span>
    <h1 style="margin-top:12px">Satu portal untuk <span>kehadiran dan kegiatan</span> sekolahmu</h1>
    <p class="lead">Siswa scan QR kelas dari HP sendiri, di WiFi sekolah, saat jam pelajaran. Guru dan wali kelas langsung melihat rekap; yang tidak scan tercatat alpa otomatis sesuai zona waktu sekolah.</p>
    <div class="row" style="gap:10px"><a class="btn nf" href="{{ route('central.register') }}">Coba gratis {{ config('tenancy.trial_days') }} hari</a><a class="btn o nf" href="#masuk">Sudah terdaftar? Masuk</a></div>
  </div>
  <form class="card" id="masuk" method="post" action="{{ route('central.go') }}">
    @csrf
    <b>Masuk ke portal sekolah</b>
    <p class="mu" style="margin-top:4px">Setiap sekolah punya link portal sendiri.</p>
    <label for="sd">Link portal sekolah</label>
    <div class="suffix"><input id="sd" name="subdomain" value="{{ old('subdomain') }}" placeholder="smkn1-contoh" required autocomplete="off"><span>.{{ config('tenancy.central_domain') }}</span></div>
    @error('subdomain')<div class="err">{{ $message }}</div>@enderror
    <button class="btn w" type="submit">Buka portal sekolah</button>
    <a class="btn o w nf" href="{{ route('central.code', 'app') }}" style="margin-top:10px">Guru & siswa: masuk dengan kode sekolah</a>
  </form>
</section>
<div class="feat">
  <div class="card"><b>Data terpisah per sekolah</b><span class="mu">Setiap sekolah hanya melihat datanya sendiri. Diuji otomatis setiap kali sistem diperbarui.</span></div>
  <div class="card"><b>Impor dari Excel</b><span class="mu">Data guru, siswa, Jam KBM, dan jadwal langsung dari format yang biasa dipakai sekolah.</span></div>
  <div class="card"><b>Presensi sulit dicurangi</b><span class="mu">WiFi sekolah, satu akun satu HP, dan hanya saat jam pelajaran kelasnya.</span></div>
  <div class="card"><b>Bayar sesuai jumlah siswa</b><span class="mu">Mulai dengan trial {{ config('tenancy.trial_days') }} hari hingga {{ config('tenancy.trial_student_quota') }} siswa.</span></div>
</div>
@endsection
