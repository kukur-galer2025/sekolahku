@extends('portal.layout')
@section('title', 'Impor Data')
@section('content')
@php
  $cards = [
    'teachers' => ['Data guru', 'Kolom NIK, NAMA, MATA PELAJARAN, JABATAN, WALI KELAS. Guru baru dibuatkan akun (login dengan NIK); guru lama diperbarui, tidak pernah dihapus. PDF jadwal aSc → Excel daftar guru terisi, tinggal isi NIK.'],
    'students' => ['Data siswa', 'Satu sheet per kelas (nama sheet = nama kelas, mis. "X - FKK 1") berisi NISN, NIS, NAMA, L/P. Opsional kolom TANGGAL LAHIR sebagai password awal.'],
    'lesson_periods' => ['Jam KBM', 'Kolom SESI, MULAI, SELESAI, KETERANGAN. Baris "Istirahat" menjadi jam istirahat. Mengganti seluruh tabel Jam KBM. Bisa juga PDF jadwal aSc: jam diambil dari kepala tabel.'],
    'schedules' => ['Jadwal mengajar', 'Jadwal per guru. PDF cetakan aSc Timetables jadwal guru (mis. JADWAL_GURU.pdf), atau Excel: HARI, JAM KE (mis. 3-4), KELAS, MATA PELAJARAN, GURU (NIK atau nama). Kelas yang belum ada dibuat otomatis. Mengganti seluruh jadwal.'],
    'class_schedules' => ['Jadwal kelas', 'Jadwal per kelas. PDF cetakan aSc Timetables jadwal kelas (mis. JADWAL_SISWA.pdf), atau Excel dengan format yang sama. Kelas yang belum ada dibuat otomatis. Mengganti seluruh jadwal.'],
  ];
@endphp
<h2>Impor Data</h2>
<p class="sub">Urutan yang disarankan: Jam KBM → siswa → guru → jadwal. Unggah Excel atau PDF, periksa pratinjaunya, lalu simpan. Tidak ada data yang berubah sebelum kamu menekan Simpan.
  Jadwal mengajar dan jadwal kelas mengisi jadwal yang sama (dilihat dari sisi guru atau sisi kelas), jadi cukup unggah salah satu.</p>
@if ($errors->any())<div class="alert er">{{ $errors->first() }}</div>@endif
<div class="three">
  @foreach ($cards as $type => [$title, $desc])
    @php $pdf = in_array($type, \App\Services\Import\ImportManager::PDF_TYPES, true); @endphp
    <div class="card">
      <b>{{ $title }}</b>
      <p class="mu" style="margin:6px 0 4px">{{ $desc }}</p>
      <a href="{{ route('portal.imports.template', $type) }}" style="font-size:12px"><x-ti n="download"/> Unduh format Excel</a>
      <form method="post" action="{{ route('portal.imports.upload', $type) }}" enctype="multipart/form-data">
        @csrf
        <label class="dz"><b>Pilih file {{ $pdf ? '.xlsx, .csv, atau PDF' : '.xlsx atau .csv' }}</b><span class="mu" style="display:block">Maks. 10 MB</span>
          <input type="file" name="file" accept="{{ $pdf ? '.xlsx,.csv,.pdf,application/pdf' : '.xlsx,.csv' }}" required></label>
        <button class="btn" type="submit">Baca & pratinjau</button>
      </form>
    </div>
  @endforeach
</div>

<div class="card" style="margin-top:14px">
  {{-- Disembunyikan secara default; tombol Tampilkan untuk melihat. --}}
  <details class="logbox" data-key="portal-imports" @if (request()->has('page')) open @endif style="margin-top:-12px">
  <summary><span class="lb-t">Riwayat impor</span><span class="mu">{{ number_format($history->total(), 0, ',', '.') }} impor</span><span class="lb-b"><span class="lb-show">Tampilkan</span><span class="lb-hide">Sembunyikan</span></span></summary>
  <div class="sc" style="margin-top:10px">
    <table>
      <tr><th>Waktu</th><th>Jenis</th><th>File</th><th>Oleh</th><th>Status</th><th>Hasil</th></tr>
      @forelse ($history as $i)
        <tr>
          <td>{{ $i->created_at->timezone(app(\App\Tenancy\TenantContext::class)->get()->timezone)->format('d/m/Y H:i') }}</td>
          <td>{{ $i->typeLabel() }}</td>
          <td><a href="{{ route('portal.imports.show', $i) }}">{{ $i->file_name }}</a></td>
          <td>{{ $i->user?->name ?? '-' }}</td>
          <td>@include('portal.imports.status', ['i' => $i])</td>
          <td class="wrap mu">{{ collect($i->result ?? [])->map(fn ($v, $k) => "$k $v")->implode(' · ') }}</td>
        </tr>
      @empty
        <tr><td colspan="6" class="mu">Belum ada impor.</td></tr>
      @endforelse
    </table>
  </div>
  @include('portal.pager', ['p' => $history])
  </details>
</div>
@endsection
