@extends('portal.layout')
@section('title', 'Data & Privasi')
@section('content')
@php [$ok, $all] = $consentTotal; @endphp
<h2>Data & Privasi</h2>
<p class="sub">Kewajiban sekolah sebagai pengendali data menurut UU 27/2022 dan PP 33/2026, di satu tempat.</p>
@if ($errors->any())<div class="alert er">{{ $errors->first() }}</div>@endif

<div class="gr">
  <div class="card"><div class="mu">Perjanjian Pemrosesan Data</div><div class="big" style="font-size:18px">{!! $dpa ? '<span class="pl ok">Disetujui</span>' : '<span class="pl er">Belum</span>' !!}</div>
    @if ($dpa)<div class="mu">{{ $dpa->signer_name }} · v{{ $dpa->version }} · {{ $dpa->accepted_at->format('d/m/Y') }}</div>@endif</div>
  <div class="card"><div class="mu">Persetujuan orang tua/wali</div><div class="big">{{ $ok }}<span class="mu" style="font-size:14px"> / {{ $all }} siswa</span></div>
    @if ($all)<div class="bar"><i style="width:{{ round($ok / $all * 100) }}%"></i></div>@endif</div>
  <div class="card"><div class="mu">Hak subjek data</div><div class="big" style="font-size:18px">3×24 jam</div><div class="mu">batas waktu menanggapi permintaan</div></div>
</div>

<div class="card">
  <b>Persetujuan orang tua/wali per kelas</b>
  <p class="mu" style="margin:4px 0 10px">Mulai 16 Januari 2027 (PP 33/2026), pemrosesan data anak wajib disertai persetujuan orang tua/wali yang terverifikasi, dan buktinya disimpan. Kumpulkan persetujuan (formulir bertanda tangan atau elektronik terverifikasi), lalu catat di sini beserta nomor/keterangan buktinya.</p>
  <div class="sc"><table><tr><th>Kelas</th><th>Sudah</th><th>Belum</th><th>Progres</th><th></th></tr>
    @forelse ($byClass as $c)
      <tr><td><b>{{ $c['name'] }}</b></td><td>{{ $c['consented'] }}</td><td>{{ $c['total'] - $c['consented'] }}</td>
        <td><div class="bar" style="width:140px"><i style="width:{{ round($c['consented'] / $c['total'] * 100) }}%"></i></div></td>
        <td><a class="btn o" href="{{ route('portal.privacy.consent', ['kelas' => $c['id']]) }}">Catat</a></td></tr>
    @empty <tr><td colspan="5" class="mu">Belum ada siswa aktif.</td></tr> @endforelse
  </table></div>
</div>

<div class="three" style="align-items:start">
  <div class="card">
    <b>Ekspor seluruh data</b>
    <p class="mu" style="margin:6px 0 12px">Satu berkas Excel: siswa, guru, kelas, Jam KBM, jadwal, presensi, dan log akses. Berkas tersedia 7 hari.</p>
    <form method="post" action="{{ route('portal.privacy.export') }}">@csrf<button class="btn" type="submit">Buat ekspor</button></form>
    <div class="steps">
      @foreach ($exports as $e)
        <div class="step"><span class="grow">{{ $e->created_at->timezone($school->timezone)->format('d/m/Y H:i') }}<span class="mu" style="display:block">{{ $e->user?->name }} · {{ $e->status === 'done' && $e->file_size ? number_format($e->file_size / 1024, 0, ',', '.').' KB' : '' }}</span></span>
          @if ($e->status === 'done' && $e->file_path)<a class="btn o nf" href="{{ route('portal.privacy.export.download', $e) }}">Unduh</a>
          @elseif ($e->status === 'queued')<span class="pl wr">Diproses</span>
          @elseif ($e->status === 'failed')<span class="pl er">Gagal</span>
          @else<span class="pl mu">Kedaluwarsa</span>@endif</div>
      @endforeach
    </div>
  </div>
  <div class="card">
    <b>Masa simpan data siswa keluar/lulus</b>
    <p class="mu" style="margin:4px 0 8px">Setelah masa ini, nama, NIS, NISN, tanggal lahir, dan akun siswa yang lulus/mutasi/keluar dihapus otomatis (dianonimkan). Rekap kehadiran tetap untuk statistik tanpa nama. Sekarang: {{ $leftCount }} siswa keluar/lulus, {{ $archivedCount }} sudah dianonimkan.</p>
    <form class="row" method="post" action="{{ route('portal.privacy.retention') }}">@csrf
      <div><label for="days">Simpan selama</label><select id="days" name="days">
        @foreach (\App\Http\Controllers\Portal\PrivacyController::RETENTION_OPTIONS as $d)<option value="{{ $d }}" @selected($retention === $d)>{{ $d >= 365 ? ($d / 365).' tahun' : '6 bulan' }} ({{ $d }} hari)</option>@endforeach
      </select></div>
      <button class="btn nf" type="submit">Simpan</button>
    </form>
    <p class="mu" style="margin-top:6px">Admin mendapat pemberitahuan 30 hari sebelumnya. Unduh Surat Keterangan Kehadiran dulu bila masih diperlukan.</p>
  </div>
  <div class="card">
    <b>Dokumen</b>
    <div class="steps">
      @foreach (config('legal.documents') as $d)<a class="step" href="{{ route('legal.show', $d['slug']) }}" target="_blank" style="text-decoration:none;color:inherit"><span class="grow">{{ $d['title'] }}<span class="mu" style="display:block">versi {{ $d['version'] }}</span></span><span>↗</span></a>@endforeach
    </div>
  </div>
  <div class="card danger">
    <b>Hapus seluruh data sekolah</b>
    @if ($school->deletion_requested_at)
      <div class="alert wr" style="margin-top:8px">Diminta {{ $school->deletion_requested_at->timezone($school->timezone)->format('d/m/Y H:i') }}. Alasan: {{ $school->deletion_reason }}. Tim Parama Tech akan menghubungi admin sebelum menghapus.</div>
      <form method="post" action="{{ route('portal.privacy.delete.cancel') }}">@csrf<button class="btn o" type="submit">Batalkan permintaan</button></form>
    @else
      <p class="mu" style="margin:6px 0 4px">Semua data, berkas, dan backup sekolah dihapus permanen setelah dikonfirmasi Parama Tech. Ekspor data dulu bila masih dibutuhkan.</p>
      <form method="post" action="{{ route('portal.privacy.delete') }}">@csrf
        <label for="reason">Alasan</label><input id="reason" name="reason" required>
        <label class="check"><input type="checkbox" name="confirm" value="1"><span>Saya sudah mengekspor data yang masih dibutuhkan.</span></label>
        <button class="btn er" type="submit" style="margin-top:10px">Ajukan penghapusan</button></form>
    @endif
  </div>
</div>
@endsection
