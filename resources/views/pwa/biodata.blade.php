@php
  $tgl = fn ($d) => $d ? \Illuminate\Support\Carbon::parse($d)->locale('id')->translatedFormat('j F Y') : null;
  $v = fn ($x) => filled($x) ? e($x) : '<span class="dots"></span>';
@endphp
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex">
<title>Biodata Peserta Didik — {{ $students->count() === 1 ? $students->first()->name : $students->first()?->classroom?->name }}</title>
<link rel="icon" href="/img/logo.png">
<style>
@font-face{font-family:Inter;src:url(/fonts/inter-latin-400.woff) format('woff');font-weight:400}
@font-face{font-family:Inter;src:url(/fonts/inter-latin-700.woff) format('woff');font-weight:700}
*{box-sizing:border-box;margin:0}
body{font-family:"Times New Roman",Times,serif;color:#000;background:#E9EDF2;font-size:12pt;line-height:1.35}
.bar{font-family:Inter,system-ui,sans-serif;font-size:13px;max-width:210mm;margin:16px auto;padding:0 8px;display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap}
.bar p{color:#555;margin-top:2px}
.bar button{font:inherit;font-weight:700;background:#066FD1;color:#fff;border:0;border-radius:8px;padding:9px 16px;cursor:pointer}
.page{width:210mm;min-height:297mm;margin:0 auto 16px;background:#fff;padding:18mm 18mm 14mm 24mm;display:flex;flex-direction:column}
h1{font-size:14pt;text-align:center;letter-spacing:.5px;margin-bottom:8mm;text-decoration:none}
table.bio{width:100%;border-collapse:collapse}
table.bio td{padding:1.5mm 0;vertical-align:top}
td.no{width:9mm}td.lb{width:72mm}td.c{width:5mm}
td.sub{padding-left:5mm}
.dots{display:inline-block;width:100%;border-bottom:1px dotted #555;height:1em}
.foot{margin-top:auto;padding-top:8mm;display:flex;justify-content:space-between;align-items:flex-end;gap:12mm}
.foto{width:30mm;height:40mm;border:1px solid #000;display:flex;align-items:center;justify-content:center;text-align:center;font-size:10pt;color:#444;margin-left:20mm}
.ttd{width:75mm}
.ttd .sp{height:24mm}
@media print{body{background:#fff}.bar{display:none}.page{margin:0;width:auto;min-height:auto;height:296mm;overflow:hidden;page-break-after:always}.page:last-child{page-break-after:auto}@page{size:A4;margin:0}}
@media(max-width:820px){.page{width:auto;min-height:auto;padding:18px}.foto{margin-left:0}}
</style>
<script src="/js/csp-actions.js?v=1" defer></script></head>
<body>
<div class="bar">
  <div><b>Biodata peserta didik · halaman identitas buku rapor</b><p>{{ $students->count() }} siswa · satu halaman A4 per siswa. Tempel pas foto 3×4 di kotak foto setelah dicetak.</p></div>
  <button type="button" data-action="print">Cetak / Simpan PDF</button>
</div>
@forelse ($students as $s)
@php $p = $s->profile; @endphp
<section class="page">
  <h1>KETERANGAN TENTANG DIRI PESERTA DIDIK</h1>
  <table class="bio">
    <tr><td class="no">1.</td><td class="lb">Nama Peserta Didik (Lengkap)</td><td class="c">:</td><td><b>{{ \Illuminate\Support\Str::upper($s->name) }}</b></td></tr>
    <tr><td class="no">2.</td><td class="lb">Nomor Induk / NISN</td><td class="c">:</td><td>{{ $s->nis ?: '…' }} / {{ $s->nisn ?: '…' }}</td></tr>
    <tr><td class="no">3.</td><td class="lb">Tempat, Tanggal Lahir</td><td class="c">:</td><td>{!! filled($p?->birth_place) || $s->birth_date ? e(trim(($p?->birth_place ?? '').($s->birth_date ? ', '.$tgl($s->birth_date) : ''), ', ')) : '<span class="dots"></span>' !!}</td></tr>
    <tr><td class="no">4.</td><td class="lb">Jenis Kelamin</td><td class="c">:</td><td>{!! $v(['L' => 'Laki-laki', 'P' => 'Perempuan'][$s->gender] ?? null) !!}</td></tr>
    <tr><td class="no">5.</td><td class="lb">Agama / Kepercayaan</td><td class="c">:</td><td>{!! $v($p?->religion) !!}</td></tr>
    <tr><td class="no">6.</td><td class="lb">Status dalam Keluarga</td><td class="c">:</td><td>{!! $v($p?->family_status) !!}</td></tr>
    <tr><td class="no">7.</td><td class="lb">Anak ke</td><td class="c">:</td><td>{!! $v($p?->child_order) !!}</td></tr>
    <tr><td class="no">8.</td><td class="lb">Alamat Peserta Didik</td><td class="c">:</td><td>{!! $v($p?->address) !!}</td></tr>
    <tr><td class="no">9.</td><td class="lb">Nomor Telepon Rumah</td><td class="c">:</td><td>{!! $v($p?->phone) !!}</td></tr>
    <tr><td class="no">10.</td><td class="lb">Sekolah Asal</td><td class="c">:</td><td>{!! $v($p?->origin_school) !!}</td></tr>
    <tr><td class="no">11.</td><td class="lb">Diterima di sekolah ini</td><td class="c"></td><td></td></tr>
    <tr><td></td><td class="lb sub">Di kelas</td><td class="c">:</td><td>{!! $v($p?->accepted_class) !!}</td></tr>
    <tr><td></td><td class="lb sub">Pada tanggal</td><td class="c">:</td><td>{!! $v($tgl($p?->accepted_date)) !!}</td></tr>
    <tr><td class="no">12.</td><td class="lb">Nama Orang Tua</td><td class="c"></td><td></td></tr>
    <tr><td></td><td class="lb sub">a. Ayah</td><td class="c">:</td><td>{!! $v($p?->father_name) !!}</td></tr>
    <tr><td></td><td class="lb sub">b. Ibu</td><td class="c">:</td><td>{!! $v($p?->mother_name) !!}</td></tr>
    <tr><td class="no">13.</td><td class="lb">Alamat Orang Tua</td><td class="c">:</td><td>{!! $v($p?->parents_address) !!}</td></tr>
    <tr><td class="no">14.</td><td class="lb">Nomor Telepon Rumah</td><td class="c">:</td><td>{!! $v($p?->parents_phone) !!}</td></tr>
    <tr><td class="no">15.</td><td class="lb">Pekerjaan Orang Tua</td><td class="c"></td><td></td></tr>
    <tr><td></td><td class="lb sub">a. Ayah</td><td class="c">:</td><td>{!! $v($p?->father_job) !!}</td></tr>
    <tr><td></td><td class="lb sub">b. Ibu</td><td class="c">:</td><td>{!! $v($p?->mother_job) !!}</td></tr>
    <tr><td class="no">16.</td><td class="lb">Nama Wali Peserta Didik</td><td class="c">:</td><td>{!! $v($p?->guardian_name) !!}</td></tr>
    <tr><td class="no">17.</td><td class="lb">Alamat Wali Peserta Didik</td><td class="c">:</td><td>{!! $v($p?->guardian_address) !!}</td></tr>
    <tr><td></td><td class="lb sub">Nomor Telepon Rumah</td><td class="c">:</td><td>{!! $v($p?->guardian_phone) !!}</td></tr>
    <tr><td class="no">18.</td><td class="lb">Pekerjaan Wali Peserta Didik</td><td class="c">:</td><td>{!! $v($p?->guardian_job) !!}</td></tr>
  </table>
  <div class="foot">
    <div class="foto">Pas foto<br>3 × 4</div>
    <div class="ttd">{{ $set['city'] ?: '…………' }}, {{ $date }}<br>Kepala {{ $school->name }},<div class="sp"></div>
      <b><u>{{ $set['principal_name'] ?: '……………………………………' }}</u></b><br>NIP. {{ $set['principal_nip'] ?: '……………………………' }}</div>
  </div>
</section>
@empty
<section class="page"><p>Tidak ada siswa aktif di kelas ini.</p></section>
@endforelse
</body>
</html>
