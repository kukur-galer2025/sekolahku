@extends('panel.layout')
@section('title', 'Operasional')
@section('content')
@php $wib = fn ($t) => \Illuminate\Support\Carbon::createFromTimestamp($t)->timezone('Asia/Jakarta')->format('d/m/Y H:i'); @endphp
<h2>Operasional</h2>
<p class="sub">Kesehatan server, konfigurasi produksi, backup, dan data yang menunggu tindakan. Status keseluruhan:
  {!! $health['status'] === 'ok' ? '<span class="pl ok">Sehat</span>' : '<span class="pl er">Ada masalah</span>' !!}</p>

<div class="two" style="align-items:start">
  <div class="card">
    <b>Kesehatan server</b>
    <div class="sc" style="margin-top:8px"><table>
      @foreach ($health['checks'] as $k => $c)
        <tr><td>{{ ['database' => 'Database', 'cache' => 'Cache', 'scheduler' => 'Cron (scheduler)', 'queue' => 'Antrean', 'storage' => 'Penyimpanan', 'disk' => 'Disk'][$k] ?? $k }}</td>
          <td>{!! $c['ok'] ? '<span class="pl ok">OK</span>' : '<span class="pl er">Masalah</span>' !!}</td><td class="mu wrap">{{ $c['detail'] }}</td></tr>
      @endforeach
    </table></div>
  </div>
  <div class="card">
    <b>Konfigurasi produksi</b>
    <div class="sc" style="margin-top:8px"><table>
      @foreach ($config as [$label, $ok, $detail])
        <tr><td>{{ $label }}</td><td>{!! $ok ? '<span class="pl ok">OK</span>' : '<span class="pl wr">Periksa</span>' !!}</td><td class="mu wrap">{{ $detail }}</td></tr>
      @endforeach
    </table></div>
  </div>
</div>

<div class="card">
  <div class="row" style="justify-content:space-between;align-items:center">
    <div><b>Backup database penuh</b>
      <div class="mu">Otomatis tiap hari 00:45 WIB, disimpan {{ config('tenancy.backup_keep', 14) }} terakhir di luar public_html. <b>Unduh seminggu sekali ke laptop/Google Drive</b> sebagai salinan di luar server.</div></div>
    <form method="post" action="{{ route('panel.ops.backup') }}">@csrf<button class="btn nf" type="submit">Backup sekarang</button></form>
  </div>
  @if ($dbStale)<div class="alert wr" style="margin-top:10px">Belum ada backup database dalam 26 jam terakhir. Pastikan cron <code>schedule:run</code> berjalan, atau tekan Backup sekarang.</div>@endif
  <div class="sc" style="margin-top:10px"><table><tr><th>Berkas</th><th>Waktu (WIB)</th><th>Ukuran</th><th></th></tr>
    @forelse ($dbBackups as $b)
      <tr><td>{{ $b['name'] }}</td><td>{{ $wib($b['time']) }}</td><td>{{ \App\Services\Platform\SchoolUsage::bytes($b['size']) }}</td>
        <td><a class="linkbtn" href="{{ route('panel.ops.download', $b['name']) }}">Unduh</a></td></tr>
    @empty <tr><td colspan="4" class="mu">Belum ada backup database.</td></tr> @endforelse
  </table></div>
  <p class="mu" style="margin-top:8px">Memulihkan: buka ZIP dengan 7-Zip (password = BACKUP_PASSWORD di .env) → phpMyAdmin → pilih database → Impor → database.sql.</p>
</div>

<div class="two" style="align-items:start">
  <div class="card">
    <b>Backup per sekolah</b>
    <p class="mu" style="margin:4px 0 8px">Otomatis 01:30 WIB. {{ $schoolCount - $noBackup->count() }} dari {{ $schoolCount }} sekolah punya backup &lt; 26 jam.</p>
    @forelse ($noBackup as $s)
      <div class="step"><span class="grow"><a href="{{ route('panel.schools.show', $s) }}">{{ $s->name }}</a> <span class="mu">{{ $s->subdomain }}</span></span><span class="pl wr">Belum ada backup baru</span></div>
    @empty <div class="mu">Semua sekolah aktif sudah ter-backup.</div> @endforelse
  </div>
  <div class="card">
    <b>Wildcard SSL *.{{ config('tenancy.central_domain') }}</b>
    <p class="mu" style="margin:4px 0 8px">Bila aktif, sekolah baru langsung bisa dibuka lewat HTTPS tanpa membuat domain manual di cPanel.</p>
    @if ($ssl)<div class="alert {{ $ssl['ok'] ? 'ok' : 'wr' }}">{{ $ssl['detail'] }} <span class="mu">· dicek {{ \Illuminate\Support\Carbon::parse($ssl['at'])->timezone('Asia/Jakarta')->format('d/m H:i') }}</span></div>@endif
    <form method="post" action="{{ route('panel.ops.ssl') }}">@csrf<button class="btn o nf" type="submit">Cek sekarang</button></form>
  </div>
</div>

<div class="card">
  <b>Data yang menunggu tindakan</b>
  <div class="sc" style="margin-top:8px"><table><tr><th>Sekolah</th><th>Alasan</th><th>Sejak</th></tr>
    @foreach ($deletion as $s)
      <tr><td><a href="{{ route('panel.schools.show', $s) }}">{{ $s->name }}</a></td><td><span class="pl er">Minta data dihapus</span> <span class="mu">{{ $s->deletion_reason }}</span></td><td>{{ $s->deletion_requested_at->timezone('Asia/Jakarta')->format('d/m/Y') }}</td></tr>
    @endforeach
    @foreach ($expired as $s)
      <tr><td><a href="{{ route('panel.schools.show', $s) }}">{{ $s->name }}</a></td><td><span class="pl wr">Langganan berhenti &gt; 30 hari</span> <span class="mu">hubungi sekolah, beri ekspor data, lalu hapus permanen</span></td><td>{{ $s->suspended_at->timezone('Asia/Jakarta')->format('d/m/Y') }}</td></tr>
    @endforeach
    @if ($deletion->isEmpty() && $expired->isEmpty())<tr><td colspan="3" class="mu">Tidak ada.</td></tr>@endif
  </table></div>
</div>

<div class="card">
  <b>Job gagal terbaru</b>
  <div class="sc" style="margin-top:8px"><table><tr><th>Waktu</th><th>Antrean</th><th>Pesan</th></tr>
    @forelse ($failed as $f)
      <tr><td>{{ \Illuminate\Support\Carbon::parse($f->failed_at)->timezone('Asia/Jakarta')->format('d/m H:i') }}</td><td>{{ $f->queue }}</td><td class="mu wrap">{{ \Illuminate\Support\Str::limit(strtok((string) $f->exception, "\n"), 160) }}</td></tr>
    @empty <tr><td colspan="3" class="mu">Tidak ada job gagal.</td></tr> @endforelse
  </table></div>
</div>
@endsection
