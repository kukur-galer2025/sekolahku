@extends('portal.layout')
@section('title', 'Data Siswa')
@section('content')
<h2>Data Siswa</h2>
<p class="sub">Siswa masuk dengan NIS. Tambah atau perbarui lewat Excel format DATABASE_SISWA di menu <a href="{{ route('portal.imports') }}">Impor Data</a>.</p>
<div class="card">
  <form class="row" method="get">
    <div><label for="kelas">Kelas</label>
      <select id="kelas" name="kelas"><option value="">Semua kelas</option>
        @foreach ($classrooms as $c)<option value="{{ $c->id }}" @selected($classroomId == $c->id)>{{ $c->name }}</option>@endforeach
      </select></div>
    <div><label for="status">Status</label>
      <select id="status" name="status">
        @foreach (\App\Models\Student::STATUSES + ['semua' => 'Semua'] as $v => $l)<option value="{{ $v }}" @selected($status === $v)>{{ $l }}</option>@endforeach
      </select></div>
    <div><label for="q">Cari</label><input id="q" name="q" value="{{ $q }}" placeholder="Nama, NIS, atau NISN"></div>
    <button class="btn nf" type="submit">Tampilkan</button>
  </form>
  <div class="sc" style="margin-top:14px">
    <table>
      <tr><th>No</th><th>NIS</th><th>NISN</th><th>Nama</th><th>L/P</th><th>Kelas</th><th>Status</th><th>Login terakhir</th><th></th></tr>
      @forelse ($students as $s)
        <tr>
          <td class="mu">{{ $students->firstItem() + $loop->index }}</td>
          <td>{{ $s->nis }}</td><td>{{ $s->nisn ?? '-' }}</td><td><b>{{ $s->name }}</b></td><td>{{ $s->gender ?? '-' }}</td><td>{{ $s->classroom?->name ?? '-' }}</td>
          <td>@if ($s->status === 'aktif')<span class="pl ok">Aktif</span>@else<span class="pl {{ $s->status === 'lulus' ? 'wr' : 'mu' }}">{{ \App\Models\Student::STATUSES[$s->status] ?? $s->status }}</span>@if ($s->left_at)<br><small class="mu">{{ $s->left_at->format('d/m/Y') }}</small>@endif @endif</td>
          <td>{!! $s->user?->last_login_at ? e($s->user->last_login_at->diffForHumans()) : '<span class="pl mu">Belum pernah</span>' !!}</td>
          <td style="white-space:nowrap">
            @if ($s->status === 'aktif')<form method="post" action="{{ route('portal.students.reset', $s) }}">@csrf<button class="linkbtn" type="submit">Password sementara</button></form>@endif
            <a class="linkbtn" href="{{ route('portal.students.certificate', $s) }}" target="_blank" rel="noopener">Surat keterangan</a>
            @if ($s->status === 'aktif')
              <details><summary class="linkbtn">Mutasi / keluar</summary>
                <form method="post" action="{{ route('portal.students.exit', $s) }}" style="display:grid;gap:6px;margin-top:6px;min-width:220px">@csrf
                  <select name="type" aria-label="Jenis">@foreach (\App\Services\Lifecycle\Exits::STUDENT_TYPES as $v => $l)<option value="{{ $v }}">{{ $l }}</option>@endforeach</select>
                  <input type="date" name="date" value="{{ now()->toDateString() }}" required aria-label="Tanggal">
                  <input name="reason" maxlength="255" placeholder="Keterangan (mis. pindah ke SMPN 2)" aria-label="Keterangan">
                  <button class="btn o nf" type="submit" data-confirm="Nonaktifkan akun siswa ini? Riwayat kehadiran tetap tersimpan.">Simpan</button>
                </form></details>
            @elseif ($s->status !== 'arsip')
              <form method="post" action="{{ route('portal.students.reactivate', $s) }}" style="display:flex;gap:6px;margin-top:4px">@csrf
                <select name="classroom_id" aria-label="Kelas">@foreach ($classrooms as $c)<option value="{{ $c->id }}" @selected($s->classroom_id == $c->id)>{{ $c->name }}</option>@endforeach</select>
                <button class="linkbtn" type="submit">Aktifkan lagi</button></form>
            @endif
          </td>
        </tr>
      @empty
        <tr><td colspan="9" class="mu">Tidak ada siswa yang cocok.</td></tr>
      @endforelse
    </table>
  </div>
  @include('portal.pager', ['p' => $students])
</div>

<details class="card" @if ($errors->hasAny(['nis', 'name', 'classroom_id'])) open @endif>
  <summary><b>Tambah siswa pindahan (mutasi masuk)</b> <span class="mu">— langsung ke kelas tujuan, password awal ditampilkan sekali</span></summary>
  @if ($errors->hasAny(['nis', 'name', 'classroom_id']))<div class="alert er" style="margin-top:10px">{{ $errors->first() }}</div>@endif
  <form method="post" action="{{ route('portal.students.transfer') }}" style="margin-top:10px">@csrf
    <div class="form-grid">
      <div><label for="t-nis">NIS</label><input id="t-nis" name="nis" value="{{ old('nis') }}" required maxlength="32" inputmode="numeric"></div>
      <div><label for="t-nisn">NISN</label><input id="t-nisn" name="nisn" value="{{ old('nisn') }}" maxlength="32" inputmode="numeric"></div>
      <div><label for="t-name">Nama lengkap</label><input id="t-name" name="name" value="{{ old('name') }}" required maxlength="255"></div>
      <div><label for="t-gender">L/P</label><select id="t-gender" name="gender"><option value="">-</option><option value="L" @selected(old('gender') === 'L')>L</option><option value="P" @selected(old('gender') === 'P')>P</option></select></div>
      <div><label for="t-class">Kelas tujuan</label><select id="t-class" name="classroom_id" required>@foreach ($classrooms as $c)<option value="{{ $c->id }}" @selected(old('classroom_id') == $c->id)>{{ $c->name }}</option>@endforeach</select></div>
      <div><label for="t-origin">Asal sekolah</label><input id="t-origin" name="origin" value="{{ old('origin') }}" maxlength="120" placeholder="mis. SMPN 2 Cilacap"></div>
    </div>
    <button class="btn" type="submit" style="margin-top:12px">Daftarkan siswa pindahan</button>
  </form>
</details>
@endsection
