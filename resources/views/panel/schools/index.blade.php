@extends('panel.layout')
@section('title', 'Sekolah')
@section('content')
<h2>Sekolah</h2>
<p class="sub">Semua tenant MyPresence. Klik nama sekolah untuk pemakaian, paket, dan bantuan.</p>
<div class="gr">
  <div class="card"><div class="mu">Total sekolah</div><div class="big">{{ $counts->sum() }}</div></div>
  <div class="card"><div class="mu">Aktif berbayar</div><div class="big" style="color:var(--ok)">{{ $counts['active'] ?? 0 }}</div></div>
  <div class="card"><div class="mu">Trial</div><div class="big" style="color:var(--wr)">{{ $counts['trial'] ?? 0 }}</div></div>
  <div class="card"><div class="mu">Ditangguhkan</div><div class="big" style="color:var(--er)">{{ $counts['suspended'] ?? 0 }}</div></div>
  <div class="card"><div class="mu">Siswa aktif (semua sekolah)</div><div class="big">{{ number_format($totalStudents, 0, ',', '.') }}</div></div>
</div>
<div class="card">
  <form class="row" method="get">
    <div><label for="q">Cari</label><input id="q" name="q" value="{{ $q }}" placeholder="Nama, link portal, atau NPSN"></div>
    <div><label for="status">Status</label><select id="status" name="status"><option value="">Semua</option>
      @foreach (['trial' => 'Trial', 'active' => 'Aktif', 'suspended' => 'Ditangguhkan', 'unverified' => 'Belum diverifikasi'] as $v => $l)<option value="{{ $v }}" @selected($status === $v)>{{ $l }}</option>@endforeach</select></div>
    <button class="btn nf" type="submit">Tampilkan</button>
    <a class="btn o nf" href="{{ route('panel.schools.create') }}"><x-ti n="plus"/> Buat sekolah</a>
  </form>
  <div class="sc" style="margin-top:14px">
    <table>
      <tr><th>Sekolah</th><th>Link portal</th><th>Status</th><th>Paket</th><th>Siswa / kuota</th><th>Guru</th><th>Presensi 30 hari</th><th>Login terakhir</th><th>Daftar via</th></tr>
      @forelse ($schools as $s)
        @php $x = $usage[$s->id]; @endphp
        <tr>
          <td><a href="{{ route('panel.schools.show', $s) }}"><b>{{ $s->name }}</b></a>@if ($s->npsn)<span class="mu" style="display:block">NPSN {{ $s->npsn }}</span>@endif</td>
          <td><span class="mu">{{ $s->subdomain }}.{{ config('tenancy.central_domain') }}</span></td>
          <td>@include('panel.status', ['school' => $s])</td>
          <td><span class="pl mu">{{ \App\Support\Plan::label($s->plan) }}</span></td>
          <td>{{ number_format($x['students'], 0, ',', '.') }} / {{ $s->student_quota ? number_format($s->student_quota, 0, ',', '.') : '∞' }}
            @if ($s->student_quota)<div class="bar" style="width:110px"><i style="width:{{ min(100, round($x['students'] / $s->student_quota * 100)) }}%"></i></div>@endif</td>
          <td>{{ $x['teachers'] }}</td>
          <td>{{ number_format($x['attendance_30d'], 0, ',', '.') }}</td>
          <td>{{ $x['last_login'] ? \Illuminate\Support\Carbon::parse($x['last_login'])->diffForHumans() : '-' }}</td>
          <td><span class="pl mu">{{ ['self' => 'Mandiri', 'panel' => 'Panel', 'seed' => 'Demo'][$s->registered_via] ?? $s->registered_via }}</span></td>
        </tr>
      @empty
        <tr><td colspan="9" class="mu">Tidak ada sekolah yang cocok.</td></tr>
      @endforelse
    </table>
  </div>
  @include('portal.pager', ['p' => $schools])
</div>
@endsection
