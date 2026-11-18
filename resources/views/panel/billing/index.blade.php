@extends('panel.layout')
@section('title', 'Tagihan')
@section('content')
@php $rp = fn ($n) => \App\Models\Invoice::rupiah($n); @endphp
<h2>Tagihan</h2>
<p class="sub">Semua tagihan sekolah. Gateway aktif: <b>{{ config('billing.gateway') }}</b> · harga per paket diatur di <a href="{{ route('panel.plans') }}">Paket & harga</a>.</p>

<div class="gr">
  <div class="card"><div class="mu">Pendapatan bulan ini</div><div class="big" style="font-size:22px">{{ $rp($summary['month']) }}</div></div>
  <div class="card"><div class="mu">Pendapatan tahun ini</div><div class="big" style="font-size:22px">{{ $rp($summary['year']) }}</div></div>
  <div class="card"><div class="mu">Belum dibayar</div><div class="big" style="font-size:22px">{{ $rp($summary['unpaid']) }}</div><div class="mu">{{ $summary['unpaid_n'] }} tagihan @if ($summary['partial_n']) · {{ $summary['partial_n'] }} sudah DP @endif</div></div>
  <div class="card"><div class="mu">Perlu tindakan</div><div class="big" style="font-size:22px;color:{{ $summary['proof_n'] ? 'var(--er)' : 'inherit' }}">{{ $summary['proof_n'] }}</div><div class="mu">bukti transfer · {{ $summary['locked_n'] }} sekolah diblokir</div></div>
</div>

<form class="card row" method="get">
  <div><label for="q">Cari nomor / sekolah</label><input id="q" name="q" value="{{ $q }}"></div>
  <div><label for="status">Status</label><select id="status" name="status">
    <option value="">Semua</option>
    @foreach (['proof' => 'Bukti transfer menunggu', 'unpaid' => 'Belum dibayar', 'partial' => 'DP / bayar sebagian', 'paid' => 'Lunas', 'void' => 'Batal'] as $k => $v)<option value="{{ $k }}" @selected($status === $k)>{{ $v }}</option>@endforeach
  </select></div>
  <button class="btn nf" type="submit">Terapkan</button>
</form>

<div class="card">
  <div class="sc"><table><tr><th>Nomor</th><th>Sekolah</th><th>Rincian</th><th class="amt">Total</th><th>Jatuh tempo / dibayar</th><th>Status</th></tr>
  @forelse ($invoices as $i)
    <tr><td><a href="{{ route('panel.billing.show', $i->id) }}">{{ $i->number }}</a></td>
      <td><a href="{{ route('panel.schools.show', $i->school_id) }}">{{ $i->school?->name }}</a></td>
      <td class="wrap">{{ $i->description }}</td><td class="amt">{{ $rp($i->total) }}@if ($i->isPartial())<div class="mu" style="font-size:11px">sisa {{ $rp($i->remaining()) }}</div>@endif</td>
      <td>{{ ($i->paid_at ?? $i->due_at)->timezone('Asia/Jakarta')->format('d/m/Y') }}</td>
      <td>@include('panel.billing.pill', ['i' => $i])</td></tr>
  @empty <tr><td colspan="6" class="mu">Belum ada tagihan.</td></tr> @endforelse
  </table></div>
  @include('portal.pager', ['p' => $invoices])
</div>

<div class="card"><b>Notifikasi gateway terakhir</b>
  <div class="sc" style="margin-top:10px"><table><tr><th>Waktu</th><th>Gateway</th><th>Status</th><th>Hasil</th><th>Keterangan</th><th>IP</th></tr>
  @forelse ($events as $e)
    <tr><td>{{ $e->created_at->timezone('Asia/Jakarta')->format('d/m H:i:s') }}</td><td>{{ $e->gateway }}</td><td>{{ $e->status }}</td>
      <td><span class="pl {{ ['applied' => 'ok', 'rejected' => 'er', 'duplicate' => 'mu', 'ignored' => 'mu'][$e->result] ?? 'mu' }}">{{ $e->result }}</span></td><td class="wrap">{{ $e->message }}</td><td>{{ $e->ip }}</td></tr>
  @empty <tr><td colspan="6" class="mu">Belum ada notifikasi.</td></tr> @endforelse
  </table></div></div>
@endsection
