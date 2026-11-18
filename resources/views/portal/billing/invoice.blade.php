@extends('portal.layout')
@section('title', 'Tagihan '.$invoice->number)
@section('content')
@php
    $rp = fn ($n) => \App\Models\Invoice::rupiah($n);
    $tz = $school->timezone;
    $pill = ['unpaid' => ['Belum dibayar', 'wr'], 'paid' => ['Lunas', 'ok'], 'void' => ['Dibatalkan', 'mu']][$invoice->status];
@endphp
<p class="mu"><a href="{{ route('portal.billing') }}">← Langganan</a></p>
<h2>Tagihan {{ $invoice->number }} <span class="pl {{ $pill[1] }}" style="vertical-align:middle;font-size:13px">{{ $pill[0] }}</span></h2>
<p class="sub">{{ $invoice->typeLabel() }} · dibuat {{ $invoice->created_at->timezone($tz)->translatedFormat('d F Y') }}@if ($invoice->isPayable()) · jatuh tempo {{ $invoice->due_at->timezone($tz)->translatedFormat('d F Y') }}@endif</p>
@if ($errors->any())<div class="alert er">{{ $errors->first() }}</div>@endif

<div class="two">
  <div class="card">
    <b>Rincian</b>
    <div class="sum" style="margin-top:10px">
      @foreach ($invoice->lines ?? [] as $l)<span>{{ $l['label'] }}</span><span>{{ $l['amount'] < 0 ? '−'.$rp(-$l['amount']) : $rp($l['amount']) }}</span>@endforeach
      <span class="t">Total</span><span class="t">{{ $rp($invoice->total) }}</span>
      @if ($invoice->paid_amount > 0 && $invoice->status !== 'void')
        @php $bd = $invoice->paymentBreakdown(); @endphp
        <span>DP / uang muka</span><span>−{{ $rp($bd['dp']) }}</span>
        @if ($bd['other'])<span>Pembayaran tambahan</span><span>−{{ $rp($bd['other']) }}</span>@endif
        <span class="t">Sisa tagihan</span><span class="t">{{ $rp($invoice->remaining()) }}</span>
      @endif
    </div>
    @if ($invoice->isPaid())
      <div class="kv" style="margin-top:16px">
        <span class="mu">Dibayar</span><span>{{ $invoice->paid_at->timezone($tz)->translatedFormat('d F Y H:i') }}</span>
        <span class="mu">Metode</span><span>{{ $invoice->payment_method }}</span>
        @if ($invoice->period_end)<span class="mu">Periode</span><span>{{ $invoice->period_start->format('d/m/Y') }} – {{ $invoice->period_end->format('d/m/Y') }}</span>@endif
      </div>
    @elseif ($invoice->status === 'void')
      <p class="mu" style="margin-top:14px">{{ $invoice->notes }}</p>
    @endif
    <p style="margin-top:16px"><a class="btn o" href="{{ route('portal.billing.print', $invoice) }}" target="_blank" rel="noopener"><x-ti n="printer"/> {{ $invoice->isPaid() ? 'Cetak kwitansi' : 'Cetak invoice' }}</a></p>
  </div>

  @if ($invoice->isPayable())
  <div>
    @if ($invoice->isPartial())
    <div class="alert in">DP {{ $rp($invoice->paid_amount) }} sudah diterima. Lunasi sisa <b>{{ $rp($invoice->remaining()) }}</b> lewat transfer bank, lalu unggah buktinya.</div>
    @endif
    @if ($online && ! $invoice->isPartial())
    <div class="card">
      <b>Bayar online</b>
      <p class="mu" style="margin-top:2px">Virtual account bank, QRIS, e-wallet, atau kartu. Layanan aktif otomatis setelah pembayaran berhasil.</p>
      <form method="post" action="{{ route('portal.billing.pay', $invoice) }}">@csrf<button class="btn w" type="submit">Bayar {{ $rp($invoice->total) }}</button></form>
      @if ($invoice->gateway_ref)
        <form method="post" action="{{ route('portal.billing.check', $invoice) }}" style="text-align:center;margin-top:10px">@csrf<button class="linkbtn" type="submit">Sudah bayar? Cek status pembayaran</button></form>
      @endif
    </div>
    @endif
    <div class="card">
      <b>{{ $online ? 'Atau transfer bank' : 'Transfer bank' }}</b>
      <div class="bank" style="margin-top:8px">{{ $bank['name'] }}<br><b class="no">{{ $bank['account'] }}</b><br>a.n. {{ $bank['holder'] }}<br>
        Nominal tepat: <b>{{ $rp($invoice->remaining()) }}</b> · berita: <b>{{ $invoice->number }}</b></div>
      @if ($invoice->transfer_proof_at)
        <div class="alert ok" style="margin:12px 0 0">Bukti transfer diterima {{ $invoice->transfer_proof_at->timezone($tz)->format('d/m/Y H:i') }} @if ($proofUrl)(<a href="{{ $proofUrl }}" target="_blank" rel="noopener">lihat</a>)@endif. Menunggu konfirmasi Parama Tech.</div>
      @endif
      <form method="post" action="{{ route('portal.billing.proof', $invoice) }}" enctype="multipart/form-data" style="margin-top:6px">
        @csrf
        <label for="sender">Nama pengirim / rekening asal</label><input id="sender" name="sender" value="{{ old('sender') }}" required maxlength="100" placeholder="mis. Bendahara SMK … / BRI 1234">
        <label class="dz"><b>{{ $invoice->transfer_proof_at ? 'Ganti bukti transfer' : 'Unggah bukti transfer' }}</b><div class="mu">JPG/PNG/WebP/PDF, maks. 5 MB</div><input type="file" name="proof" accept="image/jpeg,image/png,image/webp,application/pdf" required></label>
        <button class="btn o" type="submit">Kirim bukti</button>
      </form>
    </div>
  </div>
  @endif
</div>
@endsection
