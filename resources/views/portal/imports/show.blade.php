@extends('portal.layout')
@section('title', 'Impor '.$import->typeLabel())
@section('content')
@php $p = $import->preview ?? []; $live = in_array($import->status, ['queued', 'processing'], true); @endphp
@if ($live)<meta http-equiv="refresh" content="3">@endif

<p class="mu"><a href="{{ route('portal.imports') }}">‹ Impor Data</a></p>
<h2>Impor {{ $import->typeLabel() }}</h2>
<p class="sub">{{ $import->file_name }} · {{ number_format($import->file_size / 1024, 0, ',', '.') }} KB · oleh {{ $import->user?->name ?? '-' }} · @include('portal.imports.status', ['i' => $import])</p>

@if ($import->status === 'done')
  <div class="alert ok"><b>Impor selesai.</b> {{ collect($import->result)->map(fn ($v, $k) => "$k: $v")->implode(' · ') }}</div>
  @if ($import->credentials)
    <div class="card">
      <b>Daftar akun baru & password awal</b>
      <p class="mu" style="margin:6px 0 12px">{{ count($import->credentials) }} akun baru. Bagikan ke guru/wali kelas. Semua akun wajib mengganti password saat login pertama. Demi keamanan, daftar ini dihapus otomatis 7 hari setelah impor.</p>
      <a class="btn" href="{{ route('portal.imports.credentials', $import) }}"><x-ti n="download"/> Unduh daftar akun (CSV)</a>
      <a class="btn o" href="{{ route('portal.imports.cards', $import) }}" target="_blank"><x-ti n="printer"/> Cetak kartu aktivasi</a>
    </div>
  @endif
@elseif ($import->status === 'failed')
  <div class="alert er">{{ $import->error }}</div>
@elseif ($live)
  <div class="alert wr"><span class="spin"></span> Sedang diproses… halaman ini diperbarui otomatis.</div>
@endif

@if (in_array($import->status, ['preview', 'failed'], true))
@if ($p['errors'] ?? [])
  <div class="alert er"><b>File belum bisa disimpan:</b><ul>@foreach ($p['errors'] as $e)<li>{{ $e }}</li>@endforeach</ul></div>
@endif
@if ($p['warnings'] ?? [])
  <div class="alert wr"><b>Perlu diperhatikan:</b><ul>@foreach ($p['warnings'] as $w)<li>{{ $w }}</li>@endforeach</ul></div>
@endif

@if ($p['stats'] ?? [])
<div class="gr">
  @foreach ($p['stats'] as $label => $n)<div class="card"><div class="mu">{{ $label }}</div><div class="big">{{ $n }}</div></div>@endforeach
</div>
@endif

@if (! empty($p['new_classes']))
  <div class="card"><b>Kelas baru yang akan dibuat</b><p class="mu" style="margin-top:6px">{{ implode(', ', $p['new_classes']) }}</p></div>
@endif

@if (! empty($p['pdf_teachers']) && $import->status === 'preview')
  <div class="card">
    <b>Langkah berikutnya</b>
    <p class="mu" style="margin:6px 0 12px">1. Unduh Excel ini. 2. Isi kolom NIK (guru yang sudah terdaftar sudah terisi). 3. Unggah Excel itu di kartu <b>Data guru</b> → akun guru dibuat dan siap login dengan NIK.</p>
    <a class="btn" href="{{ route('portal.imports.teacher-sheet', $import) }}"><x-ti n="download"/> Unduh Excel Data Guru (dari PDF)</a>
  </div>
@endif

@if ($p['sample'] ?? [])
<div class="card">
  <b>Contoh isi file</b> <span class="mu">({{ count($p['sample']) }} baris pertama)</span>
  <div class="sc" style="margin-top:10px">
    <table><tr>@foreach ($p['columns'] as $c)<th>{{ $c }}</th>@endforeach</tr>
      @foreach ($p['sample'] as $r)<tr>@foreach ($r as $v)<td>{{ $v ?? '-' }}</td>@endforeach</tr>@endforeach
    </table>
  </div>
</div>
@endif

@endif

@if ($import->status === 'preview' && ($p['ok'] ?? false))
<form class="card" method="post" action="{{ route('portal.imports.confirm', $import) }}">
  @csrf
  @if ($import->type === 'students')
    <b>Cara menyimpan</b>
    @foreach (['add' => 'Tambah & perbarui', 'replace' => 'Ganti data lama'] as $m => $title)
      <label class="opt"><input type="radio" name="mode" value="{{ $m }}" @checked($import->mode === $m) @disabled(isset($p['blocked'][$m]))
          data-navigate="{{ route('portal.imports.show', $import) }}?mode=">
        <span><b>{{ $title }}</b><span class="mu">{{ $p['mode_effects'][$m] ?? '' }} {{ $p['blocked'][$m] ?? '' }}</span></span></label>
    @endforeach
  @else
    <input type="hidden" name="mode" value="add">
  @endif
  <div class="row" style="margin-top:12px">
    <button class="btn nf" type="submit">Simpan ke database sekolah</button>
    <a class="btn o nf" href="{{ route('portal.imports') }}">Batal</a>
  </div>
</form>
@endif
@endsection
