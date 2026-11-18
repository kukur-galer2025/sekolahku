@extends('panel.layout')
@section('title', 'Log aktivitas')
@section('content')
@php
  $C = \App\Models\AuditLog::CATEGORIES;
  $cat = request('kategori');
  $keep = request()->only(['q', 'from', 'to', 'school']);
@endphp
<h2>Log aktivitas</h2>
<p class="sub">Jejak semua tindakan penting: tagihan &amp; keuangan, penggajian, data sekolah, serta akses &amp; keamanan. Gunakan untuk menelusuri siapa mengubah apa dan kapan.</p>

<nav class="ltabs log-cats" aria-label="Kategori log">
  <a href="{{ route('panel.logs', $keep) }}" class="{{ ! $cat ? 'on' : '' }}">Semua</a>
  @foreach ($C as $k => $c)
    <a href="{{ route('panel.logs', $keep + ['kategori' => $k]) }}" class="{{ $cat === $k ? 'on' : '' }}">{{ $c[0] }} @if ($today[$k] ?? 0) <span class="pl {{ $c[1] }}" title="hari ini">{{ $today[$k] }}</span> @endif</a>
  @endforeach
</nav>

<div class="card">
  <form class="row" method="get" style="align-items:flex-end">
    @if ($cat) <input type="hidden" name="kategori" value="{{ $cat }}"> @endif
    @if (request('school')) <input type="hidden" name="school" value="{{ request('school') }}"> @endif
    <div style="flex:2"><label for="lq">Cari</label><input id="lq" name="q" value="{{ request('q') }}" maxlength="80" placeholder="nama, nomor invoice, sekolah, IP, …"></div>
    <div><label for="from">Dari</label><input id="from" type="date" name="from" value="{{ request('from') }}"></div>
    <div><label for="to">Sampai</label><input id="to" type="date" name="to" value="{{ request('to') }}"></div>
    <div style="flex:none;display:flex;gap:6px">
      <button class="btn nf" type="submit">Tampilkan</button>
      @if (request()->hasAny(['q', 'from', 'to', 'kategori', 'school'])) <a class="btn o nf" href="{{ route('panel.logs') }}">Reset</a> @endif
      <a class="btn o nf" href="{{ route('panel.logs.export', request()->only(['kategori', 'q', 'from', 'to', 'school'])) }}"><x-ti n="download"/> CSV</a>
    </div>
  </form>

  <p class="mu" style="margin:12px 0 0">{{ number_format($logs->total(), 0, ',', '.') }} catatan @if ($cat) · {{ $C[$cat][0] ?? '' }} @endif @if (request('q')) · cari “{{ request('q') }}” @endif</p>
  <div class="sc" style="margin-top:8px"><table><tr><th>Waktu (WIB)</th><th>Kategori</th><th>Keterangan</th><th>Sekolah</th><th>Oleh</th><th>IP</th></tr>
    @forelse ($logs as $l)
      @php [$ck, $cl, $ct] = $l->category(); @endphp
      <tr><td style="white-space:nowrap">{{ $l->created_at->timezone('Asia/Jakarta')->format('d/m/Y H:i') }}</td>
        <td><span class="pl {{ $ct }}">{{ $cl }}</span><div class="mu" style="font-size:10.5px;margin-top:2px">{{ $l->action }}</div></td>
        <td class="wrap">{{ $l->description }}</td>
        <td>@if ($l->school) <a href="{{ route('panel.schools.show', $l->school_id) }}">{{ $l->school->name }}</a> @else <span class="mu">Platform</span> @endif</td>
        <td>{{ $l->actor_name }}</td><td class="mu">{{ $l->ip }}</td></tr>
    @empty <tr><td colspan="6" class="mu">Tidak ada log yang cocok.</td></tr> @endforelse
  </table></div>
  @include('portal.pager', ['p' => $logs])
</div>
@endsection
