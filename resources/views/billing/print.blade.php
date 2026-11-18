@php
    $rp = fn ($n) => \App\Models\Invoice::rupiah($n);
    $tz = $school->timezone;
    $paid = $invoice->isPaid();
    $title = $paid ? 'KWITANSI' : 'INVOICE';
    $pays = $invoice->payments()->get();
    $partial = $invoice->isPartial();
@endphp
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>{{ $title }} {{ $invoice->number }} — {{ $school->name }}</title>
<link rel="icon" href="{{ asset('img/logo.png') }}">
<style>
@font-face{font-family:Poppins;src:url({{ asset('fonts/poppins-latin-400.woff') }}) format('woff');font-weight:400}
@font-face{font-family:Poppins;src:url({{ asset('fonts/poppins-latin-600.woff') }}) format('woff');font-weight:600}
@font-face{font-family:Poppins;src:url({{ asset('fonts/poppins-latin-700.woff') }}) format('woff');font-weight:700}
*{box-sizing:border-box;margin:0}
body{font-family:Poppins,system-ui,sans-serif;color:#0F1E3A;background:#EEF3FA;font-size:13px;line-height:1.5}
.page{max-width:800px;margin:24px auto;background:#fff;padding:40px clamp(18px,5vw,48px);border-radius:14px;box-shadow:0 10px 40px #0757c91a;position:relative;overflow:hidden}
.hd{display:flex;justify-content:space-between;gap:20px;flex-wrap:wrap;align-items:flex-start;border-bottom:3px solid #0B63E5;padding-bottom:18px}
.brand{display:flex;gap:10px;align-items:center}.brand img{width:48px;height:48px}
.brand b{font-size:20px;letter-spacing:-.5px}.brand b span{color:#1E88E5}
h1{font-size:26px;letter-spacing:2px;color:#0B63E5;text-align:right}
.mu{color:#647797}
.cols{display:grid;grid-template-columns:1fr 1fr;gap:20px;margin:22px 0}
.cols h3{font-size:11px;letter-spacing:1px;color:#647797;font-weight:600;margin-bottom:4px}
table{width:100%;border-collapse:collapse;margin-top:6px}
th{background:#EEF3FA;text-align:left;font-size:11px;letter-spacing:.5px;color:#647797;padding:8px 10px}
td{padding:10px;border-bottom:1px solid #E3E9F3;vertical-align:top}
.r{text-align:right;white-space:nowrap;font-variant-numeric:tabular-nums}
tr.tot td{font-weight:700;font-size:15px;border-bottom:0;border-top:2px solid #0F1E3A}
.stamp{position:absolute;top:120px;right:-10px;transform:rotate(-14deg);border:4px solid;border-radius:10px;padding:6px 18px;font-size:30px;font-weight:700;letter-spacing:4px;opacity:.22}
.stamp.ok{color:#14833B}.stamp.wr{color:#C2410C}.stamp.mu{color:#647797}
.box{margin-top:22px;border:1px dashed #9DB6DC;border-radius:10px;padding:12px 14px;background:#F7FAFE}
.ft{margin-top:28px;font-size:11px;text-align:center}
.bar{max-width:800px;margin:16px auto 0;display:flex;justify-content:flex-end;gap:8px;padding:0 16px}
.bar button{font:inherit;font-weight:600;background:#0B63E5;color:#fff;border:0;border-radius:8px;padding:9px 16px;cursor:pointer}
@media(max-width:560px){.cols{grid-template-columns:1fr}h1{text-align:left}}
@media print{body{background:#fff}.page{box-shadow:none;margin:0;max-width:none;border-radius:0}.bar{display:none}@page{margin:14mm}}
</style>
<script src="/js/csp-actions.js?v=1" defer></script></head>
<body>
<div class="bar"><button type="button" data-action="print">🖨️ Cetak / Simpan PDF</button></div>
<div class="page">
  <div class="stamp {{ $paid ? 'ok' : ($invoice->status === 'void' ? 'mu' : 'wr') }}">{{ $paid ? 'LUNAS' : ($invoice->status === 'void' ? 'BATAL' : ($partial ? 'DP DITERIMA' : 'BELUM LUNAS')) }}</div>
  <div class="hd">
    <div>
      <div class="brand"><img src="{{ asset('img/logo-512.png') }}" alt=""><b>My<span>Presence</span></b></div>
      <div style="margin-top:8px"><b>{{ $seller['name'] }}</b><br><span class="mu">{{ $seller['address'] }}<br>{{ $seller['email'] }} · {{ $seller['phone'] }}@if ($seller['npwp'])<br>NPWP {{ $seller['npwp'] }}@endif</span></div>
    </div>
    <div><h1>{{ $title }}</h1><div class="r" style="margin-top:6px"><b>{{ $invoice->number }}</b><br><span class="mu">Tanggal</span> {{ ($paid ? $invoice->paid_at : $invoice->created_at)->timezone($tz)->translatedFormat('d F Y') }}
      @unless ($paid)<br><span class="mu">Jatuh tempo</span> {{ $invoice->due_at->timezone($tz)->translatedFormat('d F Y') }}@endunless</div></div>
  </div>

  <div class="cols">
    <div><h3>{{ $paid ? 'TELAH DITERIMA DARI' : 'DITAGIHKAN KEPADA' }}</h3><b>{{ $school->name }}</b>@if ($school->npsn)<br>NPSN {{ $school->npsn }}@endif
      @if ($school->address)<br><span class="mu">{{ $school->address }}</span>@endif<br><span class="mu">{{ $school->subdomain }}.{{ config('tenancy.central_domain') }}</span></div>
    <div><h3>LAYANAN</h3>{{ $invoice->typeLabel() }} MyPresence — presensi QR, jurnal &amp; agenda guru<br>
      @if ($invoice->period_end)<span class="mu">Periode {{ $invoice->period_start->translatedFormat('d M Y') }} – {{ $invoice->period_end->translatedFormat('d M Y') }}</span>
      @elseif ($invoice->months)<span class="mu">{{ $invoice->months }} bulan sejak pembayaran diterima</span>@endif</div>
  </div>

  <table>
    <tr><th>Uraian</th><th class="r">Jumlah</th></tr>
    @foreach ($invoice->lines ?? [] as $l)<tr><td>{{ $l['label'] }}</td><td class="r">{{ $l['amount'] < 0 ? '−'.$rp(-$l['amount']) : $rp($l['amount']) }}</td></tr>@endforeach
    <tr class="tot"><td>Total tagihan</td><td class="r">{{ $rp($invoice->total) }}</td></tr>
    @if ($pays->isNotEmpty() && $invoice->status !== 'void')
      @php $bd = $invoice->paymentBreakdown(); @endphp
      <tr><td>DP / uang muka @if ($bd['first_dp'])<span class="mu">({{ $bd['first_dp']->translatedFormat('d M Y') }})</span>@endif</td><td class="r">−{{ $rp($bd['dp']) }}</td></tr>
      <tr><td>Pembayaran tambahan</td><td class="r">−{{ $rp($bd['other']) }}</td></tr>
      <tr class="tot"><td>Sisa tagihan</td><td class="r">{{ $rp($invoice->remaining()) }}</td></tr>
    @endif
  </table>

  @if ($pays->isNotEmpty())
    <h3 style="font-size:11px;letter-spacing:1px;color:#647797;font-weight:600;margin-top:22px">RIWAYAT PEMBAYARAN</h3>
    <table><tr><th>Tanggal</th><th>Jenis</th><th>Metode / referensi</th><th class="r">Nominal</th></tr>
      @foreach ($pays as $p)<tr><td>{{ $p->paid_on->translatedFormat('d M Y') }}</td><td>{{ $p->kindLabel() }}</td><td>{{ $p->methodLabel() }}@if ($p->ref) · {{ $p->ref }}@endif</td><td class="r">{{ $rp($p->amount) }}</td></tr>@endforeach
    </table>
  @endif

  @if ($paid)
    <div class="box">Pembayaran sebesar <b>{{ $rp($invoice->total) }}</b>@if ($pays->count() > 1) (dalam {{ $pays->count() }} kali pembayaran)@endif telah diterima pada {{ $invoice->paid_at->timezone($tz)->translatedFormat('d F Y H:i') }} melalui <b>{{ $invoice->payment_method }}</b>@if ($invoice->payment_ref) (ref. {{ $invoice->payment_ref }})@endif. Kwitansi ini sah tanpa tanda tangan karena diterbitkan secara elektronik.</div>
  @elseif ($invoice->isPayable())
    <div class="box"><b>Cara pembayaran</b><br>@unless ($partial)1. Online melalui portal: {{ $school->url('langganan/tagihan/'.$invoice->id) }}<br>@endunless
      2. Transfer ke {{ $bank['name'] }} <b>{{ $bank['account'] }}</b> a.n. {{ $bank['holder'] }} — nominal tepat {{ $rp($invoice->remaining()) }}, berita <b>{{ $invoice->number }}</b>, lalu unggah bukti di portal.</div>
  @endif
  @unless ($paid)
  <div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;margin-top:30px;text-align:center">
    <div>Dibuat oleh,<div style="height:60px"></div><b>({{ $seller['name'] }})</b></div>
    <div>Disetujui oleh,<div style="height:60px"></div><b>({{ $school->name }})</b></div>
  </div>
  @endunless
  <p class="ft mu">{{ $seller['name'] }} · MyPresence — Solusi Digital untuk Sekolah Modern</p>
</div>
</body>
</html>
