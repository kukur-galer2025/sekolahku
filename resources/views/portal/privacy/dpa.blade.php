@extends('portal.layout')
@section('title', 'Perjanjian Pemrosesan Data')
@section('content')
<h2>Perjanjian Pemrosesan Data Pribadi</h2>
<p class="sub">Sekolah adalah pengendali data siswa, guru, dan pegawai; Parama Tech memprosesnya atas nama sekolah. Sebelum mengelola data, admin yang berwenang perlu menyetujui perjanjian ini (versi {{ $version }}).</p>
@if ($accepted)
  <div class="alert ok">Sudah disetujui oleh <b>{{ $accepted->signer_name }}</b> ({{ $accepted->signer_position }}) pada {{ $accepted->accepted_at->timezone($school->timezone)->format('d/m/Y H:i') }}.</div>
@endif
@if ($errors->any())<div class="alert er">{{ $errors->first() }}</div>@endif
<div class="card">
  <p>Ringkasan isi perjanjian:</p>
  <ul style="margin:8px 0 8px 20px;line-height:1.7">
    <li>Parama Tech memproses data <b>hanya atas perintah sekolah</b>, tidak untuk iklan atau dijual.</li>
    <li>Sekolah memperoleh dan memverifikasi <b>persetujuan orang tua/wali</b> untuk data siswa yang masih anak, lalu mencatat buktinya di menu Data &amp; Privasi.</li>
    <li>Data disimpan di Indonesia, backup harian terenkripsi, akses tim dukungan selalu tercatat di Log Akses.</li>
    <li>Kegagalan pelindungan data diberitahukan tanpa penundaan agar sekolah dapat memenuhi tenggat 3×24 jam (Pasal 46 UU 27/2022).</li>
    <li>Sekolah bisa mengekspor dan meminta penghapusan seluruh datanya kapan saja.</li>
  </ul>
  <p><a href="{{ route('legal.show', 'perjanjian-pemrosesan-data') }}" target="_blank">Baca perjanjian lengkap ↗</a> · <a href="{{ route('legal.show', 'kebijakan-privasi') }}" target="_blank">Kebijakan Privasi ↗</a></p>
</div>
@unless ($accepted)
<form class="card" method="post" action="{{ route('portal.dpa') }}" style="max-width:640px">
  @csrf
  <div class="form-grid">
    <div><label for="signer_name">Nama penandatangan</label><input id="signer_name" name="signer_name" value="{{ old('signer_name', auth()->user()->name) }}" required></div>
    <div><label for="signer_position">Jabatan</label><input id="signer_position" name="signer_position" value="{{ old('signer_position') }}" placeholder="Kepala Sekolah / Operator" required></div>
  </div>
  <label class="check"><input type="checkbox" name="authorized" value="1"><span>Saya berwenang mewakili {{ $school->name }} dan menyetujui Perjanjian Pemrosesan Data Pribadi versi {{ $version }}.</span></label>
  <button class="btn w" type="submit">Setujui perjanjian</button>
</form>
@endunless
@endsection
