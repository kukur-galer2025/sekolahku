@extends('portal.layout')
@section('title', 'Tahun Ajaran')
@section('content')
@php $cls = collect($p['classes']); $total = $cls->sum(fn ($c) => count($c['students'])); @endphp
<h2>Tahun Ajaran & Kelulusan</h2>
<p class="sub">Tahun ajaran berjalan <b>{{ $p['from'] }}</b>. Tutup Tahun Ajaran memindahkan siswa ke kelas tingkat berikutnya, mencatat yang tinggal kelas, dan meluluskan tingkat akhir sekaligus. Nama kelas tidak berubah; riwayat {{ $p['from'] }} tetap tersimpan.</p>
@if ($errors->any())<div class="alert er">{{ $errors->first() }}</div>@endif

@php $last = $history->first(); @endphp
@if ($last && ! $last->undone_at && $last->to_year === $p['from'])
  <div class="card">
    <b>Langkah berikutnya untuk tahun ajaran {{ $p['from'] }}</b>
    <div class="steps" style="margin-top:8px">
      <a class="step" href="{{ route('portal.imports') }}" style="text-decoration:none;color:inherit"><span class="grow">1. Impor siswa baru tingkat {{ $school->grades()[0] }}<span class="mu" style="display:block">Pilih <b>Tambah &amp; perbarui</b> (bukan Ganti data lama) agar kakak kelas tidak ikut nonaktif.</span></span><span>→</span></a>
      <a class="step" href="{{ route('portal.imports') }}" style="text-decoration:none;color:inherit"><span class="grow">2. Cetak kartu aktivasi siswa baru<span class="mu" style="display:block">Tombol "Kartu aktivasi" di hasil impor.</span></span><span>→</span></a>
      <a class="step" href="{{ route('portal.classrooms') }}" style="text-decoration:none;color:inherit"><span class="grow">3. Atur wali kelas tahun ini<span class="mu" style="display:block">Kelas & Wali.</span></span><span>→</span></a>
      <a class="step" href="{{ route('portal.share') }}" style="text-decoration:none;color:inherit"><span class="grow">4. Bagikan aplikasi ke siswa & orang tua baru</span><span>→</span></a>
    </div>
  </div>
@endif
@if ($p['already'])
  <div class="alert ok">{{ $p['already_message'] ?? 'Tahun ajaran '.$p['from'].' sudah ditutup.' }} Lihat riwayat di bawah.</div>
@elseif (! $cls->count())
  <div class="alert wr">Belum ada kelas. Buat kelas dulu di <a href="{{ route('portal.classrooms') }}">Kelas & Wali</a>.</div>
@else
<form class="card" method="post" action="{{ route('portal.year.close') }}">
  @csrf
  <b>Tutup tahun ajaran {{ $p['from'] }} → {{ $p['to'] }}</b>
  <p class="mu" style="margin:4px 0 10px">Periksa kelas tujuan tiap kelas. Bawaan: tingkat berikutnya dengan rombel yang sama (7A → 8A); tingkat akhir → Lulus. Buka daftar siswa untuk mencentang yang <b>tinggal kelas</b>.
    @if (now()->month >= 1 && now()->month <= 4)<br><span class="pl wr">Perhatian: sekarang masih semester genap berjalan. Biasanya tahun ajaran ditutup Juni–Juli.</span>@endif</p>
  <div class="sc">
    <table>
      <tr><th>Kelas sekarang</th><th>Siswa aktif</th><th>Tahun {{ $p['to'] }} menjadi</th><th>Tinggal kelas</th></tr>
      @foreach ($cls as $c)
        <tr>
          <td><b>{{ $c['name'] }}</b> <span class="mu">tingkat {{ $c['grade'] ?? '?' }}</span></td>
          <td>{{ count($c['students']) }}</td>
          <td><select name="mapping[{{ $c['id'] }}]" aria-label="Tujuan {{ $c['name'] }}">
            <option value="lulus" @selected($c['target'] === 'lulus')>🎓 Lulus</option>
            <option value="tetap" @selected($c['target'] === 'tetap')>Tetap di {{ $c['name'] }}</option>
            @foreach ($classrooms as $t)@if ($t->id !== $c['id'])<option value="{{ $t->id }}" @selected($c['target'] === $t->id)>Naik ke {{ $t->name }}</option>@endif @endforeach
          </select></td>
          <td>@if (count($c['students']))<details><summary class="linkbtn">Pilih siswa</summary>
            @foreach ($c['students'] as $s)<label class="check" style="margin:4px 0"><input type="checkbox" name="repeaters[]" value="{{ $s['id'] }}"> {{ $s['name'] }} <span class="mu">{{ $s['nis'] }}</span></label>@endforeach
          </details>@else<span class="mu">-</span>@endif</td>
        </tr>
      @endforeach
    </table>
  </div>
  @if ($p['unassigned'])<p class="mu">{{ $p['unassigned'] }} siswa aktif belum punya kelas: tidak diubah.</p>@endif
  <div class="alert wr" style="margin-top:12px">Siswa yang lulus: akun berubah menjadi <b>alumni (hanya lihat rekap) selama {{ \App\Models\Student::ALUMNI_DAYS }} hari</b>, lalu nonaktif otomatis. Kuota paket langsung longgar. Bisa dibatalkan dalam {{ \App\Models\AcademicYearClosure::UNDO_DAYS }} hari.</div>
  <div class="form-grid">
    <div><label for="confirm">{{ $twoFactor ? 'Kode 2FA (aplikasi authenticator)' : 'Ketik TUTUP untuk konfirmasi' }}</label>
      <input id="confirm" name="confirm" required autocomplete="one-time-code" inputmode="{{ $twoFactor ? 'numeric' : 'text' }}" maxlength="20"></div>
  </div>
  <button class="btn" type="submit" style="margin-top:12px" data-confirm="Tutup tahun ajaran {{ $p['from'] }} untuk {{ $total }} siswa aktif?">Tutup tahun ajaran</button>
</form>
@endif

<div class="card">
  <b>Riwayat</b>
  <div class="sc" style="margin-top:8px">
    <table>
      <tr><th>Waktu</th><th>Tahun</th><th>Naik</th><th>Tinggal</th><th>Lulus</th><th>Oleh</th><th></th></tr>
      @forelse ($history as $h)
        <tr>
          <td>{{ $h->created_at->timezone($school->timezone)->translatedFormat('j M Y H:i') }}</td>
          <td>{{ $h->from_year }} → {{ $h->to_year }}</td>
          <td>{{ $h->summary['naik'] ?? 0 }}</td><td>{{ $h->summary['tinggal'] ?? 0 }}</td><td>{{ $h->summary['lulus'] ?? 0 }}</td>
          <td>{{ $h->creator?->name ?? '-' }}</td>
          <td><a class="linkbtn" href="{{ route('portal.year.export', $h) }}">Unduh arsip</a>
            @if ($h->undone_at)<span class="pl mu">Dibatalkan</span>
            @elseif ($h->canUndo() && $loop->first)
              <form method="post" action="{{ route('portal.year.undo', $h) }}" class="row" style="gap:6px">@csrf
                <input name="confirm" required maxlength="20" placeholder="{{ $twoFactor ? 'Kode 2FA' : 'Ketik TUTUP' }}" style="width:110px" aria-label="Konfirmasi batal">
                <button class="linkbtn" type="submit" data-confirm="Batalkan penutupan {{ $h->from_year }}?">Batalkan</button></form>
            @endif</td>
        </tr>
      @empty
        <tr><td colspan="7" class="mu">Belum pernah ditutup lewat MyPresence.</td></tr>
      @endforelse
    </table>
  </div>
</div>
@endsection
