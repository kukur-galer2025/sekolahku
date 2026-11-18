<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title>Kartu Token {{ $exam->code }} — {{ $school->name }}</title>
<link rel="icon" href="/img/logo.png">
<style>
@font-face{font-family:Inter;src:url(/fonts/inter-latin-400.woff) format('woff');font-weight:400}
@font-face{font-family:Inter;src:url(/fonts/inter-latin-600.woff) format('woff');font-weight:600}
@font-face{font-family:Inter;src:url(/fonts/inter-latin-700.woff) format('woff');font-weight:700 800}
*{box-sizing:border-box;margin:0}
body{font-family:Inter,system-ui,-apple-system,"Segoe UI",sans-serif;color:#182433;background:#F1F5F9;font-size:12px;line-height:1.4}
.bar{max-width:820px;margin:16px auto;padding:0 16px;display:flex;justify-content:space-between;gap:12px;flex-wrap:wrap;align-items:center}
.bar button{font:inherit;font-weight:600;background:#066FD1;color:#fff;border:0;border-radius:8px;padding:9px 16px;cursor:pointer}
.bar p{color:#66758C;margin-top:2px}
.sheet{max-width:820px;margin:0 auto 24px;background:#fff;padding:10mm;display:grid;grid-template-columns:1fr 1fr;gap:0;border-radius:10px}
.card{border:1px dashed #9AA9BD;padding:5mm 6mm;break-inside:avoid;display:flex;flex-direction:column;gap:6px;min-height:86mm}
.hd{display:flex;justify-content:space-between;align-items:flex-start;gap:8px;border-bottom:2px solid #182433;padding-bottom:5px}
.hd b{font-size:11px;letter-spacing:.3px}.hd small{display:block;color:#66758C;font-size:10px}
.kind{font-size:10px;font-weight:700;letter-spacing:1px;background:#182433;color:#fff;padding:3px 7px;border-radius:4px;white-space:nowrap}
.ttl{font-size:13px;font-weight:700}
dl{display:grid;grid-template-columns:62px 1fr;gap:1px 6px;font-size:11px}
dt{color:#66758C}dd{font-weight:600}
.cls{font-size:16px;font-weight:800}
.tok{border:2px solid #182433;border-radius:8px;text-align:center;padding:6px 4px 4px;margin-top:2px}
.tok small{display:block;font-size:9px;letter-spacing:1px;color:#66758C;font-weight:700}
.tok span{font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;font-size:34px;font-weight:800;letter-spacing:8px;padding-left:8px}
.note{font-size:9.5px;color:#66758C}
.sign{display:flex;justify-content:space-between;gap:10px;font-size:10px;margin-top:auto;padding-top:6px}
.sign span{border-top:1px solid #182433;padding-top:2px;min-width:40%;text-align:center}
@media print{body{background:#fff}.bar{display:none}.sheet{max-width:none;margin:0;padding:0;border-radius:0}@page{size:A4;margin:8mm}}
@media(max-width:640px){.sheet{grid-template-columns:1fr;padding:12px}}
</style>
<script src="/js/csp-actions.js?v=1" defer></script></head>
<body>
<div class="bar">
  <div><b>Kartu token · {{ $exam->title }}</b><p>{{ $classes->count() }} kelas · token berbeda tiap kelas · gunting di garis putus-putus, berikan ke pengawas masing-masing kelas.</p></div>
  <button type="button" data-action="print">Cetak / Simpan PDF</button>
</div>
<div class="sheet">
@foreach ($classes as $c)
  <div class="card">
    <div class="hd"><div><b>{{ \Illuminate\Support\Str::upper($school->name) }}</b><small>Ujian berbasis komputer (CBT) · MyPresence</small></div><span class="kind">KARTU TOKEN</span></div>
    <div class="ttl">{{ $exam->title }}</div>
    <dl>
      <dt>Kelas</dt><dd class="cls">{{ $c->classroom?->name }}</dd>
      <dt>Mapel</dt><dd>{{ $exam->subject }}</dd>
      <dt>Kode</dt><dd>{{ $exam->code }}</dd>
      <dt>Jadwal</dt><dd>{{ $date }} · {{ $time }}</dd>
      <dt>Durasi</dt><dd>{{ $exam->duration_minutes }} menit · {{ $questions }} soal · {{ (int) ($counts[$c->classroom_id] ?? 0) }} peserta</dd>
    </dl>
    <div class="tok"><small>TOKEN KELAS {{ \Illuminate\Support\Str::upper($c->classroom?->name) }}</small><span>{{ $c->token }}</span></div>
    <p class="note">Rahasiakan sebelum ujian dimulai. Tulis token di papan saat jadwal mulai. Token hanya berlaku untuk kelas ini. Siswa yang terputus melanjutkan tanpa token.</p>
    <div class="sign"><span>Pengawas</span><span>Paraf</span></div>
  </div>
@endforeach
</div>
</body>
</html>
