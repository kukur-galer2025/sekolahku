@extends('portal.layout')
@section('title', 'Data Guru')
@section('content')
<h2>Data Guru</h2>
<p class="sub">Guru masuk dengan NIK. Tambah atau perbarui lewat Excel format Database Guru di menu <a href="{{ route('portal.imports') }}">Impor Data</a>.</p>
<div class="card">
  <form class="row" method="get">
    <div><label for="status">Status</label><select id="status" name="status">@foreach (\App\Models\Teacher::STATUSES + ['semua' => 'Semua'] as $v => $l)<option value="{{ $v }}" @selected($status === $v)>{{ $l }}</option>@endforeach</select></div>
    <div><label for="q">Cari</label><input id="q" name="q" value="{{ $q }}" placeholder="Nama, NIK, atau mata pelajaran"></div>
    <button class="btn nf" type="submit">Cari</button>
    @if ($q)<a class="btn o nf" href="{{ route('portal.teachers') }}">Reset</a>@endif
  </form>
  <div class="sc" style="margin-top:14px">
    <table>
      <tr><th>No</th><th>NIK</th><th>Nama</th><th>Mata pelajaran</th><th>Jabatan</th><th>Wali kelas</th><th>Status</th><th>Login terakhir</th><th></th></tr>
      @forelse ($teachers as $t)
        <tr>
          <td class="mu">{{ $teachers->firstItem() + $loop->index }}</td>
          <td>{{ $t->nik }}</td><td><b>{{ $t->name }}</b></td><td>{{ $t->subject ?? '-' }}</td><td>{{ $t->position ?? '-' }}</td>
          <td>{{ $t->homeroomClassrooms->pluck('name')->implode(', ') ?: '-' }}</td>
          <td>@if ($t->status === 'aktif')<span class="pl ok">Aktif</span>@else<span class="pl mu">{{ \App\Models\Teacher::STATUSES[$t->status] ?? $t->status }}</span>@endif</td>
          <td>{!! $t->user?->last_login_at ? e($t->user->last_login_at->diffForHumans()) : '<span class="pl mu">Belum pernah</span>' !!}</td>
          <td style="white-space:nowrap">
            @if ($t->status === 'aktif')
              <form method="post" action="{{ route('portal.teachers.reset', $t) }}">@csrf<button class="linkbtn" type="submit">Password sementara</button></form>
              <details><summary class="linkbtn">Mutasi / pensiun</summary>
                <form method="post" action="{{ route('portal.teachers.exit', $t) }}" style="display:grid;gap:6px;margin-top:6px;min-width:220px">@csrf
                  <select name="type" aria-label="Jenis">@foreach (\App\Services\Lifecycle\Exits::TEACHER_TYPES as $v => $l)<option value="{{ $v }}">{{ $l }}</option>@endforeach</select>
                  <input type="date" name="date" value="{{ now()->toDateString() }}" required aria-label="Tanggal">
                  <input name="reason" maxlength="255" placeholder="Keterangan" aria-label="Keterangan">
                  <button class="btn o nf" type="submit" data-confirm="Nonaktifkan akun guru ini? Kelas perwaliannya dikosongkan.">Simpan</button>
                </form></details>
            @else
              <form method="post" action="{{ route('portal.teachers.reactivate', $t) }}">@csrf<button class="linkbtn" type="submit">Aktifkan lagi</button></form>
            @endif
          </td>
        </tr>
      @empty
        <tr><td colspan="9" class="mu">Belum ada data guru.</td></tr>
      @endforelse
    </table>
  </div>
  @include('portal.pager', ['p' => $teachers])
</div>
@endsection
