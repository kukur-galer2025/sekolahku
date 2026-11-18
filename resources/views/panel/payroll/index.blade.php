@extends('panel.layout')
@section('title', 'Penggajian')
@section('content')
@php $rp = fn ($n) => \App\Models\Invoice::rupiah($n); $M = \App\Models\PayrollRun::MONTHS; @endphp
<h2>Penggajian karyawan</h2>
<p class="sub">Tabel gaji ParamaEdu.Tech per bulan: pendapatan, potongan, gaji bersih, dan status pembayaran. Data gaji bersifat rahasia — hanya super-admin yang bisa membuka.</p>
@include('panel.payroll.tabs')

<div class="gr">
  <div class="card"><div class="mu">Karyawan aktif</div><div class="big" style="font-size:22px">{{ $activeCount }}</div><div class="mu"><a href="{{ route('panel.payroll.employees') }}">Kelola data karyawan</a></div></div>
  <div class="card"><div class="mu">Gaji bersih {{ $year }}</div><div class="big" style="font-size:22px">{{ $rp($ytd) }}</div><div class="mu">{{ $runs->where('year', $year)->count() }} periode</div></div>
  @php $last = $runs->first(); $ls = $last?->summary($last->items); @endphp
  <div class="card"><div class="mu">Periode terakhir</div><div class="big" style="font-size:22px">{{ $last ? $last->label() : '—' }}</div>
    @if ($last)<div class="mu">bersih {{ $rp($ls['net']) }} · belum dibayar {{ $rp($ls['unpaid']) }}</div>@endif</div>
</div>

<form class="card" method="post" action="{{ route('panel.payroll.store') }}">@csrf
  <b><x-ti n="plus"/> Buat periode gaji</b>
  <div class="row" style="margin-top:6px">
    <div><label for="pm">Bulan</label><select id="pm" name="month">@foreach ($M as $k => $v)<option value="{{ $k }}" @selected((int) old('month', $next['month']) === $k)>{{ $v }}</option>@endforeach</select></div>
    <div><label for="py">Tahun</label><input id="py" name="year" type="number" min="2020" max="2100" value="{{ old('year', $next['year']) }}" required></div>
    <div><label for="pd">Tanggal pembayaran</label><input id="pd" name="pay_date" type="date" value="{{ old('pay_date') }}"></div>
    <div><label for="wd">Hari kerja</label><input id="wd" name="work_days" type="number" min="0" max="31" value="{{ old('work_days', 22) }}" required></div>
    <div style="flex:2"><label for="src">Isi awal</label><select id="src" name="source">
      <option value="employees">Dari data karyawan aktif ({{ $activeCount }} orang)</option>
      @foreach ($runs->take(12) as $r)<option value="run:{{ $r->id }}">Salin dari {{ $r->label() }} ({{ $r->items->count() }} orang)</option>@endforeach
      <option value="empty">Kosong (impor dari Excel nanti)</option>
    </select></div>
  </div>
  <button class="btn" type="submit" style="margin-top:12px">Buat periode</button>
  <p class="mu" style="margin:8px 0 0;font-size:12px">Komponen gaji bawaan diambil dari data karyawan. Lembur, bonus, dan status pembayaran diisi di tabel periode.</p>
</form>

<div class="card">
  <b>Periode gaji</b>
  <div class="sc" style="margin-top:10px"><table><tr><th>Periode</th><th>Tgl. bayar</th><th class="amt">Karyawan</th><th class="amt">Pendapatan</th><th class="amt">Potongan</th><th class="amt">Gaji bersih</th><th class="amt">Belum dibayar</th><th>Status</th></tr>
  @forelse ($runs as $r)
    @php $s = $r->summary($r->items); $done = $s['count'] && $s['n_paid'] === $s['count']; @endphp
    <tr><td><a href="{{ route('panel.payroll.show', $r->id) }}"><b>{{ $r->label() }}</b></a></td><td>{{ $r->pay_date?->format('d/m/Y') ?? '—' }}</td>
      <td class="amt">{{ $s['count'] }}</td><td class="amt">{{ $rp($s['gross']) }}</td><td class="amt">{{ $rp($s['deductions']) }}</td><td class="amt"><b>{{ $rp($s['net']) }}</b></td>
      <td class="amt">{{ $rp($s['unpaid']) }}</td>
      <td><span class="pl {{ $done ? 'ok' : ($s['paid'] ? 'in' : 'wr') }}">{{ $done ? 'Dibayar semua' : ($s['paid'] ? $s['n_paid'].'/'.$s['count'].' dibayar' : 'Belum dibayar') }}</span></td></tr>
  @empty <tr><td colspan="8" class="mu">Belum ada periode gaji. Tambahkan data karyawan dulu, lalu buat periode di atas.</td></tr> @endforelse
  </table></div>
</div>
@endsection
