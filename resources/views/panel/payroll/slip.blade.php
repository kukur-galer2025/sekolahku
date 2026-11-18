@php
  $rp = fn ($n) => ($n < 0 ? '−' : '').'Rp'.number_format(abs((int) $n), 0, ',', '.');
  $E = \App\Models\PayrollItem::EARNINGS; $D = \App\Models\PayrollItem::DEDUCTIONS;
@endphp
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title>Slip gaji {{ $run->label() }}{{ $items->count() === 1 ? ' — '.$items->first()->name : '' }}</title>
<style>
@font-face{font-family:Poppins;src:url({{ asset('fonts/poppins-latin-400.woff') }}) format('woff');font-weight:400}
@font-face{font-family:Poppins;src:url({{ asset('fonts/poppins-latin-600.woff') }}) format('woff');font-weight:600}
@font-face{font-family:Poppins;src:url({{ asset('fonts/poppins-latin-700.woff') }}) format('woff');font-weight:700}
*{box-sizing:border-box;margin:0}
body{font-family:Poppins,system-ui,sans-serif;color:#0F1E3A;background:#EEF3FA;font-size:12.5px;line-height:1.5}
.slip{max-width:760px;margin:20px auto;background:#fff;padding:28px clamp(16px,4vw,36px);border-radius:12px;box-shadow:0 10px 40px #0757c91a;page-break-after:always}
.slip:last-child{page-break-after:auto}
.hd{display:flex;justify-content:space-between;gap:16px;align-items:flex-start;border-bottom:3px solid #0B63E5;padding-bottom:12px}
.brand{display:flex;gap:10px;align-items:center}.brand img{width:40px;height:40px}.brand b{font-size:17px}.brand b span{color:#1E88E5}
h1{font-size:18px;letter-spacing:2px;color:#0B63E5;text-align:right}
.mu{color:#647797}
.id{display:grid;grid-template-columns:auto 1fr auto 1fr;gap:3px 12px;margin:14px 0}
.cols{display:grid;grid-template-columns:1fr 1fr;gap:18px}
h3{font-size:11px;letter-spacing:1px;font-weight:700;margin:4px 0 4px;padding-bottom:4px;border-bottom:2px solid}
h3.in{color:#15803d;border-color:#16a34a}h3.out{color:#b91c1c;border-color:#dc2626}
table{width:100%;border-collapse:collapse}td{padding:4px 0;border-bottom:1px dashed #E3E9F3}
.r{text-align:right;white-space:nowrap;font-variant-numeric:tabular-nums}
tr.t td{font-weight:700;border-bottom:0;border-top:1.5px solid #0F1E3A}
.net{margin-top:14px;background:#EEF3FA;border-radius:10px;padding:12px 14px;display:flex;justify-content:space-between;align-items:center;font-size:15px}
.net b{font-size:20px;color:#0B63E5}
.sig{display:grid;grid-template-columns:1fr 1fr;gap:20px;margin-top:26px;text-align:center}
.bar{max-width:760px;margin:16px auto 0;display:flex;justify-content:flex-end;padding:0 16px}
.bar button{font:inherit;font-weight:600;background:#0B63E5;color:#fff;border:0;border-radius:8px;padding:9px 16px;cursor:pointer}
@media(max-width:560px){.cols,.id{grid-template-columns:1fr}}
@media print{body{background:#fff}.slip{box-shadow:none;margin:0 auto;border-radius:0}.bar{display:none}@page{margin:12mm}}
</style>
<script src="/js/csp-actions.js?v=1" defer></script></head>
<body>
<div class="bar"><button type="button" data-action="print">🖨️ Cetak / Simpan PDF</button></div>
@foreach ($items as $i)
<div class="slip">
  <div class="hd">
    <div><div class="brand"><img src="{{ asset('img/logo-512.png') }}" alt=""><b>{{ $run->company }}</b></div>
      <div class="mu" style="margin-top:4px">{{ $seller['address'] ?? '' }}</div></div>
    <div><h1>SLIP GAJI</h1><div class="r mu">Periode <b style="color:#0F1E3A">{{ $run->label() }}</b>@if ($run->pay_date)<br>Dibayar {{ ($i->paid_on ?? $run->pay_date)->translatedFormat('d F Y') }}@endif</div></div>
  </div>
  <div class="id">
    <span class="mu">Nama</span><b>{{ $i->name }}</b><span class="mu">NIK / ID</span><span>{{ $i->nik }}</span>
    <span class="mu">Jabatan</span><span>{{ $i->position ?: '—' }}</span><span class="mu">Status kerja</span><span>{{ \App\Models\Employee::STATUSES[$i->work_status] ?? '—' }}</span>
    <span class="mu">Hari kerja</span><span>{{ $i->work_days }} hari</span><span class="mu">Hari hadir</span><span>{{ $i->present_days }} hari</span>
  </div>
  <div class="cols">
    <div><h3 class="in">PENDAPATAN</h3><table>
      @foreach ($E as $k => $l)<tr><td>{{ $l }}</td><td class="r">{{ $rp($i->{$k}) }}</td></tr>@endforeach
      <tr class="t"><td>Total pendapatan</td><td class="r">{{ $rp($i->gross) }}</td></tr></table></div>
    <div><h3 class="out">POTONGAN</h3><table>
      @foreach ($D as $k => $l)<tr><td>{{ $l }}</td><td class="r">{{ $rp($i->{$k}) }}</td></tr>@endforeach
      <tr class="t"><td>Total potongan</td><td class="r">{{ $rp($i->deductions) }}</td></tr></table></div>
  </div>
  <div class="net"><span>GAJI BERSIH (take home pay)</span><b>{{ $rp($i->net) }}</b></div>
  <p class="mu" style="margin-top:8px">Status: <b style="color:#0F1E3A">{{ $i->statusLabel() }}</b>@if ($i->pay_status === 'parsial') · sudah dibayar {{ $rp($i->paidValue()) }}, sisa {{ $rp(max(0, $i->net - $i->paidValue())) }}@endif
    @if ($i->method) · {{ \App\Models\PayrollItem::METHODS[$i->method] ?? $i->method }}@endif
    @if ($i->employee?->bank_account) · {{ $i->employee->bank_name }} {{ $i->employee->bank_account }}@endif</p>
  <div class="sig">
    <div>Diterima oleh,<div style="height:54px"></div><b>({{ $i->name }})</b></div>
    <div>Hormat kami,<div style="height:54px"></div><b>({{ $run->company }})</b></div>
  </div>
  <p class="mu" style="margin-top:14px;font-size:10.5px;text-align:center">Dokumen ini bersifat rahasia dan hanya untuk karyawan yang bersangkutan.</p>
</div>
@endforeach
</body>
</html>
