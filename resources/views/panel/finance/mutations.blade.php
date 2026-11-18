@extends('panel.layout')
@section('title', 'Mutasi bank')
@section('content')
@php
  $rp = fn ($n) => \App\Models\Invoice::rupiah((int) $n);
  $tabs = ['pending' => 'Belum diproses', 'matched' => 'Cocok invoice', 'recorded' => 'Dicatat di kas', 'ignored' => 'Diabaikan', 'semua' => 'Semua'];
@endphp
<h2>Keuangan</h2>
<p class="sub">Unggah mutasi rekening bisnis (CSV/Excel dari BNIdirect atau internet banking lain). Kredit dicocokkan otomatis ke invoice lewat nomor invoice di berita transfer atau nominal + kode unik.</p>
@include('panel.finance.tabs')

<div class="two">
  <form class="card" method="post" action="{{ route('panel.finance.mutations.import') }}" enctype="multipart/form-data">@csrf
    <b><x-ti n="upload"/> Impor mutasi rekening</b>
    <p class="mu" style="margin-top:2px">File CSV/XLSX dengan kolom <b>Tanggal</b>, <b>Keterangan</b>, dan <b>Kredit/Debit</b> (atau Nominal + D/K). Baris yang sudah pernah diimpor otomatis dilewati.</p>
    <input type="file" name="file" accept=".csv,.xlsx,.txt" required style="margin-top:8px">
    <button class="btn" type="submit" style="margin-top:10px">Impor</button>
  </form>
  <div class="card">
    <b>Cara kerja pencocokan</b>
    <ol class="mu" style="margin:6px 0 0 18px;padding:0;font-size:13px;line-height:1.6">
      <li>Nomor invoice tertulis di berita transfer → langsung disarankan.</li>
      <li>Nominal sama persis dengan sisa satu tagihan (kode unik 3 digit membuat tiap tagihan berbeda).</li>
      <li>Periksa saran, lalu klik <b>Cocokkan</b>. Pembayaran tercatat sebagai DP/cicilan, atau langsung <b>lunas</b> bila nominal = sisa.</li>
      <li>Debit (pengeluaran) → <b>Catat ke kas</b> dengan kategori, atau <b>Abaikan</b> bila sudah tercatat (mis. gaji di Penggajian).</li>
    </ol>
  </div>
</div>

<nav class="ltabs" style="margin-top:14px">
  @foreach ($tabs as $k => $l)
    <a href="{{ route('panel.finance.mutations', ['status' => $k]) }}" class="{{ $status === $k ? 'on' : '' }}">{{ $l }} @if ($k !== 'semua' && ($counts[$k] ?? 0)) <span class="mu">({{ $counts[$k] }})</span> @endif</a>
  @endforeach
</nav>

@if ($status === 'pending' && $suggestN)
  <form method="post" action="{{ route('panel.finance.mutations.match_all') }}" class="pg-bar" data-confirm="Cocokkan {{ $suggestN }} kredit ke invoice yang disarankan?">@csrf
    <span class="mu">{{ $suggestN }} kredit punya saran invoice.</span>
    <button class="btn" type="submit"><x-ti n="check"/> Cocokkan semua yang disarankan</button>
  </form>
@endif

<div class="card">
  <div class="sc"><table>
    <tr><th>Tanggal</th><th>Keterangan bank</th><th class="amt">Nominal</th><th>Status / tindakan</th></tr>
    @forelse ($list as $m)
      <tr>
        <td style="white-space:nowrap">{{ $m->trx_date->format('d/m/Y') }}</td>
        <td class="wrap" style="max-width:380px">{{ $m->description }} @if ($m->batch) <div class="mu" style="font-size:11px">{{ $m->batch }}</div> @endif</td>
        <td class="amt" style="color:{{ $m->direction === 'in' ? 'var(--ok)' : 'var(--er)' }};white-space:nowrap">{{ $m->direction === 'in' ? '+' : '−' }}{{ $rp($m->amount) }}</td>
        <td style="min-width:320px">
          @if ($m->status === 'pending')
            @if ($m->direction === 'in')
              <form method="post" action="{{ route('panel.finance.mutations.match', $m->id) }}" class="mut-act">@csrf
                <select name="invoice_id" required aria-label="Invoice">
                  <option value="">— pilih invoice —</option>
                  @foreach ($open as $i) <option value="{{ $i->id }}" @selected($m->suggested_invoice_id === $i->id)>{{ $i->number }} · {{ \Illuminate\Support\Str::limit($i->school?->name, 22) }} · sisa {{ $rp($i->remaining()) }}</option> @endforeach
                </select>
                <button class="btn" type="submit">Cocokkan</button>
              </form>
              @if ($m->suggest_reason) <div class="mu" style="font-size:11px;margin-top:2px">{{ $m->suggested_invoice_id ? 'Saran: '.$m->suggest_reason : $m->suggest_reason }}</div> @endif
            @endif
            <form method="post" action="{{ route('panel.finance.mutations.record', $m->id) }}" class="mut-act" style="margin-top:4px">@csrf
              <select name="category" required aria-label="Kategori kas">
                @foreach ($m->direction === 'in' ? \App\Models\CashEntry::IN : \App\Models\CashEntry::OUT as $k => $cv) <option value="{{ $k }}">{{ $cv[0] }}</option> @endforeach
              </select>
              <button class="btn o" type="submit">Catat ke kas</button>
              <button class="btn o" type="submit" form="ign{{ $m->id }}">Abaikan</button>
            </form>
            <form id="ign{{ $m->id }}" method="post" action="{{ route('panel.finance.mutations.ignore', $m->id) }}">@csrf</form>
          @elseif ($m->status === 'matched')
            <span class="pl ok">Cocok</span> <a href="{{ route('panel.billing.show', $m->invoice_id) }}">{{ $m->invoice?->number }}</a>
            <div class="mu" style="font-size:11px">{{ $m->handler?->name }} · {{ $m->handled_at?->timezone('Asia/Jakarta')->format('d/m H:i') }}</div>
          @elseif ($m->status === 'recorded')
            <span class="pl in">Dicatat di kas</span> <a href="{{ route('panel.finance.ledger', ['bulan' => $m->trx_date->format('Y-m'), 'cari' => $m->cashEntry?->code]) }}"><code class="kode {{ $m->direction }}">{{ $m->cashEntry?->code ?? 'lihat' }}</code></a>
          @else
            <span class="pl mu">Diabaikan</span>
            <form method="post" action="{{ route('panel.finance.mutations.ignore', $m->id) }}" style="display:inline">@csrf<button class="btn o" type="submit" style="padding:3px 8px;font-size:12px">Kembalikan</button></form>
          @endif
        </td>
      </tr>
    @empty
      <tr><td colspan="4" class="mu" style="padding:14px">{{ $status === 'pending' ? 'Tidak ada mutasi yang perlu diproses. Impor file mutasi terbaru dari internet banking.' : 'Belum ada data.' }}</td></tr>
    @endforelse
  </table></div>
  {{ $list->links() }}
</div>
@endsection
