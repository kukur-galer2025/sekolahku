<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>{{ $title ?? 'Kartu Aktivasi' }} · {{ $school->name }}</title>
<style>
  @page { size: A4; margin: 10mm; }
  * { box-sizing: border-box; }
  body { font-family: Arial, Helvetica, sans-serif; color: #0f172a; margin: 0; background: #e2e8f0; }
  .bar { text-align: center; margin: 12px; font-size: 14px; }
  .bar button { font-size: 15px; padding: 10px 20px; border-radius: 8px; border: 0; background: #0B63E5; color: #fff; cursor: pointer; }
  .sheet { width: 190mm; margin: 0 auto; display: grid; grid-template-columns: 1fr 1fr; gap: 4mm; background: #fff; padding: 4mm; }
  .card { border: 1.5px dashed #64748b; border-radius: 3mm; padding: 4mm; display: flex; gap: 4mm; break-inside: avoid; page-break-inside: avoid; height: 64mm; }
  .qr { width: 30mm; flex: none; }
  .info { flex: 1; font-size: 9.5pt; line-height: 1.35; overflow: hidden; }
  .brand { color: #0B63E5; font-weight: 800; font-size: 10pt; }
  .name { font-size: 11.5pt; font-weight: 700; margin: 1mm 0; }
  .pw { font-family: 'Courier New', monospace; font-size: 13pt; font-weight: 700; letter-spacing: 1px; background: #f1f5f9; padding: 1mm 2mm; border-radius: 1.5mm; display: inline-block; }
  .url { font-size: 8.5pt; color: #0B63E5; word-break: break-all; }
  .note { font-size: 7.5pt; color: #475569; margin-top: 1.5mm; }
  @media print { body { background: #fff; } .bar { display: none; } .sheet { padding: 0; width: auto; } }
</style>
<script src="/js/csp-actions.js?v=1" defer></script></head>
<body>
<div class="bar"><button data-action="print">🖨 Cetak / Simpan PDF</button><p>{{ $cards->count() }} kartu · gunting sesuai garis putus-putus · berikan langsung ke masing-masing orang (jangan difoto ke grup).</p></div>
<div class="sheet">
  @foreach ($cards as $c)
    <div class="card">
      <div class="qr">{!! $qr[$c['url']] !!}<div class="note" style="text-align:center">Scan untuk pasang aplikasi</div></div>
      <div class="info">
        <div class="brand">MyPresence · Aplikasi {{ $c['app'] }}</div>
        <div class="mu">{{ $school->name }}</div>
        <div class="name">{{ $c['name'] }}</div>
        @if ($c['class'] && $c['class'] !== '-')<div>Kelas: {{ $c['class'] }}</div>@endif
        @if (! empty($c['subject']))<div>Mapel: {{ $c['subject'] }}</div>@endif
        <div>{{ $c['role'] === 'Guru' ? 'NIK' : 'NIS' }}: <b>{{ $c['username'] }}</b></div>
        <div>{{ $pwLabel ?? 'Password awal' }}: <span class="pw">{{ $c['password'] }}</span></div>
        <div class="url">{{ $c['url'] }}</div>
        <div>Atau buka {{ \App\Models\School::codeEntryUrl($c['role'] === 'Guru' ? 'guru' : 'siswa') }} · kode sekolah: <b>{{ \App\Support\SchoolCode::format($school->access_code) }}</b></div>
        <div class="note">Wajib ganti password saat pertama masuk. Simpan kartu ini, jangan dibagikan.</div>
      </div>
    </div>
  @endforeach
</div>
</body>
</html>
