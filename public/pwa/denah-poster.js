/* MyPresence — Penyusun Denah (koordinator Sarpras).
 * Denah disimpan sebagai data (gedung → lantai/bagian → baris → ruang), digambar menjadi poster SVG,
 * lalu dijadikan JPG di HP/laptop koordinator dan diunggah. Admin, guru, dan siswa hanya melihat JPG-nya.
 */
const W = 2400;
export const KINDS = {
  x: ['Kelas X', '#0F7C8C', '#0B5F6B', '#FFFFFF'],
  xi: ['Kelas XI', '#F4B90B', '#C99300', '#3A2A00'],
  xii: ['Kelas XII', '#5E9440', '#467230', '#FFFFFF'],
  lab: ['Laboratorium', '#EDE9FE', '#7C6BD6', '#3B2F86'],
  kantor: ['Kantor / staf', '#FFF1E6', '#E59A5C', '#7A3D0C'],
  pus: ['Perpustakaan', '#F97316', '#C2570C', '#FFFFFF'],
  fas: ['Fasilitas', '#F1F5F9', '#B6C2D1', '#334155'],
  aula: ['Aula / lapangan', '#F0FDF4', '#86C79A', '#2F7A45'],
  tangga: ['Tangga', '', '', ''],
  wc: ['Toilet', '', '', ''],
  kosong: ['Lorong / kosong', '', '', ''],
  spasi: ['Jarak (tak terlihat)', '', '', ''],
};
const SOLID = ['x', 'xi', 'xii', 'pus'];
const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
const ROW_H = { besar: 170, kecil: 104 };

/** Gambar poster. layout: {title, subtitle, note, buildings:[{name, note, span, sections:[{label, rows:[{size, rooms:[{num,name,kind,cap,w}]}]}]}]} */
/** opts.interactive: setiap ruang dibungkus <g data-p> agar bisa diklik di Mode editor. */
export function renderPoster(L, opts = {}) {
  const IA = !!opts.interactive;
  const o = [];
  const t = (x, y, s, size = 28, fill = '#182433', weight = 700, anchor = 'middle', fam = 'Inter', extra = '') =>
    o.push(`<text x="${x}" y="${y}" font-family="${fam}, Arial, sans-serif" font-size="${size}" font-weight="${weight}" fill="${fill}" text-anchor="${anchor}" ${extra}>${esc(s)}</text>`);
  const person = (x, y, c) => o.push(`<g transform="translate(${x},${y}) scale(0.9)" fill="none" stroke="${c}" stroke-width="2.4" stroke-linecap="round"><circle cx="9" cy="6" r="4.2"/><path d="M2 20c0-4 3.2-6.5 7-6.5s7 2.5 7 6.5"/></g>`);
  const room = (x, y, w, h, r, path) => {
    if (IA) { o.push(`<g class="pd-room" data-p="${path}" data-box="${x},${y},${w},${h}">`); room0(x, y, w, h, r); o.push(`<rect x="${x}" y="${y}" width="${w}" height="${h}" rx="14" fill="transparent" stroke="none"/></g>`); }
    else room0(x, y, w, h, r);
  };
  const room0 = (x, y, w, h, r) => {
    const k = KINDS[r.kind] ? r.kind : 'fas';
    if (k === 'spasi') { if (IA) o.push(`<rect x="${x}" y="${y}" width="${w}" height="${h}" rx="10" fill="none" stroke="#CBD5E1" stroke-width="1.5" stroke-dasharray="3 7"/>`); return; }
    if (k === 'tangga') { o.push(`<rect x="${x}" y="${y}" width="${w}" height="${h}" rx="10" fill="url(#stairs)" stroke="#1E293B" stroke-width="2"/>`); t(x + w / 2, y + h / 2 + 7, '▲', 18, '#E2E8F0'); return; }
    if (k === 'wc') { o.push(`<rect x="${x}" y="${y}" width="${w}" height="${h}" rx="10" fill="url(#tiles)" stroke="#94A3B8" stroke-width="2"/>`); t(x + w / 2, y + h / 2 + 8, r.name || 'WC', w < 60 ? 16 : 22, '#475569', 800); return; }
    if (k === 'kosong') { o.push(`<rect x="${x}" y="${y}" width="${w}" height="${h}" rx="10" fill="#F8FAFC" stroke="#CBD5E1" stroke-width="2" stroke-dasharray="6 6"/>`); if (r.name) t(x + w / 2, y + h / 2 + 8, r.name, 20, '#94A3B8', 700); return; }
    const [, fill, stroke, tc] = KINDS[k];
    if (k === 'aula') { o.push(`<rect x="${x}" y="${y}" width="${w}" height="${h}" rx="20" fill="${fill}" stroke="${stroke}" stroke-width="2.5" stroke-dasharray="10 8"/>`); }
    else {
      o.push(`<g filter="url(#sh)"><rect x="${x}" y="${y}" width="${w}" height="${h}" rx="16" fill="${fill}" stroke="${stroke}" stroke-width="2.5"/></g>`);
      if (SOLID.includes(k)) o.push(`<rect x="${x}" y="${y}" width="${w}" height="${h}" rx="16" fill="url(#gl)" opacity=".55"/>`);
    }
    const num = String(r.num ?? '').trim();
    if (num && w >= 56 && h >= 60) {
      o.push(`<circle cx="${x + 24}" cy="${y + 24}" r="15" fill="#FFFFFF" fill-opacity="${k === 'xi' ? 0.85 : 1}" stroke="${stroke}" stroke-width="1.5"/>`);
      t(x + 24, y + 30, num.slice(0, 3), num.length > 2 ? 12 : 16, k === 'xi' ? '#7A5A00' : stroke, 800);
    }
    const lines = String(r.name || '').split(/\n|\\n|\|/).map((s) => s.trim()).filter(Boolean).slice(0, 3);
    const longest = Math.max(1, ...lines.map((s) => s.length));
    const cap = +r.cap > 0 && h >= 90 ? +r.cap : 0;
    let fs = Math.min(k === 'aula' ? 38 : h < 90 ? 22 : 30, Math.max(13, (w - 18) / (longest * 0.64)));
    if (lines.length > 1) fs = Math.min(fs, (h - (cap ? 40 : 14)) / (lines.length * 1.15));
    const cy = y + h / 2 - ((lines.length - 1) * fs * 1.1) / 2 + fs * 0.35 - (cap ? 10 : 0);
    lines.forEach((ln, i) => t(x + w / 2, cy + i * fs * 1.1, ln, Math.round(fs), tc, 800, 'middle', k === 'aula' ? 'Montserrat' : 'Inter'));
    if (cap) {
      const tw = 26 + String(cap).length * 12;
      person(x + w / 2 - tw / 2, y + h - 38, tc);
      t(x + w / 2 - tw / 2 + 22, y + h - 21, cap, 19, tc, 700, 'start');
    }
  };

  // ── Tata letak kartu gedung (baris kartu, lebar penuh/½/⅓/⅔) ──
  const SPAN = { penuh: 1, setengah: 1 / 2, sepertiga: 1 / 3, duapertiga: 2 / 3 };
  const M = 60, GAP = 40, HEAD = 250;
  const inner = W - 2 * M;
  const measure = (b) => {
    const labelCol = b.sections.some((s) => String(s.label || '').trim()) ? 130 : 0;
    let h = 110;
    b.sections.forEach((s, si) => { h += s.rows.reduce((a, r) => a + (ROW_H[r.size] || ROW_H.besar), 0) + Math.max(0, s.rows.length - 1) * 12 + (si ? 30 : 0); });
    return { labelCol, h: h + 40 };
  };
  const rowsOfCards = []; let cur = [], used = 0;
  (L.buildings || []).forEach((b) => {
    const sp = SPAN[b.span] || 1;
    if (used + sp > 1.001 && cur.length) { rowsOfCards.push(cur); cur = []; used = 0; }
    cur.push({ b, sp }); used += sp;
  });
  // Ringkasan otomatis: mengisi sisa baris terakhir, atau baris baru.
  const stat = summary(L);
  if (cur.length && 1 - used >= 0.32) cur.push({ summary: true, sp: 1 - used });
  if (cur.length) rowsOfCards.push(cur);
  if (!rowsOfCards.length || !rowsOfCards[rowsOfCards.length - 1].some((c) => c.summary)) rowsOfCards.push([{ summary: true, sp: 1, wide: true }]);

  let y = HEAD + 50;
  const placed = [];
  rowsOfCards.forEach((row) => {
    const n = row.length; const free = inner - GAP * (n - 1);
    const total = row.reduce((a, c) => a + c.sp, 0);
    let x = M; let rowH = 0;
    row.forEach((c) => {
      const w = Math.round(total >= 0.999 ? (free * c.sp) / total : free * c.sp);
      const m = c.summary ? { h: c.wide || c.sp * inner > 1000 ? 210 : 420 } : measure(c.b);
      placed.push({ ...c, x, y, w, m }); rowH = Math.max(rowH, m.h); x += w + GAP;
    });
    placed.filter((p) => p.y === y).forEach((p) => (p.h = rowH));
    y += rowH + GAP;
  });
  const H = y + 40;

  o.push(`<svg xmlns="http://www.w3.org/2000/svg" width="${W}" height="${H}" viewBox="0 0 ${W} ${H}">`);
  o.push(`<defs>${FONT_STYLE}
<linearGradient id="bg" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="#F3F7FB"/><stop offset="1" stop-color="#E8EEF5"/></linearGradient>
<linearGradient id="gl" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="#FFFFFF" stop-opacity=".35"/><stop offset=".45" stop-color="#FFFFFF" stop-opacity="0"/></linearGradient>
<linearGradient id="hd" x1="0" y1="0" x2="1" y2="0"><stop offset="0" stop-color="#0B3A66"/><stop offset="1" stop-color="#066FD1"/></linearGradient>
<pattern id="stairs" width="14" height="14" patternUnits="userSpaceOnUse"><rect width="14" height="14" fill="#334155"/><rect width="14" height="5" fill="#475569"/></pattern>
<pattern id="tiles" width="16" height="16" patternUnits="userSpaceOnUse"><rect width="16" height="16" fill="#E0F2FE"/><path d="M16 0H0V16" fill="none" stroke="#BAE6FD" stroke-width="2"/></pattern>
<filter id="sh" x="-10%" y="-10%" width="120%" height="130%"><feDropShadow dx="0" dy="3" stdDeviation="3" flood-color="#0F172A" flood-opacity=".18"/></filter>
<filter id="shc" x="-5%" y="-5%" width="110%" height="110%"><feDropShadow dx="0" dy="6" stdDeviation="10" flood-color="#0F172A" flood-opacity=".10"/></filter></defs>`);
  o.push(`<rect width="${W}" height="${H}" fill="url(#bg)"/><rect width="${W}" height="${HEAD}" fill="url(#hd)"/>`);
  if (IA) o.push('<g class="pd-room pd-meta" data-meta="1"><rect x="40" y="30" width="1380" height="210" rx="20" fill="transparent"/>');
  t(80, 118, L.title || 'DENAH SEKOLAH', 74, '#FFFFFF', 800, 'start', 'Montserrat', 'letter-spacing="3"');
  t(82, 178, L.subtitle || '', 34, '#CFE3FA', 600, 'start');
  t(82, 222, L.note || '', 22, '#9CC3EE', 600, 'start');
  if (IA) o.push('</g>');
  // Legenda: hanya jenis yang dipakai.
  const usedK = new Set(); (L.buildings || []).forEach((b) => b.sections.forEach((s) => s.rows.forEach((r) => r.rooms.forEach((x) => usedK.add(x.kind)))));
  const leg = ['x', 'xi', 'xii', 'lab', 'kantor', 'pus', 'fas', 'aula', 'tangga', 'wc'].filter((k) => usedK.has(k));
  const lx = 1500, ly = 70;
  const LS = 46;
  o.push(`<rect x="${lx - 30}" y="${ly - 44}" width="880" height="${Math.min(4, Math.ceil((leg.length + 1) / 3)) * LS + 26}" rx="22" fill="#FFFFFF" fill-opacity=".12" stroke="#FFFFFF" stroke-opacity=".25"/>`);
  [...leg, 'cap'].slice(0, 12).forEach((k, i) => {
    const cx = lx + (i % 3) * 285, cy = ly + Math.floor(i / 3) * LS;
    if (k === 'cap') { person(cx + 8, cy - 22, '#FFFFFF'); t(cx + 54, cy, 'Jumlah siswa', 22, '#FFFFFF', 700, 'start'); return; }
    const sw = k === 'tangga' ? 'url(#stairs)' : k === 'wc' ? 'url(#tiles)' : KINDS[k][1];
    o.push(`<rect x="${cx}" y="${cy - 22}" width="40" height="28" rx="7" fill="${sw}" stroke="${KINDS[k][2] || 'none'}" stroke-width="2"/>`);
    t(cx + 54, cy, KINDS[k][0], 22, '#FFFFFF', 700, 'start');
  });

  placed.forEach((p, pi) => {
    if (p.summary) return drawSummary(p);
    const { b, x, y: py, w, h, m } = p;
    const bIdx = (L.buildings || []).indexOf(b);
    o.push(`<rect x="${x}" y="${py}" width="${w}" height="${h}" rx="28" fill="#FFFFFF" stroke="#DCE1E7" stroke-width="2" filter="url(#shc)"/>`);
    o.push(`<rect x="${x}" y="${py}" width="${w}" height="78" rx="28" fill="#182433"/><rect x="${x}" y="${py + 50}" width="${w}" height="28" fill="#182433"/>`);
    if (IA) o.push(`<g class="pd-room pd-bhead" data-b="${(L.buildings || []).indexOf(b)}"><rect x="${x}" y="${py}" width="${w}" height="78" rx="28" fill="transparent"/>`);
    t(x + 32, py + 52, b.name || 'GEDUNG', 34, '#FFFFFF', 800, 'start', 'Montserrat');
    if (IA) o.push('</g>');
    if (b.note && w > 500) t(x + w - 32, py + 50, b.note, 20, '#9FB0C6', 600, 'end');
    let yy = py + 110;
    const x0 = x + 30, rx0 = x0 + m.labelCol, avail = w - 60 - m.labelCol;
    b.sections.forEach((s, si) => {
      if (si) yy += 30;
      const sh = s.rows.reduce((a, r) => a + (ROW_H[r.size] || ROW_H.besar), 0) + Math.max(0, s.rows.length - 1) * 12;
      if (m.labelCol && String(s.label || '').trim()) {
        o.push(`<rect x="${x0}" y="${yy}" width="112" height="${sh}" rx="14" fill="#EEF2F7"/>`);
        const lab = String(s.label).trim();
        const short = lab.length <= 3;
        if (short) { t(x0 + 56, yy + sh / 2 - 6, 'LANTAI', 15, '#66758C', 800); t(x0 + 56, yy + sh / 2 + 28, lab, 40, '#182433', 800, 'middle', 'Montserrat'); }
        else lab.split(/\s+/).slice(0, 3).forEach((wd, i, a) => t(x0 + 56, yy + sh / 2 + (i - (a.length - 1) / 2) * 24 + 6, wd.toUpperCase(), 17, '#182433', 800));
      }
      s.rows.forEach((r, ri) => {
        const rh = ROW_H[r.size] || ROW_H.besar;
        const rooms = r.rooms.filter(Boolean);
        const tw = rooms.reduce((a, q) => a + Math.max(0.2, +q.w || 1), 0) || 1;
        const gaps = Math.max(0, rooms.length - 1) * 10;
        const unit = (avail - gaps) / tw;
        let cx = rx0;
        rooms.forEach((q, qi) => { const qw = Math.max(20, unit * Math.max(0.2, +q.w || 1)); room(cx, yy, qw, rh, q, `${bIdx}.${si}.${ri}.${r.rooms.indexOf(q)}`); cx += qw + 10; });
        yy += rh + (ri < s.rows.length - 1 ? 12 : 0);
      });
    });
  });
  function drawSummary(p) {
    o.push(`<rect x="${p.x}" y="${p.y}" width="${p.w}" height="${p.h}" rx="28" fill="#182433"/>`);
    t(p.x + 30, p.y + 60, 'RINGKASAN', 24, '#9FB0C6', 800, 'start');
    const items = [[stat.kelas, 'rombel / ruang kelas'], [`${stat.x} · ${stat.xi} · ${stat.xii}`, 'kelas X · XI · XII'], [stat.lab, 'laboratorium'], [stat.gedung, 'gedung']];
    const horiz = p.w > 1000;
    items.forEach(([v, l], i) => {
      const xx = horiz ? p.x + 30 + i * (p.w - 60) / 4 : p.x + 30, yy = horiz ? p.y + 130 : p.y + 130 + i * 100;
      t(xx, yy, v, String(v).length < 6 ? 44 : 32, '#FFFFFF', 800, 'start', 'Montserrat');
      t(xx, yy + 32, l, 20, '#9FB0C6', 600, 'start');
    });
  }
  t(W / 2, H - 22, 'MyPresence · Informasi Sarpras · Angka di lingkaran = nomor ruang · ikon orang = jumlah siswa', 22, '#66758C', 600);
  o.push('</svg>');
  return { svg: o.join('\n'), width: W, height: H };
}

export function summary(L) {
  const s = { x: 0, xi: 0, xii: 0, lab: 0, kelas: 0, gedung: (L.buildings || []).length, siswa: 0 };
  (L.buildings || []).forEach((b) => b.sections.forEach((se) => se.rows.forEach((r) => r.rooms.forEach((q) => {
    if (q.kind in s && q.kind !== 'kelas') s[q.kind]++;
    if (['x', 'xi', 'xii'].includes(q.kind)) { s.kelas++; s.siswa += +q.cap || 0; }
  }))));
  return s;
}

// Font disematkan ke SVG agar huruf tetap Inter/Montserrat saat dijadikan JPG.
let FONT_STYLE = '';
export async function loadFonts() {
  if (FONT_STYLE) return;
  const get = async (u) => { const b = await (await fetch(u)).blob(); return await new Promise((r) => { const f = new FileReader(); f.onload = () => r(f.result); f.readAsDataURL(b); }); };
  try {
    const [i6, i7, m8] = await Promise.all(['/fonts/inter-latin-600.woff', '/fonts/inter-latin-700.woff', '/fonts/montserrat-latin-800.woff'].map(get));
    FONT_STYLE = `<style>@font-face{font-family:Inter;src:url(${i6}) format('woff');font-weight:400 600}@font-face{font-family:Inter;src:url(${i7}) format('woff');font-weight:700 900}@font-face{font-family:Montserrat;src:url(${m8}) format('woff');font-weight:700 900}</style>`;
  } catch (_) { FONT_STYLE = ''; }
}

/** SVG → JPG (Blob) lewat canvas. */
export async function toJpeg(svg, w, h, quality = 0.9) {
  const url = URL.createObjectURL(new Blob([svg], { type: 'image/svg+xml' }));
  try {
    const img = await new Promise((res, rej) => { const i = new Image(); i.onload = () => res(i); i.onerror = () => rej(new Error('Gagal menggambar denah.')); i.src = url; });
    if (img.decode) await img.decode().catch(() => {});
    const c = document.createElement('canvas'); c.width = w; c.height = h;
    const g = c.getContext('2d'); g.fillStyle = '#fff'; g.fillRect(0, 0, w, h); g.drawImage(img, 0, 0, w, h);
    for (const q of [quality, 0.8, 0.7]) { const b = await new Promise((r) => c.toBlob(r, 'image/jpeg', q)); if (b && b.size < 1.9 * 1048576) return b; }
    throw new Error('Denah terlalu besar untuk diunggah. Kurangi jumlah gedung/ruang.');
  } finally { URL.revokeObjectURL(url); }
}

// ════════════════════════ PENYUSUN DENAH (editor koordinator Sarpras) ════════════════════════
const KIND_OPTS = ['x', 'xi', 'xii', 'lab', 'kantor', 'pus', 'fas', 'aula', 'tangga', 'wc', 'kosong', 'spasi'];
const SPANS = [['penuh', 'Lebar penuh'], ['duapertiga', '⅔ lebar'], ['setengah', '½ lebar'], ['sepertiga', '⅓ lebar']];
const nr = (kind = 'x') => ({ num: '', name: '', kind, cap: 0, w: 1 });
const nrow = () => ({ size: 'besar', rooms: [nr()] });
const nsec = (label = '1') => ({ label, rows: [nrow()] });
const STARTER = (school) => ({ title: 'DENAH SEKOLAH', subtitle: school || '', note: 'Disusun Bidang Sarpras', buildings: [{ name: 'GEDUNG A', note: '', span: 'penuh', sections: [nsec('1')] }] });
const move = (arr, i, d) => { const j = i + d; if (j < 0 || j >= arr.length) return; [arr[i], arr[j]] = [arr[j], arr[i]]; };

/** Pasang editor di `box`. onDone() dipanggil setelah terbit/tutup. */
export async function mountBuilder(box, { onDone } = {}) {
  const { api, toast, esc: e2 } = window.MP;
  const ti = globalThis.ti;
  const D = await api('school-map/layout');
  const L = D.layout ? JSON.parse(JSON.stringify(D.layout)) : STARTER(D.school);
  let dirty = false, pv = 0, mode = 'visual', sel = null; // sel: 'b.s.r.q' | {b} | 'meta'
  const showName = (n) => String(n || '').replace(/\\n|\n/g, ' | ');
  const btn = (act, icon, label, extra = '') => `<button type="button" class="pd-ib" data-a="${act}" ${extra} title="${label}" aria-label="${label}">${icon}</button>`;
  const roomRow = (r, path) => `<tr data-p="${path}">
      <td><input class="pd-num" value="${e2(r.num || '')}" maxlength="6" data-f="num" aria-label="Nomor ruang"></td>
      <td><input value="${e2(showName(r.name))}" maxlength="40" data-f="name" placeholder="${['tangga', 'wc', 'kosong', 'spasi'].includes(r.kind) ? '(opsional)' : 'Nama ruang'}" aria-label="Nama ruang"></td>
      <td><select data-f="kind" aria-label="Jenis">${KIND_OPTS.map((k) => `<option value="${k}" ${k === r.kind ? 'selected' : ''}>${KINDS[k][0]}</option>`).join('')}</select></td>
      <td><input type="number" min="0" max="999" value="${+r.cap || ''}" data-f="cap" class="pd-num" aria-label="Jumlah siswa"></td>
      <td><input type="number" min="0.2" max="6" step="0.1" value="${+r.w || 1}" data-f="w" class="pd-num" aria-label="Lebar relatif"></td>
      <td class="pd-acts">${btn('rup', '↑', 'Geser kiri')}${btn('rdn', '↓', 'Geser kanan')}${btn('rdup', '⧉', 'Duplikat')}${btn('rdel', '✕', 'Hapus ruang')}</td></tr>`;
  const headHtml = () => `<div class="pd-head"><div><b>${ti('edit')} Penyusun denah</b><p class="mu" style="margin:2px 0 0">Ubah gedung, lantai, nomor & nama ruang, lalu terbitkan. Admin, guru, dan siswa melihat hasilnya sebagai gambar JPG.</p></div>
        <span class="row" style="flex:none"><button type="button" class="btn o nf" data-a="close">Tutup</button><button type="button" class="btn o nf" data-a="save">Simpan data</button><button type="button" class="btn nf" data-a="publish">${ti('check')} Simpan & terbitkan JPG</button></span></div>
      <div class="pd-mode" role="tablist"><button type="button" data-a="mvis" class="${mode === 'visual' ? 'on' : ''}">${ti('edit')} Mode editor (klik ruang)</button><button type="button" data-a="mlist" class="${mode === 'list' ? 'on' : ''}">${ti('menu-2')} Mode daftar</button></div>`;
  // ── Mode editor visual: klik ruang di denah → ubah di panel ──
  const selRoom = () => (typeof sel === 'string' && sel !== 'meta' ? at(sel) : null);
  const inspector = () => {
    const x = selRoom();
    if (sel === 'meta') return `<b>Judul denah</b><label>Judul</label><input data-m="title" value="${e2(L.title || '')}" maxlength="60"><label>Subjudul</label><input data-m="subtitle" value="${e2(L.subtitle || '')}" maxlength="120"><label>Catatan kecil</label><input data-m="note" value="${e2(L.note || '')}" maxlength="140">`;
    if (sel && typeof sel === 'object') {
      const B = L.buildings[sel.b]; if (!B) return '';
      return `<b>${e2(B.name)}</b><div class="pd-brow" data-p="${sel.b}"><label>Nama gedung</label><input data-bf="name" value="${e2(B.name)}" maxlength="40"><label>Keterangan</label><input data-bf="note" value="${e2(B.note || '')}" maxlength="60" placeholder="mis. 3 lantai · ruang 1–23">
        <label>Lebar kartu</label><select data-bf="span">${SPANS.map(([v, l]) => `<option value="${v}" ${v === B.span ? 'selected' : ''}>${l}</option>`).join('')}</select></div>
        <p class="mu" style="font-size:12px">Menambah lantai, baris, atau menghapus gedung: pakai <b>Mode daftar</b>.</p>`;
    }
    if (!x?.Q) return `<div class="pd-hint">${ti('info-circle')}<b>Klik ruang di denah</b><span class="mu">untuk mengganti nomor, nama, jenis, atau jumlah siswa. Klik judul gedung untuk mengganti nama gedung, atau klik judul besar di atas untuk mengganti judul denah.</span></div>`;
    const B = x.B, S = x.S, Q = x.Q;
    return `<div class="pd-where mu">${e2(B.name)} · ${String(S.label || '').trim() ? 'Lantai ' + e2(S.label) + ' · ' : ''}baris ${x.r + 1} · ruang ke-${x.q + 1}</div>
      <div data-qp="${sel}"><div class="row"><div style="max-width:110px"><label>Nomor</label><input data-qf="num" value="${e2(Q.num || '')}" maxlength="6"></div>
      <div><label>Jenis</label><select data-qf="kind">${KIND_OPTS.map((k) => `<option value="${k}" ${k === Q.kind ? 'selected' : ''}>${KINDS[k][0]}</option>`).join('')}</select></div></div>
      <label>Nama ruang</label><input data-qf="name" value="${e2(showName(Q.name))}" maxlength="40" placeholder="Nama ruang" id="pdn">
      <p class="mu" style="font-size:12px;margin:4px 0 0">Pakai <b>|</b> untuk ganti baris, mis. <code>LAB | KULINER</code>.</p>
      <div class="row"><div><label>Jumlah siswa</label><input type="number" min="0" max="999" data-qf="cap" value="${+Q.cap || ''}"></div><div><label>Lebar</label><input type="number" min="0.2" max="6" step="0.1" data-qf="w" value="${+Q.w || 1}"></div></div></div>
      <div class="pd-vacts"><button type="button" class="btn o sm nf" data-a="vleft">← Geser</button><button type="button" class="btn o sm nf" data-a="vright">Geser →</button>
        <button type="button" class="btn o sm nf" data-a="vadd">${ti('plus')} Ruang di kanan</button><button type="button" class="btn o sm nf" data-a="vdup">Duplikat</button><button type="button" class="btn o sm nf pd-del" data-a="vdel">${ti('trash')} Hapus</button></div>`;
  };
  const canvas = () => {
    const c = box.querySelector('.pd-canvas'); if (!c) return;
    const { svg } = renderPoster(L, { interactive: true });
    c.innerHTML = svg.replace(/<svg ([^>]*?)width="\d+" height="\d+"/, '<svg $1');
    const key = typeof sel === 'string' && sel !== 'meta' ? `[data-p="${sel}"]` : sel === 'meta' ? '[data-meta]' : sel ? `[data-b="${sel.b}"]` : null;
    const g = key && c.querySelector('g' + key);
    if (g) g.classList.add('sel');
  };
  const drawVisual = () => {
    box.innerHTML = `<div class="pd">${headHtml()}<div class="pd-vis"><div class="pd-canvas card" title="Klik ruang untuk mengubah"></div><aside class="pd-insp card">${inspector()}</aside></div></div>`;
    canvas();
    box.querySelector('.pd-canvas').onclick = (ev) => {
      const g = ev.target.closest('g.pd-room'); if (!g) return;
      sel = g.dataset.meta ? 'meta' : g.dataset.b !== undefined && !g.dataset.p ? { b: +g.dataset.b } : g.dataset.p;
      box.querySelector('.pd-insp').innerHTML = inspector(); canvas();
      const n = box.querySelector('#pdn'); if (n) { n.focus(); n.select(); }
      if (innerWidth < 1000) box.querySelector('.pd-insp').scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    };
  };
  const draw = () => {
    if (mode === 'visual') return drawVisual();
    box.innerHTML = `<div class="pd">${headHtml()}
      <div class="pd-grid"><div class="pd-ed">
        <div class="card pd-meta"><div class="row"><div style="flex:1"><label>Judul</label><input data-m="title" value="${e2(L.title || '')}" maxlength="60"></div>
          <div style="flex:2"><label>Subjudul</label><input data-m="subtitle" value="${e2(L.subtitle || '')}" maxlength="120"></div></div>
          <label>Catatan kecil</label><input data-m="note" value="${e2(L.note || '')}" maxlength="140">
          <p class="mu" style="margin:8px 0 0;font-size:12px">Tips: <b>Lebar</b> = lebar relatif ruang dalam satu baris (1 = normal, 2 = dua kali). Pakai tanda <b>|</b> untuk ganti baris pada nama, mis. <code>LAB | KULINER</code>. Jenis <b>Jarak</b> = ruang kosong tak terlihat untuk mengatur posisi.</p></div>
        ${L.buildings.map((b, bi) => `<details class="card pd-b" open data-b="${bi}"><summary><span class="pd-bt">${e2(b.name || 'Gedung')}</span><span class="mu">${b.sections.length} lantai/bagian · ${b.sections.reduce((a, s) => a + s.rows.reduce((x, r) => x + r.rooms.filter((q) => !['spasi', 'kosong', 'tangga', 'wc'].includes(q.kind)).length, 0), 0)} ruang</span></summary>
          <div class="row pd-brow" data-p="${bi}"><div style="flex:2"><label>Nama gedung</label><input data-bf="name" value="${e2(b.name)}" maxlength="40"></div>
            <div style="flex:2"><label>Keterangan</label><input data-bf="note" value="${e2(b.note || '')}" maxlength="60" placeholder="mis. 3 lantai · ruang 1–23"></div>
            <div><label>Lebar kartu</label><select data-bf="span">${SPANS.map(([v, l]) => `<option value="${v}" ${v === b.span ? 'selected' : ''}>${l}</option>`).join('')}</select></div>
            <div class="pd-acts" style="align-self:flex-end">${btn('bup', '↑', 'Naikkan gedung')}${btn('bdn', '↓', 'Turunkan gedung')}${btn('bdel', '✕', 'Hapus gedung')}</div></div>
          ${b.sections.map((s, si) => `<div class="pd-s" data-p="${bi}.${si}"><div class="row pd-srow"><div style="max-width:200px"><label>Lantai / bagian</label><input data-sf="label" value="${e2(s.label || '')}" maxlength="20" placeholder="mis. 3, atau kosong"></div>
              <div class="pd-acts" style="align-self:flex-end">${btn('sup', '↑', 'Naikkan')}${btn('sdn', '↓', 'Turunkan')}${btn('sdel', '✕', 'Hapus lantai/bagian')}</div></div>
            ${s.rows.map((r, ri) => `<div class="pd-r" data-p="${bi}.${si}.${ri}"><div class="pd-rh"><span class="mu">Baris ${ri + 1}</span>
                <select data-rf="size" aria-label="Tinggi baris"><option value="besar" ${r.size !== 'kecil' ? 'selected' : ''}>Ruang besar</option><option value="kecil" ${r.size === 'kecil' ? 'selected' : ''}>Ruang kecil</option></select>
                <span class="pd-acts">${btn('wup', '↑', 'Naikkan baris')}${btn('wdn', '↓', 'Turunkan baris')}${btn('wdel', '✕', 'Hapus baris')}</span></div>
              <div class="sc"><table class="pd-t"><tr><th>No.</th><th>Nama ruang</th><th>Jenis</th><th>Siswa</th><th>Lebar</th><th></th></tr>
              ${r.rooms.map((q, qi) => roomRow(q, `${bi}.${si}.${ri}.${qi}`)).join('')}</table></div>
              <button type="button" class="btn o sm nf" data-a="radd">${ti('plus')} Ruang</button></div>`).join('')}
            <button type="button" class="btn o sm nf" data-a="wadd">${ti('plus')} Baris</button></div>`).join('')}
          <button type="button" class="btn o sm nf" data-a="sadd" style="margin-top:10px">${ti('plus')} Lantai / bagian</button></details>`).join('')}
        <button type="button" class="btn o nf" data-a="badd">${ti('plus')} Tambah gedung</button>
      </div>
      <div class="pd-pv card"><b>Pratinjau</b><span class="mu" style="font-size:12px"> · diperbarui otomatis</span><div class="pd-img"><img alt="Pratinjau denah"></div></div></div></div>`;
    refresh();
  };
  const at = (path) => { const [b, s, r, q] = String(path).split('.').map(Number); const B = L.buildings[b], S = B?.sections[s], R = S?.rows[r]; return { B, S, R, Q: R?.rooms[q], b, s, r, q }; };
  const refresh = () => {
    if (mode === 'visual') { clearTimeout(pv); pv = setTimeout(canvas, 120); return; }
    clearTimeout(pv); pv = setTimeout(() => {
      const img = box.querySelector('.pd-img img'); if (!img) return;
      const { svg } = renderPoster(L);
      const old = img.src; img.src = URL.createObjectURL(new Blob([svg], { type: 'image/svg+xml' })); if (old.startsWith('blob:')) URL.revokeObjectURL(old);
    }, 350);
  };
  const touch = () => { dirty = true; refresh(); };
  box.addEventListener('input', (ev) => {
    const t = ev.target;
    if (t.dataset.m) L[t.dataset.m] = t.value;
    else if (t.dataset.bf) L.buildings[+t.closest('[data-p]').dataset.p][t.dataset.bf] = t.value;
    else if (t.dataset.sf) at(t.closest('[data-p]').dataset.p).S[t.dataset.sf] = t.value;
    else if (t.dataset.rf) at(t.closest('[data-p]').dataset.p).R[t.dataset.rf] = t.value;
    else if (t.dataset.f || t.dataset.qf) { const f = t.dataset.f || t.dataset.qf; const { Q } = at(t.dataset.qf ? t.closest('[data-qp]').dataset.qp : t.closest('tr').dataset.p); Q[f] = f === 'cap' ? Math.max(0, parseInt(t.value, 10) || 0) : f === 'w' ? Math.min(6, Math.max(0.2, +t.value || 1)) : t.value; }
    else return;
    if (t.dataset.bf === 'name') { const sm = t.closest('details')?.querySelector('.pd-bt'); if (sm) sm.textContent = t.value || 'Gedung'; }
    touch();
  });
  box.addEventListener('change', (ev) => { if (ev.target.tagName === 'SELECT') ev.target.dispatchEvent(new Event('input', { bubbles: true })); });
  box.addEventListener('click', async (ev) => {
    const b = ev.target.closest('[data-a]'); if (!b) return;
    const a = b.dataset.a; const p = b.closest('[data-p]')?.dataset.p;
    const x = p !== undefined ? at(p) : {};
    const open = [...box.querySelectorAll('details.pd-b')].map((d) => d.open);
    const S = () => b.closest('.pd-s') ? at(b.closest('.pd-s').dataset.p) : null;
    const Bi = () => +b.closest('details.pd-b').dataset.b;
    switch (a) {
      case 'close': if (dirty && !confirm('Perubahan belum disimpan. Tutup penyusun denah?')) return; onDone?.(); return;
      case 'mvis': mode = 'visual'; draw(); return;
      case 'mlist': mode = 'list'; draw(); return;
      case 'vleft': case 'vright': case 'vdup': case 'vadd': case 'vdel': {
        const y = selRoom(); if (!y?.Q) return;
        const rooms = y.R.rooms;
        if (a === 'vleft' && y.q > 0) { move(rooms, y.q, -1); sel = `${y.b}.${y.s}.${y.r}.${y.q - 1}`; }
        if (a === 'vright' && y.q < rooms.length - 1) { move(rooms, y.q, 1); sel = `${y.b}.${y.s}.${y.r}.${y.q + 1}`; }
        if (a === 'vdup') { rooms.splice(y.q + 1, 0, { ...y.Q }); sel = `${y.b}.${y.s}.${y.r}.${y.q + 1}`; }
        if (a === 'vadd') { rooms.splice(y.q + 1, 0, nr(y.Q.kind)); sel = `${y.b}.${y.s}.${y.r}.${y.q + 1}`; }
        if (a === 'vdel') { if (rooms.length === 1) return toast('Baris minimal berisi 1 ruang. Hapus baris lewat Mode daftar.', true); rooms.splice(y.q, 1); sel = null; }
        dirty = true; box.querySelector('.pd-insp').innerHTML = inspector(); canvas(); return;
      }
      case 'save': case 'publish': return save(a === 'publish', b);
      case 'badd': L.buildings.push({ name: 'GEDUNG ' + String.fromCharCode(65 + L.buildings.length), note: '', span: 'setengah', sections: [nsec('1')] }); break;
      case 'bup': move(L.buildings, x.b, -1); break; case 'bdn': move(L.buildings, x.b, 1); break;
      case 'bdel': if (!confirm(`Hapus ${x.B.name} beserta semua ruangnya?`)) return; L.buildings.splice(x.b, 1); break;
      case 'sadd': { const B = L.buildings[Bi()]; B.sections.push(nsec(String(B.sections.length + 1))); break; }
      case 'sup': move(x.B.sections, x.s, -1); break; case 'sdn': move(x.B.sections, x.s, 1); break;
      case 'sdel': if (x.B.sections.length === 1) return toast('Gedung minimal punya 1 lantai/bagian.', true); if (!confirm('Hapus lantai/bagian ini beserta ruangnya?')) return; x.B.sections.splice(x.s, 1); break;
      case 'wadd': S().S.rows.push(nrow()); break;
      case 'wup': move(x.S.rows, x.r, -1); break; case 'wdn': move(x.S.rows, x.r, 1); break;
      case 'wdel': if (x.S.rows.length === 1) return toast('Lantai/bagian minimal punya 1 baris.', true); x.S.rows.splice(x.r, 1); break;
      case 'radd': { const R = at(b.closest('.pd-r').dataset.p).R; R.rooms.push(nr(R.rooms[R.rooms.length - 1]?.kind || 'x')); break; }
      case 'rup': move(x.R.rooms, x.q, -1); break; case 'rdn': move(x.R.rooms, x.q, 1); break;
      case 'rdup': x.R.rooms.splice(x.q + 1, 0, { ...x.Q }); break;
      case 'rdel': x.R.rooms.splice(x.q, 1); break;
      default: return;
    }
    dirty = true; const y = scrollY; draw(); [...box.querySelectorAll('details.pd-b')].forEach((d, i) => { if (open[i] === false) d.open = false; }); scrollTo(0, y);
  });
  const clean = () => ({ ...L, buildings: L.buildings.map((b) => ({ ...b, sections: b.sections.map((s) => ({ ...s, rows: s.rows.map((r) => ({ ...r, rooms: r.rooms.map((q) => ({ num: String(q.num || '').trim(), name: String(q.name || '').trim(), kind: q.kind, cap: +q.cap || 0, w: +q.w || 1 })) })) })) })) });
  async function save(publish, b) {
    b.disabled = true;
    try {
      if (!L.buildings.length) throw new Error('Tambahkan minimal satu gedung.');
      await api('school-map/layout', { method: 'PUT', body: { layout: clean() } });
      if (!publish) { dirty = false; toast('Data denah tersimpan (belum diterbitkan).'); return; }
      toast('Menggambar denah…');
      await loadFonts();
      const r = renderPoster(L);
      const jpg = await toJpeg(r.svg, r.width, r.height);
      const fd = new FormData(); fd.append('file', jpg, 'denah.jpg');
      const res = await api('school-map/image', { method: 'POST', form: fd });
      dirty = false; toast(res.message); onDone?.();
    } catch (x) { toast(x.data?.errors ? Object.values(x.data.errors)[0][0] : x.message, true); }
    finally { if (b.isConnected) b.disabled = false; }
  }
  draw();
}
