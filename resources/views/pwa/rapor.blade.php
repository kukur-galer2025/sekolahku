@php
    $st = $sheet['structure']; $set = $sheet['settings'];
    [$sem, $year] = [$set['semester'], $set['year']];
@endphp
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Rapor {{ $classroom->name }} · {{ $sem }} {{ $year }} — {{ $school->name }}</title>
<link rel="icon" href="/img/logo.png">
<style>
@font-face{font-family:Poppins;src:url(/fonts/poppins-latin-400.woff) format('woff');font-weight:400}
@font-face{font-family:Poppins;src:url(/fonts/poppins-latin-600.woff) format('woff');font-weight:600}
*{box-sizing:border-box;margin:0}
body{font-family:Poppins,system-ui,sans-serif;color:#10213F;background:#EEF3FA;font-size:12.5px;line-height:1.45}
.bar{max-width:820px;margin:16px auto;padding:0 16px;display:flex;justify-content:space-between;gap:12px;flex-wrap:wrap;align-items:center}
.bar button{font:inherit;font-weight:600;background:#0B63E5;color:#fff;border:0;border-radius:8px;padding:9px 16px;cursor:pointer}
.page{max-width:820px;margin:0 auto 24px;background:#fff;padding:36px 40px;border-radius:12px;box-shadow:0 8px 30px #0757c914}
h1{text-align:center;font-size:16px;letter-spacing:.5px}h1 small{display:block;font-size:13px;font-weight:600}
.id{display:grid;grid-template-columns:1fr 1fr;gap:2px 24px;margin:18px 0}
.id div{display:grid;grid-template-columns:150px 10px 1fr}
table{width:100%;border-collapse:collapse;margin-top:6px}
th,td{border:1px solid #9AAAC4;padding:6px 8px;vertical-align:top;text-align:left}
th{background:#EEF3FA;font-size:11.5px}
td.c{text-align:center;white-space:nowrap}td.grp{background:#F7F9FD;font-weight:600}
.sec{margin-top:14px;font-weight:600}
.sign{display:grid;grid-template-columns:repeat(3,1fr);gap:16px;margin-top:26px;text-align:center}
.sign .sp{height:60px}
.mu{color:#5B6E8F}
@media print{body{background:#fff}.bar{display:none}.page{box-shadow:none;border-radius:0;margin:0;max-width:none;padding:0 4mm;page-break-after:always}.page:last-child{page-break-after:auto}@page{size:A4;margin:12mm}}
@media(max-width:640px){.page{padding:20px 16px}.id{grid-template-columns:1fr}.id div{grid-template-columns:120px 10px 1fr}}
</style>
<script src="/js/csp-actions.js?v=1" defer></script></head>
<body>
<div class="bar"><div><b>Rapor {{ $classroom->name }}</b> · Semester {{ $sem }} {{ $year }} · {{ $rows->count() }} siswa</div><button type="button" data-action="print">🖨️ Cetak / Simpan PDF</button></div>
@foreach ($rows as $r)
  @php $s = $r['student']; $nm = $s['name']; $ex = collect($st['extracurriculars'])->map(fn ($e, $j) => ($r['extras'][$j] ?? '') !== '' ? $e.': '.$r['extras'][$j] : null)->filter(); @endphp
  <div class="page">
    <h1>LAPORAN HASIL BELAJAR<small>(RAPOR)</small></h1>
    <div class="id">
      <div><span>Nama Peserta Didik</span><span>:</span><b>{{ $nm }}</b></div>
      <div><span>Kelas</span><span>:</span><span>{{ $classroom->name }}</span></div>
      <div><span>NISN</span><span>:</span><span>{{ $s['nisn'] ?: ($s['nis'] ?: '-') }}</span></div>
      <div><span>Fase</span><span>:</span><span>{{ $phase }}</span></div>
      <div><span>Sekolah</span><span>:</span><span>{{ $school->name }}</span></div>
      <div><span>Semester</span><span>:</span><span>{{ $sem }}</span></div>
      <div><span>Alamat</span><span>:</span><span>{{ $school->address ?: '-' }}</span></div>
      <div><span>Tahun Pelajaran</span><span>:</span><span>{{ $year }}</span></div>
    </div>
    <table>
      <tr><th style="width:34px">No</th><th style="width:30%">Muatan Pelajaran</th><th style="width:70px">Nilai Akhir</th><th>Capaian Kompetensi</th></tr>
      @foreach ($st['subjects'] as $j => $sub)
        @if ($j === 0)<tr><td colspan="4" class="grp">A. KELOMPOK MATA PELAJARAN UMUM</td></tr>@endif
        @if ($j === 6)<tr><td colspan="4" class="grp">B. KELOMPOK MATA PELAJARAN KEJURUAN</td></tr>@endif
        @php [$m, $n] = $r['competencies'][$j]; $v = $r['scores'][$j]; @endphp
        <tr><td class="c">{{ $j + 1 }}</td><td>{{ $sub['name'] }}</td><td class="c"><b>{{ $v === null ? '-' : rtrim(rtrim(number_format($v, 2, ',', ''), '0'), ',') }}</b></td>
          <td>@if ($m !== ''){{ $nm }} menunjukkan pemahaman dalam {{ $m }}@endif @if ($m !== '' && $n !== '')<br>@endif @if ($n !== ''){{ $nm }} membutuhkan bimbingan dalam {{ $n }}@endif</td></tr>
      @endforeach
    </table>
    <p class="sec">Ekstrakurikuler</p><p>{{ $ex->isNotEmpty() ? $ex->implode(' · ') : '-' }}</p>
    <p class="sec">Ketidakhadiran</p><p>Sakit {{ $r['absences'][0] }} hari · Izin {{ $r['absences'][1] }} hari · Tanpa keterangan {{ $r['absences'][2] }} hari</p>
    <p class="mu" style="text-align:right;margin-top:16px">{{ $set['city'] ?: '…………' }}, {{ $date }}</p>
    <div class="sign">
      <div>Orang Tua/Wali,<div class="sp"></div>…………………………</div>
      <div>Wali Kelas,<div class="sp"></div><b>{{ $homeroom ?: '…………………………' }}</b></div>
      <div>Mengetahui,<br>Kepala Sekolah<div class="sp" style="height:42px"></div><b>{{ $set['principal_name'] ?: '…………………………' }}</b>@if ($set['principal_nip'])<br>NIP. {{ $set['principal_nip'] }}@endif</div>
    </div>
  </div>
@endforeach
</body>
</html>
