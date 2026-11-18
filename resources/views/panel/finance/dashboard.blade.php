@extends('panel.layout')
@section('title', 'Keuangan')
@section('content')
@php
  $rp = fn ($n) => ($n < 0 ? '−' : '').\App\Models\Invoice::rupiah(abs((int) $n));
  $max = max(1, $months->max('in'), $months->max('out'));
  $delta = $prevM['revenue'] ? round(($thisM['revenue'] - $prevM['revenue']) / $prevM['revenue'] * 100) : null;
@endphp
<h2>Keuangan</h2>
<p class="sub">Arus kas ParamaEdu.Tech: pembayaran sekolah, gaji karyawan, dan catatan kas manual — dihitung langsung dari menu Tagihan &amp; Penggajian.</p>
@include('panel.finance.tabs')

@if ($pendingMut)
  <div class="alert wr" style="margin-bottom:14px"><x-ti n="alert-triangle"/> Ada <b>{{ $pendingMut }}</b> baris mutasi bank yang belum diproses. <a href="{{ route('panel.finance.mutations') }}">Proses sekarang</a></div>
@endif

<div class="gr">
  <div class="card"><div class="mu">Saldo kas tercatat</div><div class="big" style="font-size:22px;color:{{ $balance < 0 ? 'var(--er)' : 'var(--pr)' }}">{{ $rp($balance) }}</div><div class="mu"><a href="{{ route('panel.finance.ledger') }}">Lihat buku kas</a></div></div>
  <div class="card"><div class="mu">Pendapatan bulan ini</div><div class="big" style="font-size:22px">{{ $rp($thisM['revenue']) }}</div>
    <div class="mu">@if ($delta !== null) <span style="color:{{ $delta >= 0 ? 'var(--ok)' : 'var(--er)' }}">{{ $delta >= 0 ? '▲' : '▼' }} {{ abs($delta) }}%</span> dari bulan lalu @else bulan lalu {{ $rp($prevM['revenue']) }} @endif</div></div>
  <div class="card"><div class="mu">Laba/rugi bulan ini</div><div class="big" style="font-size:22px;color:{{ $thisM['revenue'] - $thisM['expense'] < 0 ? 'var(--er)' : 'var(--ok)' }}">{{ $rp($thisM['revenue'] - $thisM['expense']) }}</div><div class="mu">beban {{ $rp($thisM['expense']) }}</div></div>
  <div class="card"><div class="mu">Laba/rugi tahun ini</div><div class="big" style="font-size:22px">{{ $rp($year['revenue'] - $year['expense']) }}</div><div class="mu">pendapatan {{ $rp($year['revenue']) }}</div></div>
</div>

<div class="card">
  <div class="row" style="justify-content:space-between;align-items:center"><b>Arus kas 12 bulan</b>
    <span class="mu" style="font-size:12px"><span class="fin-key in"></span> masuk &nbsp; <span class="fin-key out"></span> keluar</span></div>
  <div class="fin-chart" role="img" aria-label="Grafik pemasukan dan pengeluaran 12 bulan terakhir">
    @foreach ($months as $m)
      <div class="fin-col" title="{{ $m['label'] }} · masuk {{ $rp($m['in']) }} · keluar {{ $rp($m['out']) }}">
        <div class="fin-bars"><i class="in" style="height:{{ round($m['in'] / $max * 100, 1) }}%"></i><i class="out" style="height:{{ round($m['out'] / $max * 100, 1) }}%"></i></div>
        <span>{{ $m['label'] }}</span>
      </div>
    @endforeach
  </div>
</div>

<div class="two">
  <div class="card">
    <b>Piutang (sisa tagihan sekolah)</b>
    <div class="fin-aging">
      <div><span class="mu">Belum jatuh tempo</span><b>{{ $rp($aging['current']) }}</b></div>
      <div><span class="mu">Telat 1–30 hari</span><b style="color:var(--wr)">{{ $rp($aging['d30']) }}</b></div>
      <div><span class="mu">Telat 31–60 hari</span><b style="color:var(--er)">{{ $rp($aging['d60']) }}</b></div>
      <div><span class="mu">Telat &gt; 60 hari</span><b style="color:var(--er)">{{ $rp($aging['over']) }}</b></div>
    </div>
    <div class="sc" style="margin-top:10px"><table><tr><th>Invoice</th><th>Sekolah</th><th>Jatuh tempo</th><th class="amt">Sisa</th></tr>
      @forelse ($open as $i)
        <tr><td><a href="{{ route('panel.billing.show', $i->id) }}">{{ $i->number }}</a></td><td>{{ $i->school?->name }}</td>
          <td style="color:{{ $i->due_at->isPast() ? 'var(--er)' : 'inherit' }}">{{ $i->due_at->format('d/m/Y') }}</td><td class="amt">{{ $rp($i->remaining()) }}</td></tr>
      @empty <tr><td colspan="4" class="mu">Tidak ada piutang. 🎉</td></tr> @endforelse
    </table></div>
    @if ($openCount > $open->count()) <p class="mu" style="margin-top:6px"><a href="{{ route('panel.billing', ['status' => 'unpaid']) }}">Lihat semua {{ $openCount }} tagihan</a></p> @endif
  </div>

  <div class="card">
    <b>Proyeksi kas 30 hari</b>
    <div class="fin-proj">
      <div><span>Saldo kas tercatat</span><b>{{ $rp($balance) }}</b></div>
      <div><span>+ Piutang jatuh tempo ≤ 30 hari</span><b style="color:var(--ok)">{{ $rp($due30) }}</b></div>
      <div><span>− Gaji ({{ $lastRun ? 'seperti '.$lastRun->label() : 'belum ada periode' }})</span><b style="color:var(--er)">{{ $rp($payroll) }}</b></div>
      <div><span>− Rata-rata beban operasional 3 bulan</span><b style="color:var(--er)">{{ $rp($avgOps) }}</b></div>
      <div class="t"><span>Perkiraan saldo 30 hari lagi</span><b style="color:{{ $projection < 0 ? 'var(--er)' : 'var(--pr)' }}">{{ $rp($projection) }}</b></div>
    </div>
    <p class="mu" style="font-size:12px;margin-top:6px">Perkiraan kasar dengan asumsi semua piutang dibayar tepat waktu.</p>

    <b style="display:block;margin-top:16px">Masa aktif sekolah habis ≤ 30 hari</b>
    <div class="sc" style="margin-top:8px"><table><tr><th>Sekolah</th><th>Aktif s.d.</th><th>Tagihan</th></tr>
      @forelse ($expiring as $s)
        <tr><td><a href="{{ route('panel.schools.show', $s->id) }}">{{ $s->name }}</a></td><td>{{ $s->active_until->timezone('Asia/Jakarta')->format('d/m/Y') }}</td>
          <td>@if (isset($hasOpen[$s->id])) <span class="pl in">Sudah ditagih</span> @else <span class="pl wr">Belum ditagih</span> @endif</td></tr>
      @empty <tr><td colspan="3" class="mu">Tidak ada sekolah yang habis dalam 30 hari.</td></tr> @endforelse
    </table></div>
  </div>
</div>
@endsection
