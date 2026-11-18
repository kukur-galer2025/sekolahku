/* MP-Library · penampil terlindung
 * - Berkas diambil lewat tiket sekali pakai; PDF datang tersamar (XOR) dan dibuka di memori, tidak pernah jadi URL.
 * - PDF dirender ke kanvas (tanpa lapisan teks → tidak bisa disalin), watermark dibakar ke piksel kanvas.
 * - Overlay watermark dinamis (nama · NIS · sekolah · jam berjalan · kode jejak), bergeser berkala, dipasang ulang bila dihapus.
 * - Blokir klik kanan, salin, seret, cetak, pintasan simpan/cetak/DevTools; layar diburamkan saat pindah aplikasi / tangkap layar.
 * - Semua percobaan dilaporkan ke log audit (dibatasi agar tidak membanjiri server).
 * - Unduh hanya bila diizinkan: gambar sudah ber-watermark dari server, PDF dicap watermark di setiap halaman (pdf-lib).
 * Catatan jujur: proteksi di peramban adalah PENCEGAH, bukan jaminan mutlak (kamera HP tetap bisa memotret layar).
 * Karena itu kode jejak di watermark + log audit adalah alat pelacak utama bila ada kebocoran.
 */
const { api, esc, toast } = window.MP;
const PDFJS = '/vendor/pdfjs/pdf.min.js?v=6.2.108';
const PDFJS_WORKER = '/vendor/pdfjs/pdf.worker.min.js?v=6.2.108';
const PDFLIB = '/vendor/pdf-lib/pdf-lib.esm.min.js?v=1.17.1';
const ti = (n) => (window.ti ? window.ti(n) : '');

let pdfjsP = null;
const loadPdfjs = () => (pdfjsP ??= import(PDFJS).then((m) => { m.GlobalWorkerOptions.workerSrc = PDFJS_WORKER; return m; }));

/** Ambil isi berkas lewat tiket; PDF dibuka samarannya dengan kunci per tiket. */
async function fetchBytes(stream, key) {
  const res = await fetch(stream, { credentials: 'same-origin', cache: 'no-store', headers: { 'X-Requested-With': 'XMLHttpRequest' } });
  if (!res.ok) {
    const j = await res.json().catch(() => ({}));
    throw new Error(j.message || 'Berkas tidak dapat dibuka (' + res.status + ').');
  }
  const buf = new Uint8Array(await res.arrayBuffer());
  if (key) {
    const k = new TextEncoder().encode(key);
    for (let i = 0; i < buf.length; i++) buf[i] ^= k[i & 31];
  }
  return buf;
}

/** Watermark dibakar ke kanvas: teks miring berulang + pita identitas di bawah. */
function burn(ctx, w, h, lines) {
  const text = lines.join('   ·   ');
  const fs = Math.max(11, Math.round(Math.min(w, h) / 34));
  ctx.save();
  ctx.font = `700 ${fs}px Inter, system-ui, sans-serif`;
  ctx.fillStyle = 'rgba(100,116,139,.16)';
  ctx.translate(w / 2, h / 2);
  ctx.rotate(-Math.PI / 6);
  const tw = ctx.measureText(text).width + fs * 4;
  const D = Math.hypot(w, h);
  for (let y = -D / 2; y < D / 2; y += fs * 5) for (let x = -D / 2 - ((y / (fs * 5)) % 2) * tw / 2; x < D / 2; x += tw) ctx.fillText(text, x, y);
  ctx.restore();
  const band = Math.round(fs * 1.7);
  ctx.save();
  ctx.fillStyle = 'rgba(15,23,42,.62)';
  ctx.fillRect(0, h - band, w, band);
  ctx.fillStyle = '#fff';
  ctx.font = `600 ${Math.round(fs * 0.72)}px Inter, system-ui, sans-serif`;
  ctx.textBaseline = 'middle';
  ctx.fillText(text, 10, h - band / 2, w - 20);
  ctx.restore();
}

/** Pola SVG untuk overlay DOM (data: diizinkan CSP img-src). */
function wmPattern(lines) {
  const t = lines.map((l) => l.replace(/[<>&"]/g, '')).join(' · ');
  const w = Math.max(360, t.length * 7.2 + 60);
  const svg = `<svg xmlns="http://www.w3.org/2000/svg" width="${w}" height="170"><text x="0" y="110" transform="rotate(-24 ${w / 2} 85)" font-family="Inter,system-ui,sans-serif" font-size="14" font-weight="700" fill="rgba(100,116,139,.22)">${t}</text></svg>`;
  return `url("data:image/svg+xml;charset=utf-8,${encodeURIComponent(svg)}")`;
}

/** Laporan peristiwa ke server; tiap jenis paling sering sekali per 10 detik. */
function reporter(itemId, trace) {
  const last = {};
  return (event, detail) => {
    const now = Date.now();
    if (event !== 'view' && last[event] && now - last[event] < 10000) return;
    last[event] = now;
    api('library/' + itemId + '/event', { method: 'POST', body: { event, trace, detail: detail ? String(detail).slice(0, 120) : undefined } }).catch(() => {});
  };
}

/** Jembatan aplikasi Android MyPresence (WebView): FLAG_SECURE + simpan berkas. Tidak ada di peramban biasa. */
const NATIVE = window.MPNative || null;
const secure = (on) => { try { NATIVE?.setSecure(on); } catch (_) {} };

async function saveBlob(blob, name) {
  if (NATIVE?.saveFile) {
    // WebView tidak bisa mengunduh blob: kirim base64 ke aplikasi, disimpan di folder Download.
    const b64 = await new Promise((ok, no) => { const fr = new FileReader(); fr.onload = () => ok(String(fr.result).split(',')[1]); fr.onerror = no; fr.readAsDataURL(blob); });
    NATIVE.saveFile(name, blob.type || 'application/octet-stream', b64);
    return;
  }
  const a = document.createElement('a');
  a.href = URL.createObjectURL(blob);
  a.download = name;
  document.body.append(a);
  a.click();
  setTimeout(() => { URL.revokeObjectURL(a.href); a.remove(); }, 4000);
}

const safeName = (s) => (s || 'MP-Library').replace(/[^\w \-]+/g, '').trim().slice(0, 80) || 'MP-Library';
// Font standar PDF hanya mengenal Latin-1: buang karakter di luar itu (nama tetap terbaca).
const latin1 = (s) => s.normalize('NFKD').replace(/[^\x20-\x7E·]/g, '');

/** Unduhan: dicek server (kebijakan + jatah harian), lalu dicap watermark. */
async function download(itemId) {
  const r = await api('library/' + itemId + '/download', { method: 'POST' });
  const bytes = await fetchBytes(r.stream, r.key);
  if (r.type === 'image') { await saveBlob(new Blob([bytes], { type: 'image/jpeg' }), safeName(r.title) + '.jpg'); return r; }
  const { PDFDocument, StandardFonts, rgb, degrees } = await import(PDFLIB);
  let doc;
  try { doc = await PDFDocument.load(bytes); } catch (_) { throw new Error('PDF ini terkunci kata sandi sehingga tidak bisa dicap watermark. Silakan baca di aplikasi.'); }
  const font = await doc.embedFont(StandardFonts.HelveticaBold);
  const text = latin1(r.watermark.join('  ·  '));
  for (const p of doc.getPages()) {
    const { width: w, height: h } = p.getSize();
    const fs = Math.max(9, Math.min(w, h) / 40);
    const tw = font.widthOfTextAtSize(text, fs) + fs * 4;
    for (let y = -h; y < h * 2; y += fs * 6) for (let x = -w; x < w * 2; x += tw) {
      p.drawText(text, { x, y, size: fs, font, color: rgb(0.45, 0.5, 0.58), opacity: 0.16, rotate: degrees(30) });
    }
    p.drawRectangle({ x: 0, y: 0, width: w, height: fs * 2, color: rgb(0.06, 0.09, 0.16), opacity: 0.6 });
    p.drawText(text, { x: 8, y: fs * 0.65, size: fs * 0.8, font, color: rgb(1, 1, 1), maxWidth: w - 16 });
  }
  doc.setSubject('MP-Library · ' + latin1(r.watermark[0]));
  doc.setKeywords(['MP-Library', 'trace:' + r.trace]);
  doc.setProducer('MyPresence MP-Library');
  await saveBlob(new Blob([await doc.save()], { type: 'application/pdf' }), safeName(r.title) + '.pdf');
  return r;
}

/**
 * Buka koleksi. Tautan luar → tab baru (tidak bisa dilindungi). PDF/gambar → penampil terlindung.
 * onOpened(r) dipanggil setelah server mencatat pembukaan.
 */
export async function openProtected(itemId, type, { onOpened } = {}) {
  const r = await api('library/' + itemId + '/open', { method: 'POST' });
  onOpened?.(r);
  if (r.type === 'link') { location.href = r.url; return; } // cadangan; daftar koleksi membuka tautan di tab baru sendiri
  return viewer(itemId, r);
}

function viewer(itemId, r) {
  const report = reporter(itemId, r.trace);
  const started = Date.now();
  const seen = new Set();
  let total = 1, zoom = 1, pdf = null, closed = false;
  const lines = () => [r.watermark[0], r.watermark[1], new Date().toLocaleString('id-ID', { dateStyle: 'short', timeStyle: 'medium' }) + ' · #' + r.trace];

  secure(true); // di aplikasi Android: tangkap layar & rekam layar jadi hitam selama penampil terbuka
  const el = document.createElement('div');
  el.className = 'lv';
  el.setAttribute('role', 'dialog');
  el.setAttribute('aria-modal', 'true');
  el.setAttribute('aria-label', r.title);
  const dl = r.policy?.download;
  el.innerHTML = `<div class="lv-bar">
      <button class="lv-b" data-a="close" aria-label="Tutup">${ti('arrow-left')}</button>
      <div class="lv-t"><b>${esc(r.title)}</b><span><span class="lv-lock">${ti('lock')}</span> ${NATIVE ? 'Dilindungi · tangkap layar diblokir' : 'Dilindungi'} · #${esc(r.trace)}</span></div>
      <span class="lv-pg" aria-live="polite"></span>
      <button class="lv-b" data-a="out" aria-label="Perkecil">−</button><button class="lv-b" data-a="in" aria-label="Perbesar">+</button>
      ${dl?.allowed ? `<button class="lv-b w" data-a="dl" title="${dl.left === null ? 'Unduh (pengelola)' : 'Sisa ' + dl.left + 'x hari ini'}">${ti('download')}<span>Unduh</span></button>` : `<span class="lv-ro">${ti('eye')} Hanya baca</span>`}
    </div>
    <div class="lv-body"><div class="lv-pages"><div class="lv-load">Membuka dokumen terlindung…</div></div></div>
    <div class="lv-wm" aria-hidden="true"></div><div class="lv-chip" aria-hidden="true"></div>
    <div class="lv-shield" hidden><div>${ti('lock')}<b>Konten disembunyikan</b><span>Kembali ke aplikasi untuk melanjutkan membaca.</span></div></div>`;
  document.body.append(el);
  document.body.classList.add('lv-on');
  const $ = (s) => el.querySelector(s);
  const pages = $('.lv-pages'), body = $('.lv-body'), shield = $('.lv-shield'), pg = $('.lv-pg');

  // ── Watermark DOM dinamis ──
  let wm = $('.lv-wm'), chip = $('.lv-chip');
  const paintWm = () => {
    wm.style.backgroundImage = wmPattern(r.watermark);
    wm.style.backgroundPosition = `${Math.round(Math.random() * 300)}px ${Math.round(Math.random() * 170)}px`;
    chip.style.left = 8 + Math.round(Math.random() * 60) + '%';
    chip.style.top = 14 + Math.round(Math.random() * 66) + '%';
  };
  const tick = () => { chip.textContent = lines().join('  ·  '); };
  paintWm(); tick();
  const tTick = setInterval(tick, 1000), tMove = setInterval(paintWm, 12000);
  // Overlay dihapus/disembunyikan lewat DevTools → pasang ulang + laporkan.
  const mo = new MutationObserver(() => {
    if (closed) return;
    const gone = !el.contains(wm) || !el.contains(chip) || getComputedStyle(wm).display === 'none' || getComputedStyle(wm).opacity === '0';
    if (gone) {
      wm.remove(); chip.remove();
      wm = document.createElement('div'); wm.className = 'lv-wm'; chip = document.createElement('div'); chip.className = 'lv-chip';
      el.append(wm, chip); paintWm(); tick();
      report('devtools', 'Watermark dihapus/diubah');
    }
  });
  mo.observe(el, { childList: true, subtree: true, attributes: true, attributeFilter: ['style', 'class', 'hidden'] });

  // ── Pencegahan & pelaporan ──
  let hideT = null;
  const hide = (ms) => { shield.hidden = false; el.classList.add('lv-blur'); clearTimeout(hideT); if (ms) hideT = setTimeout(show, ms); };
  const show = () => { shield.hidden = true; el.classList.remove('lv-blur'); };
  const stop = (ev, name, detail) => { ev.preventDefault(); ev.stopPropagation(); report(name, detail); };
  const on = [];
  const listen = (t, type, fn, opt) => { t.addEventListener(type, fn, opt); on.push([t, type, fn, opt]); };
  listen(el, 'contextmenu', (e) => stop(e, 'contextmenu'));
  listen(el, 'dragstart', (e) => stop(e, 'drag'));
  listen(el, 'selectstart', (e) => e.preventDefault());
  listen(document, 'copy', (e) => { stop(e, 'copy'); e.clipboardData?.setData('text/plain', 'Konten MP-Library dilindungi · #' + r.trace); }, true);
  listen(document, 'cut', (e) => stop(e, 'copy', 'cut'), true);
  listen(window, 'beforeprint', () => { report('print'); hide(); });
  listen(window, 'afterprint', () => show());
  listen(document, 'keydown', (e) => {
    const k = (e.key || '').toLowerCase(), mod = e.ctrlKey || e.metaKey;
    if (k === 'escape') { e.preventDefault(); close(); return; }
    // Tombol Windows / Command ditekan → konten langsung ditutup SEBELUM kombinasi tangkap layar selesai
    // (Win+Shift+S, Win+PrtSc, Win+G, Cmd+Shift+3/4/5). Dibuka lagi 1,5 detik setelah tombol dilepas.
    if (k === 'meta' || k === 'os' || e.metaKey) {
      hide();
      if (e.shiftKey || k === 'shift') report('screenshot', (navigator.platform || '').startsWith('Mac') ? 'Cmd+Shift' : 'Win+Shift');
    }
    if (mod && ['s', 'p', 'c', 'u', 'a', 'x'].includes(k)) return stop(e, k === 'p' ? 'print' : k === 'c' || k === 'x' ? 'copy' : 'shortcut', 'Ctrl+' + k.toUpperCase());
    if (k === 'f12' || (mod && e.shiftKey && ['i', 'j', 'c', 'k'].includes(k)) || (e.metaKey && e.altKey && ['i', 'j', 'c'].includes(k))) return stop(e, 'devtools', e.key);
    if (k === 'printscreen' || (e.metaKey && e.shiftKey && ['3', '4', '5', 's'].includes(k))) { stop(e, 'screenshot', e.key); hide(2500); }
    if (mod && ['+', '=', '-'].includes(k)) { e.preventDefault(); setZoom(zoom * (k === '-' ? 1 / 1.2 : 1.2)); }
  }, true);
  listen(document, 'keyup', (e) => {
    const ku = (e.key || '').toLowerCase();
    if (ku === 'meta' || ku === 'os') hide(1500);
    if (ku === 'printscreen') { report('screenshot', 'PrintScreen'); hide(2500); navigator.clipboard?.writeText?.('Konten MP-Library dilindungi · #' + r.trace).catch(() => {}); }
  }, true);
  listen(document, 'visibilitychange', () => { if (document.hidden) { hide(); report('hidden', 'Pindah tab/aplikasi'); } else setTimeout(show, 400); });
  listen(window, 'blur', () => hide());
  if (!/Android|iPhone|iPad|Mobile/i.test(navigator.userAgent)) {
    listen(document.documentElement, 'mouseleave', () => hide());
    listen(document.documentElement, 'mouseenter', () => { if (document.hasFocus()) setTimeout(show, 300); });
  }
  listen(window, 'focus', () => setTimeout(show, 250));
  // DevTools terbuka (desktop): selisih ukuran jendela besar → sembunyikan konten.
  const desktop = !/Android|iPhone|iPad|Mobile/i.test(navigator.userAgent);
  let devOpen = false;
  const tDev = desktop ? setInterval(() => {
    const open = window.outerWidth - window.innerWidth > 200 || window.outerHeight - window.innerHeight > 240;
    if (open && !devOpen) { report('devtools', 'Panel DevTools terdeteksi'); hide(); }
    if (!open && devOpen) show();
    devOpen = open;
  }, 1500) : null;

  // ── Render ──
  const setPg = () => { pg.textContent = total > 1 ? `${Math.min(total, Math.max(1, cur))} / ${total}` : ''; };
  let cur = 1;
  const io = new IntersectionObserver((ents) => {
    for (const e of ents) if (e.isIntersecting) { const n = +e.target.dataset.n; seen.add(n); cur = n; setPg(); if (!e.target.dataset.done) drawPage(e.target); }
  }, { root: body, rootMargin: '600px 0px' });

  async function drawPage(box) {
    if (!pdf || closed) return;
    box.dataset.done = '1';
    const page = await pdf.getPage(+box.dataset.n);
    const base = page.getViewport({ scale: 1 });
    const cssW = Math.min(body.clientWidth - 24, 900) * zoom;
    const scale = cssW / base.width, dpr = Math.min(2, window.devicePixelRatio || 1);
    const vp = page.getViewport({ scale: scale * dpr });
    const c = document.createElement('canvas');
    c.width = Math.floor(vp.width); c.height = Math.floor(vp.height);
    c.style.width = Math.floor(vp.width / dpr) + 'px'; c.style.height = Math.floor(vp.height / dpr) + 'px';
    const ctx = c.getContext('2d');
    await page.render({ canvasContext: ctx, canvas: c, viewport: vp }).promise;
    burn(ctx, c.width, c.height, lines());
    box.replaceChildren(c);
    box.style.height = '';
  }

  function layout(sizes) {
    pages.innerHTML = '';
    io.disconnect();
    const cssW = Math.min(body.clientWidth - 24, 900) * zoom;
    sizes.forEach((s, i) => {
      const b = document.createElement('div');
      b.className = 'lv-page'; b.dataset.n = i + 1;
      b.style.width = cssW + 'px'; b.style.height = Math.round(cssW * s) + 'px';
      pages.append(b); io.observe(b);
    });
  }
  let ratios = [];
  function setZoom(z) { zoom = Math.min(3, Math.max(0.5, z)); if (pdf) layout(ratios); else if (img) placeImg(); }

  let img = null;
  function placeImg() {
    const cssW = Math.min(body.clientWidth - 24, 1100) * zoom;
    const c = document.createElement('canvas');
    c.width = img.width; c.height = img.height;
    c.style.width = cssW + 'px'; c.style.height = Math.round(cssW * img.height / img.width) + 'px';
    const ctx = c.getContext('2d');
    ctx.drawImage(img, 0, 0);
    burn(ctx, c.width, c.height, lines());
    const b = document.createElement('div'); b.className = 'lv-page'; b.append(c);
    pages.replaceChildren(b);
  }

  (async () => {
    try {
      const bytes = await fetchBytes(r.stream, r.key);
      if (closed) return;
      if (r.type === 'image') {
        img = await createImageBitmap(new Blob([bytes], { type: 'image/jpeg' }));
        seen.add(1); placeImg();
      } else {
        const lib = await loadPdfjs();
        pdf = await lib.getDocument({ data: bytes, isEvalSupported: false, enableXfa: false, disableAutoFetch: true }).promise;
        total = pdf.numPages;
        // Ukuran halaman dibaca dari 10 halaman pertama; sisanya diasumsikan sama (dirapikan saat dirender).
        for (let i = 1; i <= total; i++) {
          if (i <= 10) { const v = (await pdf.getPage(i)).getViewport({ scale: 1 }); ratios.push(v.height / v.width); } else ratios.push(ratios[ratios.length - 1]);
        }
        layout(ratios); setPg();
      }
    } catch (x) {
      pages.innerHTML = `<div class="lv-load er">${esc(x.message || 'Dokumen gagal dibuka.')}</div>`;
    }
  })();

  // ── Tombol ──
  el.querySelector('.lv-bar').onclick = async (e) => {
    const b = e.target.closest('[data-a]'); if (!b) return;
    const a = b.dataset.a;
    if (a === 'close') close();
    if (a === 'in') setZoom(zoom * 1.2);
    if (a === 'out') setZoom(zoom / 1.2);
    if (a === 'dl') {
      b.disabled = true;
      try {
        const d = await download(itemId);
        toast(d.left === null ? 'Diunduh dengan watermark.' : `Diunduh dengan watermark. Sisa ${d.left}x hari ini.`);
        if (d.left === 0) b.replaceWith(Object.assign(document.createElement('span'), { className: 'lv-ro', textContent: 'Jatah unduh habis' }));
      } catch (x) { toast(x.message, true); } finally { b.disabled = false; }
    }
  };
  const onPop = () => close(true);
  history.pushState({ lv: 1 }, '');
  listen(window, 'popstate', onPop);

  function close(fromPop) {
    if (closed) return;
    closed = true;
    const sec = Math.round((Date.now() - started) / 1000);
    report('view', `${Math.floor(sec / 60)}m ${sec % 60}d · ${seen.size}/${total} halaman`);
    clearInterval(tTick); clearInterval(tMove); if (tDev) clearInterval(tDev); clearTimeout(hideT);
    mo.disconnect(); io.disconnect();
    on.forEach(([t, type, fn, opt]) => t.removeEventListener(type, fn, opt));
    pdf?.destroy?.(); img?.close?.();
    el.remove();
    document.body.classList.remove('lv-on');
    secure(false);
    if (!fromPop && history.state?.lv) history.back();
  }
  return { close };
}

/** Untuk tombol "Unduh" di daftar koleksi (tanpa membuka penampil). */
export { download };
