@extends('portal.layout')
@section('title', 'Dashboard')
@section('content')
@php $role = auth()->user()->role; @endphp
<h2>Dashboard</h2>
<p class="sub">{{ $today->locale('id')->translatedFormat('l, j F Y') }} · batas presensi pukul {{ $cutoff }}</p>

@if ($role === \App\Enums\Role::Admin)
  <div class="gr">
    <div class="card"><div class="mu">Siswa aktif</div><div class="big">{{ number_format($students, 0, ',', '.') }}</div>
      @if ($school->student_quota)<div class="mu">kuota paket {{ number_format($school->student_quota, 0, ',', '.') }}</div>@endif</div>
    <div class="card"><div class="mu">Guru</div><div class="big">{{ $teachers }}</div></div>
    <div class="card"><div class="mu">Kelas</div><div class="big">{{ $classrooms }}</div></div>
    <div class="card"><div class="mu">Hadir hari ini</div><div class="big" style="color:var(--ok)">{{ $present }}</div><div class="mu">Alpa {{ $absent }} · Sakit/Izin {{ $permit }}</div></div>
  </div>
  @if ($onboarding['done'] < $onboarding['total'])
    <div class="card">
      <div class="row" style="align-items:center"><div><b>Siapkan sekolahmu</b><p class="mu" style="margin-top:2px">{{ $onboarding['done'] }} dari {{ $onboarding['total'] }} langkah selesai</p></div></div>
      <div class="bar"><i style="width:{{ round($onboarding['done'] / $onboarding['total'] * 100) }}%"></i></div>
      <div class="steps">
        @foreach ($onboarding['steps'] as $i => [$label, $done, $link])
          <div class="step {{ $done ? 'done' : '' }}"><span class="dot">{{ $done ? '✓' : $i + 1 }}</span><span class="grow">{{ $label }}</span>
            @if (! $done && $link)<a class="btn o nf" href="{{ $link }}">Buka</a>@elseif (! $done)<span class="mu">menyusul di PWA</span>@endif</div>
        @endforeach
      </div>
    </div>
  @endif
  <div class="card">
    <div class="row" style="align-items:center"><b>Impor terakhir</b><a class="btn o nf" href="{{ route('portal.imports') }}">Semua impor</a></div>
    <div class="sc" style="margin-top:10px">
      <table><tr><th>Waktu</th><th>Jenis</th><th>File</th><th>Status</th></tr>
      @forelse ($imports as $i)
        <tr><td>{{ $i->created_at->timezone($school->timezone)->format('d/m H:i') }}</td><td>{{ $i->typeLabel() }}</td><td><a href="{{ route('portal.imports.show', $i) }}">{{ $i->file_name }}</a></td><td>@include('portal.imports.status', ['i' => $i])</td></tr>
      @empty
        <tr><td colspan="4" class="mu">Belum ada impor.</td></tr>
      @endforelse
      </table>
    </div>
  </div>

@elseif ($role === \App\Enums\Role::Teacher)
  <div class="gr">
    <div class="card"><div class="mu">Mata pelajaran</div><div class="big" style="font-size:18px">{{ $teacher?->subject ?? '-' }}</div></div>
    <div class="card"><div class="mu">Jam mengajar hari ini</div><div class="big">{{ $lessonsToday->sum('period_count') }}</div></div>
    <div class="card"><div class="mu">Jam mengajar per minggu</div><div class="big">{{ $lessonsWeek }}</div></div>
    <div class="card"><div class="mu">Wali kelas</div><div class="big" style="font-size:18px">{{ $homerooms->pluck('name')->implode(', ') ?: '-' }}</div></div>
  </div>
  <div class="card"><b>Jadwal mengajar hari ini</b>
    <div class="sc" style="margin-top:10px"><table><tr><th>Jam ke</th><th>Kelas</th><th>Mata pelajaran</th></tr>
      @forelse ($lessonsToday as $l)
        <tr><td>{{ $l->period_start }}{{ $l->period_count > 1 ? '–'.($l->period_start + $l->period_count - 1) : '' }}</td><td>{{ $l->classroom?->name }}</td><td>{{ $l->subject }}</td></tr>
      @empty
        <tr><td colspan="3" class="mu">Tidak ada jadwal mengajar hari ini.</td></tr>
      @endforelse
    </table></div>
  </div>

@else
  <div class="gr">
    <div class="card"><div class="mu">Kelas</div><div class="big" style="font-size:20px">{{ $student?->classroom?->name ?? '-' }}</div><div class="mu">NIS {{ $student?->nis }}</div></div>
    <div class="card"><div class="mu">Presensi hari ini</div>
      @if ($record)
        <div class="big" style="font-size:20px">@include('portal.status-pill', ['s' => $record->status])</div>
        <div class="mu">{{ $record->scanned_at?->timezone($school->timezone)->format('H:i') }} {{ $record->is_late ? '· terlambat' : '' }}</div>
      @else
        <div class="big" style="font-size:20px"><span class="pl mu">Belum scan</span></div>
      @endif
    </div>
    <div class="card"><div class="mu">Scan QR dibuka</div><div class="big" style="font-size:18px">{{ $windows ? $windows[0][0]->format('H:i').' – '.end($windows)[1]->format('H:i') : 'Tidak ada pelajaran' }}</div><div class="mu">hanya saat jam pelajaran kelasmu</div></div>
  </div>
  <div class="alert wr">Scan QR memakai kamera akan tersedia di aplikasi PWA (tahap 7). Pastikan HP tersambung ke WiFi sekolah.</div>
@endif
@endsection
