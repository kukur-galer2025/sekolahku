<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Poster Aplikasi {{ $label }} · {{ $school->name }}</title>
<style>
  @page { size: A4; margin: 14mm; }
  * { box-sizing: border-box; }
  body { font-family: Arial, Helvetica, sans-serif; color: #0f172a; margin: 0; background: #e2e8f0; }
  .page { width: 182mm; min-height: 260mm; margin: 12px auto; background: #fff; padding: 14mm; text-align: center; border-radius: 6px; }
  .brand { color: #0B63E5; font-weight: 800; font-size: 22pt; letter-spacing: .5px; }
  .brand span { color: #0ea5e9; }
  h1 { font-size: 30pt; margin: 6mm 0 2mm; }
  .school { font-size: 15pt; font-weight: 700; margin: 0 0 6mm; }
  .qr { width: 95mm; margin: 0 auto; border: 2px solid #0B63E5; border-radius: 6mm; padding: 4mm; }
  .url { font-size: 15pt; font-weight: 700; color: #0B63E5; margin: 5mm 0 7mm; word-break: break-all; }
  .steps { display: grid; grid-template-columns: 1fr 1fr; gap: 6mm; text-align: left; font-size: 11.5pt; }
  .steps div { border: 1px solid #cbd5e1; border-radius: 4mm; padding: 4mm 5mm; }
  .steps ol { margin: 2mm 0 0; padding-left: 6mm; }
  .foot { margin-top: 8mm; font-size: 10pt; color: #475569; }
  .bar { text-align: center; margin: 12px; }
  .bar button { font-size: 15px; padding: 10px 20px; border-radius: 8px; border: 0; background: #0B63E5; color: #fff; cursor: pointer; }
  @media print { body { background: #fff; } .bar { display: none; } .page { margin: 0; padding: 0; width: auto; min-height: 0; } }
</style>
<script src="/js/csp-actions.js?v=1" defer></script></head>
<body>
<div class="bar"><button data-action="print">🖨 Cetak / Simpan PDF</button></div>
<div class="page">
  @if ($logo)<img src="{{ $logo }}" alt="" style="height:22mm;object-fit:contain">@endif
  <img src="{{ asset('img/logo-vertical.svg') }}" alt="ParamaEdu.Tech" style="display:block;width:55mm;margin:0 auto 3mm">
  <div class="brand">My<span>Presence</span></div>
  <h1>Aplikasi {{ $label }}</h1>
  <p class="school">{{ $school->name }}</p>
  <div class="qr">{!! $qr !!}</div>
  <p class="url">{{ $url }}</p>
  <div style="border:2px dashed #0B63E5;border-radius:5mm;padding:4mm;margin:0 auto 7mm;max-width:130mm">
    <div style="font-size:11pt">Atau buka <b>{{ \App\Models\School::codeEntryUrl($app) }}</b> dan ketik kode sekolah:</div>
    <div style="font-size:30pt;font-weight:800;letter-spacing:4mm;margin-top:2mm">{{ \App\Support\SchoolCode::format($school->access_code) }}</div>
  </div>
  <div class="steps">
    <div><b>Android</b><ol><li>Scan QR atau buka link.</li><li>Ketuk <b>Pasang aplikasi</b>.</li><li>Buka ikon MyPresence.</li></ol></div>
    <div><b>iPhone</b><ol><li>Buka link di <b>Safari</b>.</li><li>Ketuk <b>Bagikan</b> → <b>Tambahkan ke Layar Utama</b>.</li><li>Buka ikon MyPresence.</li></ol></div>
  </div>
  <p class="foot">Masuk dengan {{ $app === 'siswa' ? 'NIS dan password awal dari wali kelas' : 'NIK dan password awal dari admin sekolah' }}, lalu ganti password.<br>Jangan berikan password ke siapa pun. · MyPresence oleh ParamaEdu.Tech</p>
</div>
</body>
</html>
