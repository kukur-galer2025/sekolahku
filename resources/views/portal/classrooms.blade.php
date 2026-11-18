@extends('portal.layout')
@section('title', 'Kelas & Wali')
@section('content')
@php $lvl = \App\Support\SchoolLevel::LABELS[$school->schoolLevel()] ?? ''; $isSma = $school->schoolLevel() === 'sma'; @endphp
<h2>Kelas & Wali Kelas</h2>
<p class="sub">Jenjang {{ $lvl }} · tingkat {{ implode(', ', $grades) }} · tahun ajaran {{ $school->currentAcademicYear() }}. Kelas juga terbentuk otomatis dari nama sheet saat impor siswa; wali kelas dari kolom WALI KELAS di Excel guru.</p>
@if ($errors->any())<div class="alert er">{{ $errors->first() }}</div>@endif

<div class="two" style="align-items:start">
  <form class="card" method="post" action="{{ route('portal.classrooms.bulk') }}">
    @csrf
    <b>Buat kelas otomatis</b>
    <p class="mu" style="margin:4px 0 8px">Sekaligus untuk tingkat yang dicentang. Nama yang sudah ada dilewati.</p>
    <div style="display:flex;gap:14px;flex-wrap:wrap">
      @foreach ($grades as $g)<label class="check" style="margin:0"><input type="checkbox" name="grades[]" value="{{ $g }}" checked> Tingkat {{ $g }}</label>@endforeach
    </div>
    <div class="form-grid">
      <div><label for="count">Rombel per tingkat</label><input id="count" name="count" type="number" min="1" max="20" value="{{ old('count', 3) }}" required></div>
      <div><label for="style">Penanda rombel</label><select id="style" name="style">
        <option value="letter" @selected(old('style') !== 'number')>Huruf ({{ $isSma ? 'X-A, X-B' : $grades[0].'A, '.$grades[0].'B' }})</option>
        <option value="number" @selected(old('style') === 'number')>Angka ({{ $grades[0] }}-1, {{ $grades[0] }}-2)</option></select></div>
      @if ($isSma)<div><label for="program">Jurusan (opsional, mis. FKK, TKJ)</label><input id="program" name="program" value="{{ old('program') }}" maxlength="24" placeholder="Kosongkan bila tanpa jurusan"></div>@endif
    </div>
    <button class="btn" type="submit" style="margin-top:12px">Buat kelas</button>
  </form>

  <form class="card" method="post" action="{{ route('portal.classrooms.store') }}">
    @csrf
    <b>Tambah satu kelas</b>
    <div class="form-grid">
      <div><label for="grade">Tingkat</label><select id="grade" name="grade" required>@foreach ($grades as $g)<option value="{{ $g }}" @selected(old('grade') === $g)>{{ $g }}</option>@endforeach</select></div>
      <div><label for="cname">Nama kelas</label><input id="cname" name="name" value="{{ old('name') }}" maxlength="64" required placeholder="{{ $isSma ? 'mis. X-TKJ 1' : 'mis. '.$grades[0].'A' }}"></div>
    </div>
    <button class="btn o" type="submit" style="margin-top:12px">Tambah kelas</button>
  </form>
</div>

<div class="card">
  <div class="sc">
    <table>
      <tr><th>Kelas</th><th>Tingkat</th><th>Siswa aktif</th><th>Wali kelas</th><th>Jadwal</th><th></th></tr>
      @forelse ($classrooms as $c)
        <tr>
          <td><a href="{{ route('portal.students', ['kelas' => $c->id]) }}"><b>{{ $c->name }}</b></a></td><td>{!! $c->grade ? e($c->grade) : '<span class="pl wr">Belum dikenali</span>' !!}</td><td>{{ $c->students_count }}</td>
          <td>{!! $c->homeroomTeacher ? e($c->homeroomTeacher->name) : '<span class="pl wr">Belum ditetapkan</span>' !!}</td>
          <td>{!! $c->schedules_count ? e($c->schedules_count.' blok') : '<span class="pl mu">Belum ada</span>' !!}</td>
          <td>@if (! $c->students_count && ! $c->schedules_count)<form method="post" action="{{ route('portal.classrooms.destroy', $c) }}" data-confirm="Hapus kelas {{ $c->name }}?">@csrf<button class="linkbtn" type="submit">Hapus</button></form>@endif</td>
        </tr>
      @empty
        <tr><td colspan="6" class="mu">Belum ada kelas. Pakai "Buat kelas otomatis" di atas, atau impor data siswa.</td></tr>
      @endforelse
    </table>
  </div>
</div>
@endsection
