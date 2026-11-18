@extends('panel.layout')
@section('title', 'Buku kas')
@section('content')
@php
  $rp = fn ($n) => ($n < 0 ? '−' : '').\App\Models\Invoice::rupiah(abs((int) $n));
  $e = $edit;
  $dir = old('direction', $e?->direction ?? request('arah', 'out'));
  $dir = in_array($dir, ['in', 'out'], true) ? $dir : 'out';
  $src = ['invoice' => ['Tagihan', 'in'], 'payroll' => ['Penggajian', 'wr'], 'manual' => ['Manual', 'mu']];
  $q = request()->only(['bulan', 'tahun', 'dari', 'sampai']);
@endphp
<h2>Keuangan</h2>
<p class="sub">Buku kas: pembayaran invoice &amp; gaji masuk otomatis. Catat sendiri pengeluaran operasional, modal, atau pendapatan lain.</p>
@include('panel.finance.tabs')

<form class="card" method="get" action="{{ route('panel.finance.ledger') }}">
  <div class="row" style="align-items:flex-end">
    <div><label for="fb">Bulan</label><input id="fb" type="month" name="bulan" value="{{ request('bulan', $from->format('Y-m')) }}"></div>
    <div><label for="ft">atau tahun</label><input id="ft" type="number" name="tahun" min="2020" max="2100" placeholder="{{ now('Asia/Jakarta')->year }}" value="{{ request('tahun') }}"></div>
    <div><label for="fd">atau dari</label><input id="fd" type="date" name="dari" value="{{ request('dari') }}"></div>
    <div><label for="fs">sampai</label><input id="fs" type="date" name="sampai" value="{{ request('sampai') }}"></div>
    <div><label for="fq">Cari kode / keterangan</label><input id="fq" name="cari" value="{{ request('cari') }}" maxlength="60" placeholder="KK/2026/11/0001"></div>
    <div><label for="fa">Arah</label><select id="fa" name="arah"><option value="">Semua</option><option value="in" @selected($arah === 'in')>Masuk</option><option value="out" @selected($arah === 'out')>Keluar</option></select></div>
    <div style="flex:none"><button class="btn" type="submit">Tampilkan</button></div>
  </div>
</form>

<div class="gr">
  <div class="card"><div class="mu">Saldo awal</div><div class="big" style="font-size:20px">{{ $rp($sum['opening']) }}</div><div class="mu">{{ $label }}</div></div>
  <div class="card"><div class="mu">Masuk</div><div class="big" style="font-size:20px;color:var(--ok)">{{ $rp($sum['in']) }}</div></div>
  <div class="card"><div class="mu">Keluar</div><div class="big" style="font-size:20px;color:var(--er)">{{ $rp($sum['out']) }}</div></div>
  <div class="card"><div class="mu">Saldo akhir</div><div class="big" style="font-size:20px;color:var(--pr)">{{ $rp($sum['closing']) }}</div></div>
</div>

<div class="pg-bar">
  <b>{{ $label }} · {{ $rows->count() }} transaksi</b>
  <div class="acts"><a class="btn o" href="{{ route('panel.finance.ledger.export', $q) }}"><x-ti n="download"/> Unduh laporan Excel</a></div>
</div>

<div class="card">
  <div class="sc"><table>
    <tr><th>Tanggal</th><th>Kode</th><th>Keterangan</th><th>Kategori</th><th>Sumber</th><th class="amt">Masuk</th><th class="amt">Keluar</th>@if (! $arah)<th class="amt">Saldo</th>@endif<th></th></tr>
    @if (! $arah && ! $cari)<tr><td></td><td></td><td class="mu">Saldo awal periode</td><td></td><td></td><td></td><td></td><td class="amt"><b>{{ $rp($sum['opening']) }}</b></td><td></td></tr>@endif
    @forelse ($rows as $r)
      <tr>
        <td style="white-space:nowrap">{{ $r['date']->format('d/m/Y') }}</td>
        <td style="white-space:nowrap">@if ($r['code']) <code class="kode {{ $r['direction'] }}">{{ $r['code'] }}</code> @endif</td>
        <td class="wrap">@if ($r['url']) <a href="{{ $r['url'] }}">{{ $r['label'] }}</a> @else {{ $r['label'] }} @endif
          @if ($r['ref']) <div class="mu" style="font-size:11px">{{ \Illuminate\Support\Str::limit($r['ref'], 70) }}</div> @endif</td>
        <td>{{ $r['category_label'] }}</td>
        <td><span class="pl {{ $src[$r['source']][1] }}">{{ $src[$r['source']][0] }}</span></td>
        <td class="amt" style="color:var(--ok)">{{ $r['direction'] === 'in' ? $rp($r['amount']) : '' }}</td>
        <td class="amt" style="color:var(--er)">{{ $r['direction'] === 'out' ? $rp($r['amount']) : '' }}</td>
        @if (! $arah)<td class="amt">{{ $rp($r['balance']) }}</td>@endif
        <td style="white-space:nowrap">
          @if ($r['source'] === 'manual')
            <a class="btn o" style="padding:3px 8px;font-size:12px" href="{{ route('panel.finance.ledger', $q + ['edit' => $r['id']]) }}#kf">Ubah</a>
            <form method="post" action="{{ route('panel.finance.entries.destroy', $r['id']) }}" style="display:inline" data-confirm="Hapus catatan kas ini?">@csrf @method('DELETE')<button class="btn o" type="submit" style="padding:3px 8px;font-size:12px">Hapus</button></form>
          @endif
        </td>
      </tr>
    @empty
      <tr><td colspan="9" class="mu">{{ $cari ? 'Tidak ada transaksi yang cocok dengan pencarian.' : 'Belum ada transaksi di periode ini.' }}</td></tr>
    @endforelse
    <tr><td></td><td></td><td><b>Total</b></td><td></td><td></td><td class="amt"><b>{{ $rp($sum['in']) }}</b></td><td class="amt"><b>{{ $rp($sum['out']) }}</b></td>@if (! $arah)<td class="amt"><b>{{ $rp($sum['closing']) }}</b></td>@endif<td></td></tr>
  </table></div>
</div>

<div class="two">
  <form class="card" id="kf" method="post" action="{{ $e ? route('panel.finance.entries.update', $e->id) : route('panel.finance.entries.store') }}">@csrf @if ($e) @method('PUT') @endif
    <div class="row" style="justify-content:space-between;align-items:center"><b>{{ $e ? 'Ubah catatan kas '.$e->code : 'Catat pemasukan / pengeluaran' }}</b>
      @if ($e) <a href="{{ route('panel.finance.ledger', $q) }}" class="mu">Batal</a> @endif</div>
    <div class="row">
      <div><label for="kd">Arah</label><select id="kd" name="direction"><option value="out" @selected($dir === 'out')>Pengeluaran</option><option value="in" @selected($dir === 'in')>Pemasukan</option></select></div>
      <div><label for="kt">Tanggal</label><input id="kt" type="date" name="entry_date" required value="{{ old('entry_date', $e?->entry_date?->toDateString() ?? now('Asia/Jakarta')->toDateString()) }}"></div>
    </div>
    <label for="kc">Kategori</label>
    <select id="kc" name="category" required>
      <optgroup label="Pengeluaran" data-dir="out">@foreach (\App\Models\CashEntry::OUT as $k => $cv) <option value="{{ $k }}" @selected(old('category', $e?->category) === $k)>{{ $cv[0] }}</option> @endforeach</optgroup>
      <optgroup label="Pemasukan" data-dir="in">@foreach (\App\Models\CashEntry::IN as $k => $cv) <option value="{{ $k }}" @selected(old('category', $e?->category) === $k)>{{ $cv[0] }}</option> @endforeach</optgroup>
    </select>
    <label for="ka">Nominal</label><div class="pp-input"><span>Rp</span><input id="ka" name="amount" class="rpi" inputmode="numeric" required value="{{ old('amount', $e ? number_format($e->amount, 0, ',', '.') : '') }}"></div>
    <label for="kk">Keterangan</label><input id="kk" name="description" maxlength="200" required value="{{ old('description', $e?->description) }}" placeholder="mis. Perpanjangan hosting cPanel 1 tahun">
    <div class="row">
      <div><label for="km">Metode</label><select id="km" name="method"><option value="">—</option>@foreach (\App\Models\CashEntry::METHODS as $k => $l) <option value="{{ $k }}" @selected(old('method', $e?->method) === $k)>{{ $l }}</option> @endforeach</select></div>
      <div><label for="kr">Referensi eksternal (opsional)</label><input id="kr" name="ref" maxlength="120" value="{{ old('ref', $e?->ref) }}" placeholder="no. nota / invoice vendor"></div>
    </div>
    <p class="mu" style="font-size:12px;margin:10px 0 0">Kode referensi dibuat otomatis: <code class="kode in">KM/{{ now('Asia/Jakarta')->format('Y/m') }}/0001</code> untuk pemasukan, <code class="kode out">KK/{{ now('Asia/Jakarta')->format('Y/m') }}/0001</code> untuk pengeluaran.</p>
    <button class="btn" type="submit" style="margin-top:12px">{{ $e ? 'Simpan perubahan' : 'Simpan catatan' }}</button>
    <p class="mu" style="font-size:12px;margin-top:8px">Pembayaran sekolah &amp; gaji tidak perlu dicatat di sini — sudah otomatis. Mulai pakai buku kas? Catat <b>Saldo awal</b> sebesar saldo rekening hari ini.</p>
  </form>

  <div class="card">
    <b>Laba/rugi · {{ $label }}</b>
    <div class="fin-proj" style="margin-top:8px">
      @foreach ($sum['in_by']->where('operating', true) as $c)<div><span>{{ $c['label'] }}</span><b style="color:var(--ok)">{{ $rp($c['amount']) }}</b></div>@endforeach
      <div class="t"><span>Total pendapatan</span><b>{{ $rp($sum['revenue']) }}</b></div>
      @foreach ($sum['out_by']->where('operating', true) as $c)<div><span>{{ $c['label'] }}</span><b style="color:var(--er)">{{ $rp($c['amount']) }}</b></div>@endforeach
      <div class="t"><span>Total beban</span><b>{{ $rp($sum['expense']) }}</b></div>
      <div class="t"><span>{{ $sum['profit'] >= 0 ? 'Laba bersih' : 'Rugi bersih' }}</span><b style="color:{{ $sum['profit'] < 0 ? 'var(--er)' : 'var(--ok)' }}">{{ $rp($sum['profit']) }}</b></div>
    </div>
    @php $non = $sum['in_by']->where('operating', false)->merge($sum['out_by']->where('operating', false)); @endphp
    @if ($non->isNotEmpty())
      <p class="mu" style="font-size:12px;margin-top:10px">Tidak dihitung di laba/rugi: @foreach ($non as $c) {{ $c['label'] }} {{ $rp($c['amount']) }}@if (! $loop->last), @endif @endforeach.</p>
    @endif
  </div>
</div>
<script @nonce>
(() => {
  const d = document.getElementById('kd'), c = document.getElementById('kc');
  const sync = () => { c.querySelectorAll('optgroup').forEach((g) => { const on = g.dataset.dir === d.value; g.hidden = !on; g.disabled = !on; });
    if (c.selectedOptions[0]?.parentElement.disabled) c.value = c.querySelector(`optgroup[data-dir="${d.value}"] option`).value; };
  d.addEventListener('change', sync); sync();
  document.querySelectorAll('input.rpi').forEach((i) => i.addEventListener('input', () => { const v = i.value.replace(/\D/g, ''); i.value = v ? (+v).toLocaleString('id-ID') : ''; }));
})();
</script>
@endsection
