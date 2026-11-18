@extends('portal.layout')
@section('title', 'Profil Sekolah')
@section('content')
<h2>Profil Sekolah</h2>
<p class="sub">Data <b>sekolah</b> (bukan data admin). Nama sekolah, NPSN, dan paket diatur oleh Parama Tech. Nama admin/operator diubah di <a href="/admin#/profil">Profil Saya</a>.</p>
@if ($errors->any())<div class="alert er">{{ $errors->first() }}</div>@endif
<form class="card" method="post" action="{{ route('portal.school') }}" enctype="multipart/form-data" style="max-width:720px">
  @csrf
  <div class="pf">
    <div class="pfl" style="display:flex;flex-direction:column;align-items:center;gap:10px">
      <div style="width:120px;height:120px;border:1px solid var(--bd);border-radius:16px;display:grid;place-items:center;background:var(--sf);overflow:hidden">
        @if ($logoUrl)<img src="{{ $logoUrl }}" alt="Logo {{ $school->name }}" style="width:100%;height:100%;object-fit:contain">@else<span class="mu">Belum ada logo</span>@endif
      </div>
      @if ($logoUrl)<label class="check" style="margin:0"><input type="checkbox" name="remove_logo" value="1"> Hapus logo</label>@endif
    </div>
    <div>
      <div class="kv"><span class="mu">Nama sekolah</span><span><b>{{ $school->name }}</b></span><span class="mu">NPSN</span><span>{{ $school->npsn ?? '-' }}@if ($school->isVerified()) <span style="color:#16a34a">&#10003; terverifikasi</span>@endif</span><span class="mu">Link portal</span><span>{{ $school->subdomain }}.{{ config('tenancy.central_domain') }}</span></div>
      @unless ($school->isVerified())
        <div class="alert wr" style="margin-top:12px">Sekolah belum terverifikasi. Isi NPSN yang benar, lalu Parama Tech akan mencocokkannya dengan data Kemendikbud. Sebelum terverifikasi: maksimal {{ config('tenancy.unverified_student_quota') }} siswa dan guru/siswa belum bisa masuk.</div>
        <label for="npsn">NPSN (8 digit)</label><input id="npsn" name="npsn" inputmode="numeric" maxlength="8" value="{{ old('npsn', $school->npsn) }}">
      @endunless
      <label for="logo">Logo baru (JPG/PNG/WebP, maks. 5 MB)</label><input id="logo" name="logo" type="file" accept="image/jpeg,image/png,image/webp">
      <label for="address">Alamat lengkap sekolah</label><input id="address" name="address" value="{{ old('address', $school->address) }}" maxlength="255" placeholder="Jl. Pendidikan No. 1, Kel./Desa, Kecamatan, Kabupaten/Kota">
      <span class="mu" style="font-size:12px">Tampil di rapor dan surat keterangan. Isi alamat jalan sekolah, bukan nama orang.</span>
      <label for="contact_phone">Telepon sekolah</label><input id="contact_phone" name="contact_phone" value="{{ old('contact_phone', $school->contact_phone) }}">
      <button class="btn" type="submit" style="margin-top:16px">Simpan profil</button>
    </div>
  </div>
</form>

<div class="card" style="max-width:720px">
  <b>Kode sekolah</b>
  <p class="mu" style="margin:4px 0 10px">Guru dan siswa cukup membuka <b>{{ \App\Models\School::codeEntryUrl('siswa') }}</b> atau <b>{{ \App\Models\School::codeEntryUrl('guru') }}</b>,
    mengetik kode ini, lalu masuk dengan NIS/NIK dan password. Kode hanya menunjuk sekolah ini; akun tetap milik sekolah ini saja.</p>
  <div style="font-size:32px;font-weight:800;letter-spacing:6px" id="kode-sekolah">{{ \App\Support\SchoolCode::format($school->access_code) }}</div>
  <p class="mu" style="margin-top:10px;font-size:13px">Kode sekolah disusun oleh ParamaEdu.Tech. Bila kode perlu diganti (mis. tersebar ke luar sekolah), hubungi ParamaEdu.Tech.</p>
</div>
@endsection
