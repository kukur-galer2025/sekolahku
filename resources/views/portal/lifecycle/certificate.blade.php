<!doctype html>
<html lang="id">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Surat Keterangan Kehadiran · {{ $s->name }}</title>
<style>
  :root{color-scheme:light}
  body{font-family:"Times New Roman",serif;color:#111;background:#fff;margin:0;padding:24px;font-size:15px;line-height:1.5}
  .page{max-width:720px;margin:0 auto}
  .kop{display:flex;gap:14px;align-items:center;border-bottom:3px double #111;padding-bottom:10px}
  .kop img{width:64px;height:64px;object-fit:contain}
  .kop h1{margin:0;font-size:20px;text-transform:uppercase}
  .kop p{margin:0;font-size:13px}
  h2{text-align:center;text-decoration:underline;margin:22px 0 0;font-size:17px}
  .no{text-align:center;margin:0 0 18px}
  table.id td{padding:2px 8px 2px 0;vertical-align:top}
  table.rk{width:100%;border-collapse:collapse;margin:12px 0}
  table.rk th,table.rk td{border:1px solid #111;padding:5px 6px;text-align:center}
  .sign{margin-top:36px;margin-left:auto;width:260px}
  .sign .gap{height:70px}
  .bar{max-width:720px;margin:0 auto 16px;font-family:system-ui,sans-serif}
  .bar button{padding:8px 16px;border-radius:8px;border:0;background:#0f766e;color:#fff;font-weight:600;cursor:pointer}
  @media print{.bar{display:none}body{padding:0}}
</style>
<script src="/js/csp-actions.js?v=1" defer></script></head>
<body>
<div class="bar"><button data-action="print">Cetak / Simpan PDF</button> <a href="{{ route('portal.students', ['status' => 'semua']) }}">Kembali</a></div>
<div class="page">
  <div class="kop">
    @if ($school->logo_path)<img src="{{ app(\App\Tenancy\TenantFiles::class)->url($school->logo_path, 60) }}" alt="">@endif
    <div><h1>{{ $school->name }}</h1><p>{{ $school->address }}@if ($school->npsn) · NPSN {{ $school->npsn }}@endif</p></div>
  </div>
  <h2>SURAT KETERANGAN KEHADIRAN</h2>
  <p class="no">Nomor: {{ $number }}</p>
  <p>Yang bertanda tangan di bawah ini, Kepala {{ $school->name }}, menerangkan bahwa:</p>
  <table class="id">
    <tr><td>Nama</td><td>:</td><td><b>{{ $s->name }}</b></td></tr>
    <tr><td>NIS / NISN</td><td>:</td><td>{{ $s->nis }} / {{ $s->nisn ?: '-' }}</td></tr>
    <tr><td>Status</td><td>:</td><td>{{ \App\Models\Student::STATUSES[$s->status] ?? $s->status }}@if ($s->left_at) sejak {{ $s->left_at->translatedFormat('j F Y') }}@endif</td></tr>
  </table>
  <p>adalah siswa kami dengan rekap kehadiran harian berdasarkan presensi MyPresence sebagai berikut:</p>
  <table class="rk">
    <tr><th>Tahun ajaran</th><th>Kelas</th><th>Hadir</th><th>Sakit</th><th>Izin</th><th>Alpa</th><th>% Hadir</th></tr>
    @forelse ($recap as $r)
      <tr><td>{{ $r['year'] }}</td><td>{{ $r['class'] }}</td><td>{{ $r['H'] }}</td><td>{{ $r['S'] }}</td><td>{{ $r['I'] }}</td><td>{{ $r['A'] }}</td><td>{{ $r['pct'] !== null ? str_replace('.', ',', $r['pct']).'%' : '-' }}</td></tr>
    @empty
      <tr><td colspan="7">Belum ada data kehadiran.</td></tr>
    @endforelse
  </table>
  <p>Demikian surat keterangan ini dibuat untuk dipergunakan sebagaimana mestinya.</p>
  <div class="sign">
    <p>{{ $city ? $city.', ' : '' }}{{ now()->translatedFormat('j F Y') }}<br>Kepala Sekolah</p>
    <div class="gap"></div>
    <p><b><u>{{ $principal ?: '........................................' }}</u></b><br>NIP {{ $nip ?: '-' }}</p>
  </div>
</div>
</body>
</html>
