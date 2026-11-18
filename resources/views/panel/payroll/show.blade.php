@extends('panel.layout')
@section('title', 'Gaji '.$run->label())
@section('content')
@php
  $rp = fn ($n) => \App\Models\Invoice::rupiah($n);
  $nf = fn ($n) => number_format((int) $n, 0, ',', '.');
  $E = \App\Models\PayrollItem::EARNINGS; $D = \App\Models\PayrollItem::DEDUCTIONS;
@endphp
<p class="mu"><a href="{{ route('panel.payroll') }}">‹ Semua periode</a></p>
<h2>Gaji {{ $run->label() }}</h2>
<p class="sub">Isi komponen gaji tiap karyawan. Total pendapatan, total potongan, dan gaji bersih dihitung otomatis. Jangan lupa <b>Simpan</b>.</p>
@include('panel.payroll.tabs')

<div class="gr">
  <div class="card"><div class="mu">Karyawan</div><div class="big" style="font-size:22px">{{ $sum['count'] }}</div><div class="mu">{{ $sum['n_paid'] }} dibayar · {{ $sum['n_partial'] }} parsial</div></div>
  <div class="card"><div class="mu">Total pendapatan bruto</div><div class="big" style="font-size:22px" data-sum="gross">{{ $rp($sum['gross']) }}</div><div class="mu">potongan <span data-sum="deductions">{{ $rp($sum['deductions']) }}</span></div></div>
  <div class="card"><div class="mu">Total gaji bersih</div><div class="big" style="font-size:22px;color:var(--pr)" data-sum="net">{{ $rp($sum['net']) }}</div></div>
  <div class="card"><div class="mu">Sudah dibayar</div><div class="big" style="font-size:22px;color:var(--ok)">{{ $rp($sum['paid']) }}</div><div class="mu">belum / parsial {{ $rp($sum['unpaid']) }}</div></div>
</div>

<form method="post" action="{{ route('panel.payroll.update', $run->id) }}" id="pf">@csrf @method('PUT')
<div class="card">
  <div class="row">
    <div style="flex:2"><label for="co">Perusahaan</label><input id="co" name="company" value="{{ old('company', $run->company) }}" maxlength="120" required></div>
    <div><label>Periode</label><input value="{{ $run->label() }}" disabled></div>
    <div><label for="pd">Tanggal pembayaran</label><input id="pd" name="pay_date" type="date" value="{{ old('pay_date', $run->pay_date?->toDateString()) }}"></div>
    <div><label for="wd">Hari kerja</label><input id="wd" name="work_days" type="number" min="0" max="31" value="{{ old('work_days', $run->work_days) }}" required></div>
    <div style="flex:2"><label for="nt">Catatan</label><input id="nt" name="notes" maxlength="200" value="{{ old('notes', $run->notes) }}"></div>
  </div>
</div>

<div class="pg-bar">
  <div class="acts">
    <button class="btn" type="submit"><x-ti n="check"/> Simpan tabel</button>
    <button class="btn o" type="button" id="allPaid" title="Tandai semua baris dibayar">Tandai semua dibayar</button>
  </div>
  <div class="acts">
    <a class="btn o" href="{{ route('panel.payroll.export', $run->id) }}"><x-ti n="download"/> Unduh Excel</a>
    <a class="btn o" href="{{ route('panel.payroll.slip', $run->id) }}" target="_blank" rel="noopener"><x-ti n="printer"/> Cetak semua slip</a>
  </div>
</div>

<div class="pg-wrap">
<table class="pg">
  <thead><tr>
    <th>No</th><th class="stk nm">Nama karyawan<span class="mini">NIK / ID</span></th><th>Jabatan<span class="mini">Status kerja</span></th><th>Hari kerja / hadir</th>
    @foreach ($E as $l)<th class="grp-in">{{ $l }}</th>@endforeach<th class="grp-in">Total pendapatan</th>
    @foreach ($D as $l)<th class="grp-out">{{ $l }}</th>@endforeach<th class="grp-out">Total potongan</th>
    <th class="grp-pay">Gaji bersih</th><th class="grp-pay">Tanggal bayar</th><th class="grp-pay">Metode</th><th class="grp-pay">Status pembayaran</th><th></th>
  </tr></thead>
  <tbody>
  @forelse ($items as $k => $i)
    @php $f = "items[{$i->id}]"; @endphp
    <tr data-row class="{{ $i->pay_status }}">
      <td style="text-align:center">{{ $k + 1 }}</td>
      <td class="stk nm"><input name="{{ $f }}[name]" value="{{ $i->name }}" required maxlength="120" aria-label="Nama"><input name="{{ $f }}[nik]" value="{{ $i->nik }}" required maxlength="40" style="margin-top:3px;font-size:11px" aria-label="NIK"></td>
      <td><input name="{{ $f }}[position]" value="{{ $i->position }}" maxlength="80" aria-label="Jabatan" style="min-width:110px">
        <select name="{{ $f }}[work_status]" style="margin-top:3px;font-size:11px" aria-label="Status kerja"><option value="">—</option>@foreach (\App\Models\Employee::STATUSES as $sk => $sv)<option value="{{ $sk }}" @selected($i->work_status === $sk)>{{ $sv }}</option>@endforeach</select></td>
      <td style="white-space:nowrap"><input class="d2" name="{{ $f }}[work_days]" type="number" min="0" max="31" value="{{ $i->work_days }}" aria-label="Hari kerja"> / <input class="d2" name="{{ $f }}[present_days]" type="number" min="0" max="31" value="{{ $i->present_days }}" aria-label="Hari hadir"></td>
      @foreach ($E as $c => $l)<td><input class="rp" data-in name="{{ $f }}[{{ $c }}]" value="{{ $nf($i->{$c}) }}" inputmode="numeric" aria-label="{{ $l }}"></td>@endforeach
      <td class="calc" data-gross>{{ $nf($i->gross) }}</td>
      @foreach ($D as $c => $l)<td><input class="rp" data-out name="{{ $f }}[{{ $c }}]" value="{{ $nf($i->{$c}) }}" inputmode="numeric" aria-label="{{ $l }}"></td>@endforeach
      <td class="calc" data-ded>{{ $nf($i->deductions) }}</td>
      <td class="calc net" data-net>{{ $nf($i->net) }}</td>
      <td><input type="date" name="{{ $f }}[paid_on]" value="{{ $i->paid_on?->toDateString() }}" aria-label="Tanggal bayar" style="min-width:128px"></td>
      <td><select name="{{ $f }}[method]" aria-label="Metode" style="min-width:110px"><option value="">—</option>@foreach (\App\Models\PayrollItem::METHODS as $mk => $mv)<option value="{{ $mk }}" @selected($i->method === $mk)>{{ $mv }}</option>@endforeach</select></td>
      <td style="min-width:140px"><select name="{{ $f }}[pay_status]" data-st aria-label="Status pembayaran">@foreach (\App\Models\PayrollItem::PAY_STATUSES as $pk => $pv)<option value="{{ $pk }}" @selected($i->pay_status === $pk)>{{ $pv }}</option>@endforeach</select>
        <input class="rp" data-pa name="{{ $f }}[paid_amount]" value="{{ $nf($i->paid_amount) }}" inputmode="numeric" placeholder="Nominal dibayar" style="margin-top:3px" @if ($i->pay_status !== 'parsial') hidden @endif aria-label="Nominal sudah dibayar"></td>
      <td style="white-space:nowrap"><a class="btn o" style="padding:4px 8px;font-size:12px" href="{{ route('panel.payroll.slip', [$run->id, $i->id]) }}" target="_blank" rel="noopener" title="Slip gaji"><x-ti n="printer"/></a>
        <button class="btn o" style="padding:4px 8px;font-size:12px" type="submit" form="del{{ $i->id }}" title="Hapus dari periode"><x-ti n="trash"/></button></td>
    </tr>
  @empty
    <tr><td colspan="23" class="mu" style="padding:16px">Belum ada karyawan di periode ini. Tambahkan dari data karyawan atau impor Excel di bawah.</td></tr>
  @endforelse
  </tbody>
  @if ($items->isNotEmpty())
  <tfoot><tr><td colspan="4" style="text-align:left">TOTAL</td>
    @foreach (array_keys($E) as $c)<td data-tot="{{ $c }}">{{ $nf($items->sum($c)) }}</td>@endforeach<td data-tot="gross">{{ $nf($sum['gross']) }}</td>
    @foreach (array_keys($D) as $c)<td data-tot="{{ $c }}">{{ $nf($items->sum($c)) }}</td>@endforeach<td data-tot="deductions">{{ $nf($sum['deductions']) }}</td>
    <td data-tot="net" style="color:var(--pr)">{{ $nf($sum['net']) }}</td><td colspan="5"></td></tr></tfoot>
  @endif
</table>
</div>
<div class="pg-bar"><div class="acts"><button class="btn" type="submit"><x-ti n="check"/> Simpan tabel</button></div>
  <span class="mu" style="font-size:12px">Nominal BPJS &amp; PPh 21 tidak dihitung otomatis — periksa sebelum pembayaran.</span></div>
</form>

@foreach ($items as $i)<form id="del{{ $i->id }}" method="post" action="{{ route('panel.payroll.items.remove', [$run->id, $i->id]) }}" data-confirm="Hapus {{ $i->name }} dari periode ini?">@csrf @method('DELETE')</form>@endforeach

<div class="two" style="margin-top:14px">
  <form class="card" method="post" action="{{ route('panel.payroll.items.add', $run->id) }}">@csrf
    <b><x-ti n="user-plus"/> Tambah karyawan ke periode</b>
    @if ($available->isEmpty())
      <p class="mu" style="margin-top:6px">Semua karyawan aktif sudah ada di periode ini. <a href="{{ route('panel.payroll.employees') }}">Tambah karyawan baru</a>.</p>
    @else
      <select name="employee_ids[]" multiple size="{{ min(6, $available->count()) }}" style="margin-top:8px" aria-label="Pilih karyawan">@foreach ($available as $a)<option value="{{ $a->id }}">{{ $a->name }} · {{ $a->nik }}</option>@endforeach</select>
      <p class="mu" style="font-size:12px;margin:4px 0 0">Tahan Ctrl/⌘ untuk memilih beberapa. Simpan perubahan tabel dulu sebelum menambah.</p>
      <button class="btn o" type="submit" style="margin-top:8px">Tambahkan</button>
    @endif
  </form>
  <form class="card" method="post" action="{{ route('panel.payroll.import', $run->id) }}" enctype="multipart/form-data">@csrf
    <b><x-ti n="file-spreadsheet"/> Impor dari Excel</b>
    <p class="mu" style="margin-top:2px">Pakai file <i>Template Tabel Penggajian Karyawan</i> (sheet Penggajian, data mulai baris 9). Baris dengan NIK yang sama diperbarui.</p>
    <input type="file" name="file" accept=".xlsx" required style="margin-top:8px">
    <button class="btn o" type="submit" style="margin-top:8px">Impor</button>
  </form>
</div>

<form class="card danger" method="post" action="{{ route('panel.payroll.destroy', $run->id) }}" style="margin-top:14px" data-confirm="Hapus periode {{ $run->label() }} beserta seluruh baris gajinya? Tidak bisa dibatalkan.">@csrf @method('DELETE')
  <b>Hapus periode</b><p class="mu" style="margin-top:2px">Seluruh tabel gaji {{ $run->label() }} dihapus. Unduh Excel dulu bila perlu arsip.</p>
  <button class="btn er" type="submit" style="margin-top:8px">Hapus periode</button>
</form>

<script @nonce>
(() => {
  const num = (v) => +String(v || '').replace(/\D/g, '') || 0;
  const fmt = (n) => (n < 0 ? '−' : '') + Math.abs(n).toLocaleString('id-ID');
  const rows = [...document.querySelectorAll('tr[data-row]')];
  const calc = () => {
    const tot = {};
    rows.forEach((r) => {
      let g = 0, d = 0;
      r.querySelectorAll('input[data-in]').forEach((i) => { const v = num(i.value); g += v; const k = i.name.match(/\[(\w+)\]$/)[1]; tot[k] = (tot[k] || 0) + v; });
      r.querySelectorAll('input[data-out]').forEach((i) => { const v = num(i.value); d += v; const k = i.name.match(/\[(\w+)\]$/)[1]; tot[k] = (tot[k] || 0) + v; });
      r.querySelector('[data-gross]').textContent = fmt(g); r.querySelector('[data-ded]').textContent = fmt(d); r.querySelector('[data-net]').textContent = fmt(g - d);
      tot.gross = (tot.gross || 0) + g; tot.deductions = (tot.deductions || 0) + d; tot.net = (tot.net || 0) + g - d;
    });
    Object.entries(tot).forEach(([k, v]) => { document.querySelectorAll(`[data-tot="${k}"]`).forEach((e) => (e.textContent = fmt(v))); document.querySelectorAll(`[data-sum="${k}"]`).forEach((e) => (e.textContent = 'Rp' + fmt(v))); });
  };
  document.querySelectorAll('.pg input.rp').forEach((i) => i.addEventListener('input', () => { const d = i.value.replace(/\D/g, ''); i.value = d ? (+d).toLocaleString('id-ID') : ''; calc(); }));
  document.querySelectorAll('select[data-st]').forEach((s) => s.addEventListener('change', () => { const r = s.closest('tr'); r.className = s.value; r.querySelector('[data-pa]').hidden = s.value !== 'parsial'; }));
  const payDate = document.getElementById('pd');
  document.getElementById('allPaid')?.addEventListener('click', () => {
    rows.forEach((r) => { const s = r.querySelector('select[data-st]'); s.value = 'dibayar'; s.dispatchEvent(new Event('change'));
      const m = r.querySelector('select[name$="[method]"]'); if (!m.value) m.value = 'transfer';
      const d = r.querySelector('input[name$="[paid_on]"]'); if (!d.value && payDate.value) d.value = payDate.value; });
  });
  // Peringatan bila meninggalkan halaman sebelum menyimpan.
  let dirty = false; const f = document.getElementById('pf');
  f.addEventListener('input', () => (dirty = true)); f.addEventListener('submit', () => (dirty = false));
  window.addEventListener('beforeunload', (e) => { if (dirty) { e.preventDefault(); e.returnValue = ''; } });
})();
</script>
@endsection
