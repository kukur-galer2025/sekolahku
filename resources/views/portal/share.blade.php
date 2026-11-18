@extends('portal.layout')
@section('title', 'Bagikan Aplikasi')
@section('content')
<h2>Bagikan Aplikasi</h2>
<p class="sub">Sebarkan link atau QR ini ke grup WhatsApp, rapat guru, atau mading. Link aman disebar: tidak memuat password. Akun pribadi tetap dibagikan per orang lewat <a href="{{ route('portal.imports') }}">kartu aktivasi</a>.</p>

<div class="card" style="display:flex;gap:18px;align-items:center;flex-wrap:wrap">
  <div><span class="mu">Kode sekolah</span><div style="font-size:30px;font-weight:800;letter-spacing:5px">{{ \App\Support\SchoolCode::format($school->access_code) }}</div></div>
  <p class="mu" style="flex:1;min-width:220px;margin:0">Cara termudah: guru dan siswa membuka <b>{{ \App\Models\School::codeEntryUrl('siswa') }}</b> atau <b>{{ \App\Models\School::codeEntryUrl('guru') }}</b>, mengetik kode ini, lalu masuk dengan NIS/NIK dan password. Kode disusun oleh ParamaEdu.Tech; hubungi ParamaEdu.Tech bila perlu diganti.</p>
</div>

<div class="two" style="align-items:start">
  @foreach ($apps as $key => $a)
    <div class="card">
      <b style="font-size:17px"><x-ti n="device-mobile"/> Aplikasi {{ $a['label'] }}</b>
      <p class="mu" style="margin:4px 0 12px">{{ $key === 'siswa' ? 'Untuk siswa: scan QR presensi, jadwal, tugas.' : 'Untuk guru & wali kelas: QR kelas, jurnal, jadwal, rekap.' }}</p>
      <div style="display:flex;gap:16px;align-items:center;flex-wrap:wrap">
        <div style="width:150px;background:#fff;border-radius:12px;padding:6px;border:1px solid var(--bd);flex:none">{!! $a['qr'] !!}</div>
        <div style="flex:1;min-width:200px">
          <label for="u-{{ $key }}">Link aplikasi</label>
          <input id="u-{{ $key }}" value="{{ $a['url'] }}" readonly data-action="select">
          <div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:10px">
            <button class="btn o nf" type="button" data-action="copy" data-target="#u-{{ $key }}">Salin link</button>
            <a class="btn nf" href="{{ $a['wa'] }}" target="_blank" rel="noopener">Bagikan ke WhatsApp</a>
            <a class="btn o nf" href="{{ route('portal.share.poster', $key) }}" target="_blank"><x-ti n="printer"/> Poster A4</a>
          </div>
        </div>
      </div>
    </div>
  @endforeach
</div>

<div class="card">
  <b>Cara pasang di HP</b>
  <div class="two" style="margin-top:8px">
    <div><b>Android (Chrome)</b><ol class="mu" style="margin:6px 0 0;padding-left:18px"><li>Buka link atau scan QR.</li><li>Ketuk <b>Pasang aplikasi</b> (atau menu ⋮ → <b>Instal aplikasi</b>).</li><li>Ikon MyPresence muncul di layar HP.</li></ol></div>
    <div><b>iPhone (Safari)</b><ol class="mu" style="margin:6px 0 0;padding-left:18px"><li>Buka link di <b>Safari</b>.</li><li>Ketuk ikon <b>Bagikan</b> (kotak dengan panah).</li><li>Pilih <b>Tambahkan ke Layar Utama</b>.</li></ol></div>
  </div>
  <p class="mu" style="margin-top:12px">Aplikasi Admin (untuk Anda): <a href="{{ $adminUrl }}">{{ $adminUrl }}</a> · jangan dibagikan ke grup.</p>
</div>
@endsection
