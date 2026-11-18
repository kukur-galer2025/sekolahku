<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>QR Presensi Kelas · {{ $school->name }}</title>
<link rel="icon" href="/img/logo.png">
<style>
@font-face{font-family:Poppins;src:url(/fonts/poppins-latin-400.woff) format('woff');font-weight:400}
@font-face{font-family:Poppins;src:url(/fonts/poppins-latin-700.woff) format('woff');font-weight:700}
*{box-sizing:border-box;margin:0}body{font-family:Poppins,system-ui,sans-serif;color:#0B2D6B;background:#EEF3FA}
.bar{display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;max-width:1000px;margin:16px auto;padding:0 16px}
.bar button{font:inherit;font-weight:700;background:#0B63E5;color:#fff;border:0;border-radius:8px;padding:10px 16px;cursor:pointer}
.warn{max-width:1000px;margin:0 auto 12px;padding:10px 14px;border-radius:10px;background:#FFF4E5;border:1px solid #F5C27A;font-size:13px}
.grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(300px,1fr));gap:16px;max-width:1000px;margin:0 auto 32px;padding:0 16px}
.card{background:#fff;border:2px solid #0B63E5;border-radius:16px;padding:18px;text-align:center;break-inside:avoid;page-break-inside:avoid}
.card .hd{display:flex;align-items:center;justify-content:center;gap:8px;font-weight:700}.card .hd img{width:28px;height:28px}
.card h2{font-size:30px;margin:6px 0 2px}.card .qr svg{width:230px;height:230px}.card .code{font-family:ui-monospace,Menlo,monospace;font-size:12px;color:#647797;word-break:break-all}
.card .st{font-size:12px;color:#647797;margin-top:6px}
@media print{body{background:#fff}.bar,.warn{display:none}.grid{grid-template-columns:repeat(2,1fr);gap:10mm;padding:0;max-width:none}@page{margin:10mm}}
</style>
<script src="/js/csp-actions.js?v=1" defer></script></head>
<body>
<div class="bar"><div><b>QR presensi kelas · {{ $school->name }}</b><div style="font-size:12px;color:#647797">{{ $cards->count() }} kelas · cetak, laminasi, tempel di depan kelas.</div></div><button type="button" data-action="print">🖨️ Cetak</button></div>
@if ($dynamic)<div class="warn">Mode QR sekolah saat ini <b>dinamis</b> (QR berganti tiap 30 detik di layar guru). Lembar statis ini baru berlaku jika mode diubah ke <b>statis</b> di Pengaturan Presensi.</div>@endif
<div class="warn">Siswa hanya bisa presensi dari <b>WiFi sekolah</b> dengan HP terdaftar. Jika QR bocor/difoto, tekan <b>Perbarui QR</b> untuk kelas itu lalu cetak ulang.</div>
<div class="grid">
@if ($gate)
  <div class="card" style="border-color:#C2410C"><div class="hd"><img src="/pwa/icons/icon-192.png" alt="">MyPresence</div>
    <h2 style="font-size:24px">Kehadiran Guru</h2><div class="qr">{!! $gate['svg'] !!}</div>
    <div class="code">{{ $gate['payload'] }}</div>
    <div class="st">Tempel di ruang guru / gerbang. Guru: Absensi → Kehadiran saya → Scan QR kehadiran (dari WiFi sekolah).</div></div>
@endif
@foreach ($cards as $c)
  <div class="card"><div class="hd"><img src="/pwa/icons/icon-192.png" alt="">MyPresence</div>
    <h2>{{ $c['name'] }}</h2><div class="qr">{!! $c['svg'] !!}</div>
    <div class="code">{{ $c['payload'] }}</div>
    <div class="st">Buka aplikasi MyPresence → Absensi → Scan QR · {{ $school->name }}</div></div>
@endforeach
</div>
</body>
</html>
