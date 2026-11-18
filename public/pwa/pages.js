/* MyPresence PWA — halaman admin & guru. */
// Fungsi inti dibagikan lewat window.MP (bukan import) agar app.js berversi (?v=) tidak dimuat dua kali.
const { api, S, $, $$, esc, avatar, withAvatar, rich, nf, fd, dueText, pastDue, hdr, empty, err, toast, dialog, go, render, BOOT, todayStr } = window.MP;

const isAdmin = () => S.user.role === 'admin';
/** Agenda sekolah & briefing tidak ditampilkan lagi di portal mana pun; menu "Agenda"/"Informasi" = informasi per bidang. */
const agendaMerged = () => false;
const ST = { H: ['Hadir', 'ok'], S: ['Sakit', 'wr'], I: ['Izin', 'wr'], A: ['Alpa', 'er'] };
const pill = (s, extra = '') => s ? `<span class="pl ${ST[s][1]}">${ST[s][0]}</span>${extra}` : '<span class="pl mu">Belum scan</span>';
const table = (head, rows, emptyText = 'Belum ada data.') => `<div class="sc"><table><tr>${head.map((h) => `<th>${h}</th>`).join('')}</tr>${rows.length ? rows.map((r) => `<tr>${r.map((c) => `<td class="${String(c).length > 60 ? 'wrap' : ''}">${c}</td>`).join('')}</tr>`).join('') : `<tr><td colspan="${head.length}" class="mu">${emptyText}</td></tr>`}</table></div>`;

let classesCache = null;
async function classrooms(fresh = false) {
  if (!classesCache || fresh) classesCache = await api('classrooms');
  return classesCache;
}
/** Kelas guru (dari jadwal) didahulukan; admin melihat semua. */
async function classOptions(selected) {
  const all = await classrooms();
  let mine = [];
  if (S.user.role === 'teacher') {
    const d = await api('dashboard');
    mine = d.my_classrooms.map((c) => c.id);
  }
  const opt = (c) => `<option value="${c.id}" ${String(c.id) === String(selected) ? 'selected' : ''}>${esc(c.name)}</option>`;
  const a = all.filter((c) => mine.includes(c.id)), b = all.filter((c) => !mine.includes(c.id));
  return { html: mine.length ? `<optgroup label="Kelas yang saya ajar">${a.map(opt).join('')}</optgroup><optgroup label="Kelas lain">${b.map(opt).join('')}</optgroup>` : all.map(opt).join(''), first: (a[0] || b[0])?.id, all };
}

/** Bidang informasi: [kunci, label, ikon, keterangan, contoh judul]. Sama dengan Announcement::CATEGORIES. */
const INFO_CATS = [
  ['kepegawaian', 'Kepegawaian', 'id-badge-2', 'Guru & tenaga kependidikan: SK, cuti, kenaikan pangkat, PAK, presensi pegawai, berkas administrasi.', 'Mis. Batas pengumpulan berkas kenaikan pangkat'],
  ['kesiswaan', 'Kesiswaan', 'users', 'Kegiatan & tata tertib siswa, OSIS, ekstrakurikuler, prestasi.', 'Mis. Pendaftaran ekstrakurikuler dibuka'],
  ['kurikulum', 'Kurikulum', 'notebook', 'KBM, jadwal ujian, perubahan jadwal pelajaran, administrasi guru.', 'Mis. Jadwal PTS semester ganjil'],
  ['humas', 'Humas', 'share', 'Hubungan masyarakat: orang tua, komite, kerja sama, kunjungan industri.', 'Mis. Undangan rapat komite'],
  ['sarpras', 'Sarpras', 'building', 'Sarana & prasarana: ruang kelas, denah, perbaikan, peminjaman ruang. Bisa melampirkan JPG/PDF.', 'Mis. Denah ruang kelas tahun ajaran baru'],
];
/** Tombol B / I / S: membungkus teks yang diblok dengan *…*, _…_, ~…~ + pratinjau langsung. */
export function bindFormat(ta, prev, root) {
  if (!ta) return;
  const show = () => { if (!prev) return; const v = ta.value.trim(); prev.hidden = !/[*_~]/.test(v); prev.querySelector('.prose').innerHTML = rich(v); };
  $$('[data-fmt]', root).forEach((b) => (b.onclick = () => {
    const m = b.dataset.fmt; let [s0, s1] = [ta.selectionStart, ta.selectionEnd];
    let sel = ta.value.slice(s0, s1);
    while (sel.endsWith(' ') || sel.endsWith('\n')) { sel = sel.slice(0, -1); s1--; } // spasi di ujung tidak ikut dibungkus
    const ins = sel ? m + sel + m : m + 'teks' + m;
    ta.setRangeText(ins, s0, s1, 'end');
    if (!sel) ta.setSelectionRange(s0 + 1, s0 + 5);
    ta.focus(); show();
  }));
  ta.addEventListener('input', show);
}
/** Satu baris informasi di dashboard: bidang, judul, isi, lampiran. Dipakai juga oleh dashboard siswa. */
export function announcementItem(a, withAuthor = true) {
  const file = (a.file_url ? `<a class="ann-file" href="${esc(a.file_url)}" target="_blank" rel="noopener">${ti(a.file_type === 'PDF' ? 'file-type-pdf' : 'eye')} ${esc(a.file_name || 'Lihat lampiran')}</a> ` : '')
    + (a.category === 'sarpras' && BOOT.school.has_map ? `<a class="ann-file" href="#/denah">${ti('building')} Lihat denah sekolah</a>` : '');
  return `<div class="item"><div class="grow"><div class="t"><span class="chip ann-cat ann-${esc(a.category || 'umum')}">${esc(a.category_label || 'Umum')}</span> ${esc(a.title)}</div>
    <div class="prose mu" style="font-size:13px">${rich(a.body)}</div>${file}
    <div class="mu" style="font-size:11px;margin-top:4px">${fd(a.created_at)}${a.edited_at ? ' · diedit ' + fd(a.edited_at) : ''}${a.coordinator ? ' · Koordinator: ' + esc(a.coordinator) : ''}${withAuthor && a.author ? ' · ' + esc(a.author) : ''}${a.mine ? ' · <span class="chip nf" style="font-size:10px">Postingan saya</span>' : ''}</div></div></div>`;
}

/** Tombol Edit/Hapus untuk informasi milik sendiri atau bidang yang dikoordinasi. */
export function announcementActions(a) {
  return (a.can_edit ? `<button class="linkbtn" data-aedit="${a.id}" type="button">${ti('edit')} Edit</button>` : '') + (a.can_delete ? `<button class="linkbtn" data-adel="${a.id}" type="button">Hapus</button>` : '');
}

/** Pasang aksi Edit (dialog) & Hapus pada daftar informasi. */
export function bindAnnouncementActions(root, list, done) {
  $$('[data-aedit]', root).forEach((b) => (b.onclick = () => {
    const a = list.find((x) => x.id === +b.dataset.aedit);
    const d = dialog('Edit informasi ' + (a.category_label || ''), `<form id="aef">
      <label for="aeT">Judul</label><input id="aeT" name="title" required maxlength="120" value="${esc(a.title)}">
      <label for="aeA">Tujuan</label><select id="aeA" name="audience">${[['semua', 'Guru dan siswa'], ['guru', 'Guru saja'], ['siswa', 'Siswa saja']].map(([v, l]) => `<option value="${v}" ${a.audience === v ? 'selected' : ''}>${l}</option>`).join('')}</select>
      <div class="fmt-head"><label for="aeB">Isi informasi</label><span class="fmt-bar" role="toolbar" aria-label="Format teks">
        <button type="button" data-fmt="*" title="Tebal" aria-label="Tebal"><b>B</b></button><button type="button" data-fmt="_" title="Miring" aria-label="Miring"><i>I</i></button><button type="button" data-fmt="~" title="Coret" aria-label="Coret"><s>S</s></button></span></div>
      <textarea id="aeB" name="body" rows="7" required maxlength="3000">${esc(a.body)}</textarea>
      <div class="fmt-prev" id="aeP" hidden><span class="mu">Pratinjau:</span><div class="prose"></div></div>
      ${a.file_url ? '<p class="mu" style="margin:6px 0 0;font-size:12px">Lampiran tetap; untuk mengganti lampiran, hapus lalu kirim ulang informasinya.</p>' : ''}
      <div class="row" style="justify-content:flex-end;margin-top:12px"><button type="button" class="btn o nf" data-x>Batal</button><button class="btn nf">Simpan perubahan</button></div></form>`);
    bindFormat($('#aeB', d), $('#aeP', d), d);
    $('[data-x]', d).onclick = () => d.close();
    $('#aef', d).onsubmit = async (e) => {
      e.preventDefault(); const btn = $('button.btn:not(.o)', e.target); btn.disabled = true;
      try { const r = await api('announcements/' + a.id, { method: 'PUT', body: Object.fromEntries(new FormData(e.target)) }); toast(r.message); d.close(); done(); }
      catch (x) { toast(x.message, true); btn.disabled = false; }
    };
  }));
  $$('[data-adel]', root).forEach((b) => (b.onclick = async () => { if (!confirm('Hapus informasi ini?')) return; try { await api('announcements/' + b.dataset.adel, { method: 'DELETE' }); toast('Informasi dihapus.'); done(); } catch (x) { toast(x.message, true); } }));
}
/** Admin: Jadwal KBM dengan dua tampilan — per kelas dan per guru. */
async function jadwalKbm(el, q) {
  const v = q.v === 'guru' ? 'guru' : 'kelas';
  const tabs = `<div class="tabs" role="tablist">${[['kelas', 'Jadwal Kelas', 'building'], ['guru', 'Jadwal Guru', 'chalkboard-teacher']].map(([x, l, i]) => `<button type="button" role="tab" data-v="${x}" class="${x === v ? 'on' : ''}" aria-selected="${x === v}">${ti(i)} ${l}</button>`).join('')}</div>`;
  const list = v === 'kelas' ? await classrooms() : await api('teachers');
  const id = list.some((x) => String(x.id) === String(q.id)) ? q.id : list[0]?.id;
  const sel = list.length ? `<select id="pick" style="max-width:260px" aria-label="${v === 'kelas' ? 'Pilih kelas' : 'Pilih guru'}">${list.map((x) => `<option value="${x.id}" ${String(x.id) === String(id) ? 'selected' : ''}>${esc(x.name)}</option>`).join('')}</select>` : '';
  let body;
  if (!list.length) {
    body = `<div class="card">${empty(v === 'kelas' ? 'Belum ada kelas.' : 'Belum ada data guru.', `Impor data ${v === 'kelas' ? 'kelas & siswa' : 'guru'} lalu jadwal di <a href="${BOOT.portal.imports}">Impor Data</a>.`)}</div>`;
  } else {
    const t = await api('timetable?' + (v === 'kelas' ? 'classroom_id=' : 'teacher_id=') + id);
    body = (t.blocks?.length ? '' : `<div class="card">${empty('Belum ada jadwal untuk ' + (v === 'kelas' ? 'kelas ini' : 'guru ini') + '.', `Jadwal diimpor dari file aSc Timetables / Excel di <a href="${BOOT.portal.imports}">Impor Data</a>.`)}</div>`) + (t.blocks?.length ? timetableHtml(t) : '');
  }
  const name = list.find((x) => String(x.id) === String(id))?.name;
  el.innerHTML = hdr('Jadwal KBM', name ? (v === 'kelas' ? 'Kelas ' : '') + esc(name) : 'Jadwal kegiatan belajar mengajar per kelas dan per guru', sel) + tabs + body;
  $$('.tabs [data-v]', el).forEach((b) => (b.onclick = () => go('jadwal', { v: b.dataset.v })));
  const p = $('#pick', el); if (p) p.onchange = (e) => go('jadwal', { v, id: e.target.value });
}

/**
 * Papan dashboard (semua portal): Agenda disematkan di atas + informasi TERBARU PER BIDANG.
 * Dulu hanya 3–5 informasi terakhir sehingga agenda/informasi satu bidang tergeser kiriman bidang lain.
 */
export async function announcementsCard(withAuthor = true) {
  const agendaOn = false; // agenda sekolah & briefing tidak ditampilkan lagi
  const [L, A] = await Promise.all([api('announcements').catch(() => []), agendaOn ? api('agenda').catch(() => ({})) : {}]);
  const pins = Object.values(A || {}).filter((x) => x?.page);
  const seen = new Set();
  const latest = L.filter((a) => { const c = a.category || 'umum'; if (seen.has(c)) return false; seen.add(c); return true; });
  if (!pins.length && !latest.length) return '';
  const more = L.length - latest.length;
  return `<div class="card board">${pins.map((x) => `<div class="pin"><div class="row" style="justify-content:space-between;align-items:center"><b>${ti('calendar-event')} ${esc(x.label)}: ${esc(x.page.title)}</b><a class="linkbtn" href="#/agenda?jenis=${x.kind}">Buka agenda</a></div>
      <div class="prose pin-body">${rich(x.page.body)}</div><div class="mu" style="font-size:11px">${x.page.coordinator ? 'Koordinator: ' + esc(x.page.coordinator.name) + ' · ' : ''}diperbarui ${fd(x.page.updated_at)}</div></div>`).join('')}
    ${latest.length ? `<div class="row" style="justify-content:space-between;align-items:center${pins.length ? ';margin-top:14px' : ''}"><b>${ti('speakerphone')} Informasi terbaru per bidang</b><a class="linkbtn" href="#/info">Lihat semua${more > 0 ? ` (+${more})` : ''}</a></div>
      <div class="list" style="margin-top:10px">${latest.map((a) => announcementItem(a, withAuthor)).join('')}</div>` : ''}</div>`;
}

/**
 * Denah sekolah sebagai GAMBAR JPG (Informasi → Sarpras & menu Denah Sekolah).
 * Disusun & diunggah guru koordinator Sarpras; admin, guru, dan siswa hanya melihat.
 */
export async function denahCard(box) {
  const D = await api('school-map/image');
  const img = D.image;
  const when = img?.updated_at ? new Date(img.updated_at).toLocaleDateString('id-ID', { day: 'numeric', month: 'long', year: 'numeric' }) : '';
  box.innerHTML = `<div class="dn-img-head"><div><b>${ti('building')} Denah sekolah</b><p class="mu" style="margin:2px 0 0">${D.coordinator ? 'Disusun koordinator Sarpras: ' + esc(D.coordinator) : 'Disusun oleh guru koordinator Sarpras'}${when ? ' · diperbarui ' + when : ''}</p></div>
      ${img ? `<span class="row" style="flex:none"><a class="btn o sm nf" href="${esc(img.url)}" target="_blank" rel="noopener">${ti('eye')} Perbesar</a><a class="btn o sm nf" href="${esc(img.download)}">${ti('download')} Unduh JPG</a></span>` : ''}</div>
    ${img ? `<a class="dn-img" href="${esc(img.url)}" target="_blank" rel="noopener" title="Ketuk untuk memperbesar"><img src="${esc(img.url)}" alt="Denah sekolah" loading="lazy" ${img.width ? `width="${img.width}" height="${img.height}"` : ''}></a>`
      : `<div class="dn-empty">${ti('building')}<b>Denah belum diunggah</b><span class="mu">${D.can_edit ? 'Unggah gambar denah (JPG/PNG) di bawah ini.' : 'Denah sekolah akan tampil di sini setelah diunggah guru koordinator Sarpras.'}</span></div>`}
    ${D.can_edit ? `<form class="dn-up" id="dnu"><span class="mu grow">Anda koordinator Sarpras. Susun denah (gedung, lantai, nomor & nama ruang) lalu terbitkan sebagai JPG, atau unggah gambar denah sendiri.</span>
      <button type="button" class="btn sm nf" id="dnb">${ti('edit')} Penyusun denah</button>
      <label class="btn o sm nf" style="cursor:pointer">${ti('upload')} ${img ? 'Ganti denah' : 'Unggah denah'}<input type="file" accept="image/jpeg,image/png" hidden id="dnf"></label>
      ${img ? `<button type="button" class="btn o sm nf" id="dnx">${ti('trash')} Hapus</button>` : ''}</form>` : ''}`;
  const f = $('#dnf', box);
  if (f) f.onchange = async () => {
    const file = f.files[0]; if (!file) return;
    try {
      toast('Mengunggah denah…');
      const blob = file.size > 1.8 * 1048576 ? await shrinkJpeg(file, 3600, 1.8 * 1048576) : file;
      const fd2 = new FormData(); fd2.append('file', blob, 'denah.jpg');
      const r = await api('school-map/image', { method: 'POST', form: fd2 }); toast(r.message); denahCard(box);
    } catch (x) { toast(x.message, true); }
  };
  const pb = $('#dnb', box);
  if (pb) pb.onclick = async () => {
    const m = await import('./denah-poster.js' + new URL(import.meta.url).search);
    box.innerHTML = '<div class="mu">Memuat penyusun denah…</div>';
    try { await m.mountBuilder(box, { onDone: () => denahCard(box) }); } catch (e) { toast(e.message, true); denahCard(box); }
  };
  const x = $('#dnx', box);
  if (x) x.onclick = async () => { if (!confirm('Hapus gambar denah sekolah? Admin, guru, dan siswa tidak lagi melihat denah.')) return; try { const r = await api('school-map/image', { method: 'DELETE' }); toast(r.message); denahCard(box); } catch (e) { toast(e.message, true); } };
}
/** Perkecil gambar besar di HP sebelum unggah (batas unggah server). */
async function shrinkJpeg(file, maxSide, maxBytes) {
  const im = await createImageBitmap(file);
  const k = Math.min(1, maxSide / Math.max(im.width, im.height));
  const c = document.createElement('canvas'); c.width = Math.round(im.width * k); c.height = Math.round(im.height * k);
  const g = c.getContext('2d'); g.fillStyle = '#fff'; g.fillRect(0, 0, c.width, c.height); g.drawImage(im, 0, 0, c.width, c.height);
  for (const q of [0.9, 0.8, 0.7, 0.6]) { const b = await new Promise((r) => c.toBlob(r, 'image/jpeg', q)); if (b.size <= maxBytes) return b; }
  throw new Error('Gambar denah terlalu besar. Perkecil resolusinya lalu coba lagi.');
}

/** Pengumpulan tugas (guru): siapa sudah/belum mengumpulkan, berkasnya, waktu kumpul, nilai & catatan per siswa. */
async function tugasKumpul(el, id, q) {
  const T = await api('assignments/' + id);
  const tz = BOOT.school.timezone || 'Asia/Jakarta';
  const when = (iso) => new Intl.DateTimeFormat('id-ID', { timeZone: tz, day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' }).format(new Date(iso));
  const f = q.f || 'semua';
  const L = T.students;
  const cnt = { semua: L.length, sudah: L.filter((s) => s.done).length, belum: L.filter((s) => !s.done).length, terlambat: L.filter((s) => s.late).length, dinilai: L.filter((s) => s.graded).length };
  const show = L.filter((s) => f === 'sudah' ? s.done : f === 'belum' ? !s.done : f === 'terlambat' ? s.late : f === 'dinilai' ? s.graded : true);
  const st = (s) => !s.done ? '<span class="pl er">Belum</span>' : s.late ? '<span class="pl wr">Terlambat</span>' : '<span class="pl ok">Dikumpulkan</span>';
  el.innerHTML = `<p style="margin-bottom:10px"><a class="btn o sm" href="#/tugas">← Penugasan</a></p>`
    + hdr(esc(T.title), `${esc(T.classroom?.name)} · ${esc(T.subject)} · tenggat ${dueText(T.due_date, T.due_time)}`, `<a class="btn o nf" href="/api/assignments/${id}/export">${ti('download')} Rekap Excel</a>`)
    + (T.description ? `<div class="card prose">${esc(T.description)}</div>` : '')
    + `<div class="fg">${[['Terkumpul', `${cnt.sudah}/${cnt.semua}`], ['Belum', cnt.belum], ['Terlambat', cnt.terlambat], ['Sudah dinilai', `${cnt.dinilai}/${cnt.semua}`]].map(([l, v]) => `<div class="card stat"><span class="mu">${l}</span><b>${v}</b></div>`).join('')}</div>
    <div class="tabs">${[['semua', 'Semua'], ['sudah', 'Sudah kumpul'], ['belum', 'Belum kumpul'], ['terlambat', 'Terlambat'], ['dinilai', 'Dinilai']].map(([k, l]) => `<button type="button" data-f="${k}" class="${f === k ? 'on' : ''}">${l} (${cnt[k]})</button>`).join('')}</div>
    <div class="card"><p class="mu" style="margin:0 0 10px">Isi nilai 0–100 dan catatan, lalu Simpan. Siswa mendapat notifikasi dan melihat nilainya di Tugas-Ku. Siswa yang mengumpulkan di kertas bisa langsung dinilai (otomatis tercatat terkumpul).</p>
    ${table(['No', 'Siswa', 'Status', 'Waktu kumpul', 'Berkas', 'Nilai', 'Catatan untuk siswa', ''], show.map((s) => [
      String(L.indexOf(s) + 1), `<b>${esc(s.name)}</b><div class="mu">${esc(s.nis || '')}</div>`, st(s), s.submitted_at ? when(s.submitted_at) : '<span class="mu">—</span>',
      s.files.length ? s.files.map((x) => `<a class="tk-file" href="${esc(x.url)}" target="_blank" rel="noopener">${ti(/pdf/i.test(x.type) ? 'file-type-pdf' : /doc/i.test(x.type) ? 'file-import' : 'eye')} ${esc(x.name)}</a>`).join('') : (s.done ? '<span class="mu">tanpa berkas</span>' : ''),
      `<input type="number" min="0" max="100" step="0.5" value="${s.score ?? ''}" data-sc="${s.id}" aria-label="Nilai ${esc(s.name)}" style="width:84px">`,
      `<input type="text" maxlength="500" value="${esc(s.feedback || '')}" data-fb="${s.id}" aria-label="Catatan untuk ${esc(s.name)}" placeholder="Opsional" style="min-width:160px">`,
      `<button type="button" class="btn sm nf" data-save="${s.id}">${s.graded ? 'Perbarui' : 'Simpan'}</button>`]), 'Tidak ada siswa pada filter ini.')}</div>`;
  $$('[data-f]', el).forEach((b) => (b.onclick = () => go('tugas', { a: id, f: b.dataset.f })));
  const save = async (sid, btn) => {
    const sc = $(`[data-sc="${sid}"]`, el).value.trim(); const fb = $(`[data-fb="${sid}"]`, el).value.trim();
    btn.disabled = true;
    try { const r = await api(`assignments/${id}/grades/${sid}`, { method: 'PUT', body: { score: sc === '' ? null : +sc, feedback: fb || null } }); toast(r.message); const s = L.find((x) => x.id === +sid); Object.assign(s, { score: sc === '' ? null : +sc, feedback: fb, graded: r.graded, done: true }); btn.textContent = 'Perbarui'; btn.closest('tr').querySelector('td:nth-child(3)').innerHTML = st(s); }
    catch (x) { toast(x.message, true); } finally { btn.disabled = false; }
  };
  $$('[data-save]', el).forEach((b) => (b.onclick = () => save(b.dataset.save, b)));
  $$('[data-sc], [data-fb]', el).forEach((i) => i.addEventListener('keydown', (e) => { if (e.key === 'Enter') { e.preventDefault(); const sid = i.dataset.sc || i.dataset.fb; save(sid, $(`[data-save="${sid}"]`, el)); } }));
}

export const PAGES = {
  // ── Dashboard ───────────────────────────────────────────────────
  async dash(el) {
    const d = await api('dashboard');
    if (isAdmin()) {
      const pct = d.students ? Math.round((d.present / d.students) * 100) : 0;
      el.innerHTML = hdr('Dashboard', `Ringkasan sekolah · ${esc(d.day_name)}, ${fd(d.date)}`) + `
        <div class="gr">
          <div class="card"><div class="mu">Siswa aktif</div><div class="big">${nf(d.students)}</div></div>
          <div class="card"><div class="mu">Guru</div><div class="big">${nf(d.teachers)}</div></div>
          <div class="card"><div class="mu">Kelas</div><div class="big">${nf(d.classrooms)}</div></div>
          <div class="card"><div class="mu">Hadir hari ini</div><div class="big" style="color:var(--ok)">${nf(d.present)}<span class="mu" style="font-size:14px"> / ${nf(d.students)}</span></div><div class="bar"><i style="width:${pct}%"></i></div></div>
          <div class="card"><div class="mu">Jam mengajar hari ini</div><div class="big">${nf(d.hours_today)}</div></div>
        </div>
        ${d.is_school_day ? '' : '<div class="alert wr">Hari ini bukan hari sekolah.</div>'}
        <div class="card"><div class="row" style="align-items:center"><b>Presensi per kelas hari ini</b><a class="btn o nf" href="#/qr">Buka absensi</a></div>
          <div style="margin-top:10px">${table(['Kelas', 'Siswa', 'Hadir', 'Sakit', 'Izin', 'Alpa', 'Belum'], d.per_classroom.map((c) => [`<a href="#/qr?kelas=${c.id}">${esc(c.name)}</a>`, c.total, `<b style="color:var(--ok)">${c.H}</b>`, c.S, c.I, c.A ? `<b style="color:var(--er)">${c.A}</b>` : 0, c.belum]), 'Belum ada kelas yang berisi siswa. Impor data siswa lewat Portal admin → Impor Data Excel.')}</div></div>
        ${await announcementsCard()}`;
      return;
    }
    el.innerHTML = hdr('Dashboard', `Selamat datang, ${esc(S.user.name)} · ${esc(d.day_name)}, ${fd(d.date)}`) + await announcementsCard() + `
      <div class="gr">
        <div class="card"><div class="mu">Mata pelajaran</div><div class="big" style="font-size:18px">${esc(d.subject || '-')}</div></div>
        <div class="card"><div class="mu">Jam mengajar hari ini</div><div class="big">${nf(d.hours_today)}</div></div>
        <div class="card"><div class="mu">Jam mengajar per minggu</div><div class="big">${nf(d.hours_week)}</div></div>
        <div class="card"><div class="mu">Wali kelas</div><div class="big" style="font-size:18px">${d.homerooms.map((h) => esc(h.name)).join(', ') || '-'}</div></div>
      </div>
      <div class="card"><b>Jadwal mengajar hari ini</b><div style="margin-top:10px">${d.today_lessons.length ? d.today_lessons.map((b) => `
        <div class="lesson"><div class="tm">${esc(b.from || '')}–${esc(b.to || '')}<small>Jam ke-${b.period_start}${b.period_end > b.period_start ? '–' + b.period_end : ''}</small></div>
        <div><b>${esc(b.classroom?.name)}</b> · ${esc(b.subject)}<div style="display:flex;gap:6px;flex-wrap:wrap;margin-top:6px"><a class="btn o nf" style="padding:5px 10px;font-size:12px" href="#/qr?kelas=${b.classroom?.id}">QR & presensi</a><a class="btn o nf" style="padding:5px 10px;font-size:12px" href="#/jurnal?kelas=${b.classroom?.id}">Isi jurnal</a></div></div></div>`).join('') : empty('Tidak ada jadwal mengajar hari ini.')}</div></div>`;
  },

  // ── Absensi QR + rekap kelas ────────────────────────────────────
  async qr(el, q) {
    const { html, first } = await classOptions(q.kelas);
    const id = q.kelas || first;
    if (!id) { el.innerHTML = hdr('Absensi QR') + empty('Belum ada kelas.', 'Tambahkan kelas lewat impor data.'); return; }
    el.innerHTML = hdr(isAdmin() ? 'Absensi QR' : 'Absensi Masuk Kelas', 'QR kelas untuk dipindai siswa dari aplikasi, dan rekap presensi hari ini',
      `<select id="kls" aria-label="Kelas" style="max-width:220px">${html}</select>`) + '<div id="qrc"></div><div id="rek"></div>';
    $('#kls', el).onchange = (e) => go('qr', { kelas: e.target.value });
    await Promise.all([qrCard($('#qrc', el), id), rekap($('#rek', el), id)]);
  },

  // ── Data guru (admin) ───────────────────────────────────────────
  /** Kartu Login Guru (admin): centang guru → password sementara baru + link & QR aplikasi Guru, siap cetak. */
  async kartuguru(el, q) {
    if (!isAdmin()) return go('dash');
    const L = await api('teachers' + (q.q ? '?q=' + encodeURIComponent(q.q) : ''));
    const ACC = { aktif: ['Aktif', 'ok'], sementara: ['Password sementara', 'wr'], belum: ['Belum pernah masuk', 'wr'], none: ['Tanpa akun', 'er'] };
    const notActive = L.filter((t) => t.account !== 'aktif' && t.account !== 'none').length;
    el.innerHTML = hdr('Kartu Login Guru', `${nf(L.length)} guru · kartu berisi NIK, password sementara baru, link & QR pasang aplikasi Guru`, `<a class="btn o nf" href="#/guru">Data Guru</a>`) + `
      <form class="card row" id="sf"><div><label for="q">Cari nama, NIK, atau mapel</label><input id="q" value="${esc(q.q || '')}"></div><button class="btn o nf">Cari</button></form>
      <div class="card">
        <div class="lc-bar"><span class="mu grow">${ti('id-badge-2')} Centang guru lalu cetak. Password lama guru yang dicetak <b>langsung tidak berlaku</b>; guru wajib mengganti password saat masuk pertama.</span>
          <button type="button" class="btn o sm nf" id="lcpick">Pilih yang belum aktif (${notActive})</button>
          <button type="button" class="btn sm nf" id="lcgo" disabled>${ti('printer')} <span>Cetak kartu login (<b>0</b>)</span></button></div>
        ${table(['<input type="checkbox" id="lcall" aria-label="Pilih semua guru">', 'NIK', 'Nama', 'Mata pelajaran', 'Akun'], L.map((t) => [
          t.account === 'none' ? '' : `<input type="checkbox" data-lct value="${t.id}" data-acc="${t.account}" aria-label="Pilih ${esc(t.name)}">`, esc(t.nik), withAvatar(t.name, t.photo_url), esc(t.subject || '-'),
          `<span class="pl ${ACC[t.account]?.[1] || 'mu'}">${ACC[t.account]?.[0] || '-'}</span>`]), 'Belum ada guru. Impor lewat Impor Data atau tambah di Data Guru.')}</div>`;
    $('#sf', el).onsubmit = (e) => { e.preventDefault(); go('kartuguru', { q: $('#q', el).value.trim() }); };
    const sync = () => { const n = $$('[data-lct]:checked', el).length; const b = $('#lcgo', el); b.disabled = !n; $('b', b).textContent = n; };
    $$('[data-lct]', el).forEach((x) => (x.onchange = sync));
    $('#lcall', el).onchange = (e) => { $$('[data-lct]', el).forEach((c) => (c.checked = e.target.checked)); sync(); };
    $('#lcpick', el).onclick = () => { $$('[data-lct]', el).forEach((c) => (c.checked = c.dataset.acc !== 'aktif')); sync(); };
    $('#lcgo', el).onclick = () => {
      const picked = $$('[data-lct]:checked', el); const active = picked.filter((c) => c.dataset.acc === 'aktif').length;
      const d = dialog('Cetak kartu login guru', `<p>${picked.length} guru akan mendapat <b>password sementara baru</b> dan wajib menggantinya saat masuk. Password lama mereka langsung tidak berlaku.</p>
        ${active ? `<div class="alert wr">${active} guru sudah aktif memakai aplikasi. Lanjutkan hanya bila mereka lupa password.</div>` : ''}
        <p class="mu">Kartu bisa dibuka 30 menit. Berikan langsung ke gurunya, jangan difoto ke grup.</p>
        <div class="row" style="justify-content:flex-end;margin-top:12px"><button type="button" class="btn o nf" data-no>Batal</button><button type="button" class="btn nf" data-ok>${ti('printer')} Buat & cetak</button></div>`);
      $('[data-no]', d).onclick = () => d.close();
      $('[data-ok]', d).onclick = async (e) => {
        const btn = e.currentTarget; btn.disabled = true;
        const tab = window.open('about:blank', '_blank'); // dibuka saat diklik agar tidak diblokir browser
        try {
          const r = await api('teachers/login-cards', { method: 'POST', body: { teacher_ids: picked.map((c) => +c.value) } });
          d.close(); toast(r.message);
          if (tab) tab.location = r.url; else location.href = r.url;
          render();
        } catch (x) { if (tab) tab.close(); toast(x.message, true); btn.disabled = false; }
      };
    };
  },

  async guru(el, q) {
    if (!isAdmin()) return go('dash');
    const L = await api('teachers' + (q.q ? '?q=' + encodeURIComponent(q.q) : ''));
    el.innerHTML = hdr('Data Guru', `${nf(L.length)} guru${q.q ? ' cocok dengan “' + esc(q.q) + '”' : ''} · impor massal lewat <a href="${BOOT.portal.imports}">Impor Data Excel</a>`,
      `<a class="btn o nf" href="#/kartuguru">${ti('id-badge-2')} Kartu login guru</a> <button class="btn nf" id="add" type="button">+ Tambah guru</button>`) + `
      <form class="card row" id="sf"><div><label for="q">Cari nama, NIK, atau mapel</label><input id="q" value="${esc(q.q || '')}"></div><button class="btn o nf">Cari</button></form>
      <div class="card">${table(['NIK', 'Nama', 'Mata pelajaran', 'Jabatan', ''], L.map((t) => [esc(t.nik), withAvatar(t.name, t.photo_url), esc(t.subject || '-'), esc(t.position || '-'),
        `<button class="linkbtn" data-edit="${t.id}" type="button">Ubah</button> · <button class="linkbtn" data-dev="${t.user_id}" data-name="${esc(t.name)}" type="button">Perangkat</button>`]))}</div>`;
    $('#sf', el).onsubmit = (e) => { e.preventDefault(); go('guru', { q: $('#q', el).value.trim() }); };
    const form = (t = {}) => `<form id="tf">
      ${t.id ? '' : '<label>NIK (dipakai untuk login)</label><input name="nik" required maxlength="32">'}
      <label>Nama lengkap</label><input name="name" required value="${esc(t.name || '')}">
      <label>Mata pelajaran</label><input name="subject" value="${esc(t.subject || '')}">
      <label>Jabatan</label><input name="position" value="${esc(t.position || '')}" placeholder="Guru">
      ${t.id ? '' : '<label>Email (opsional)</label><input name="email" type="email">'}
      <button class="btn" style="margin-top:14px">${t.id ? 'Simpan' : 'Tambah guru'}</button>${t.id ? ' <button class="btn er" type="button" id="del">Hapus guru</button>' : ''}</form>`;
    $('#add', el).onclick = () => dialog('Tambah guru', form(), (d) => {
      $('#tf', d).onsubmit = async (e) => {
        e.preventDefault();
        try {
          const r = await api('teachers', { method: 'POST', body: Object.fromEntries(new FormData(e.target)) });
          $('.db', d).innerHTML = `<div class="alert ok" style="margin-top:12px"><b>${esc(r.teacher.name)}</b> ditambahkan.<br>Login: NIK <b>${esc(r.teacher.nik)}</b> · password awal <span class="secret">${esc(r.initial_password)}</span><br><span class="mu">Ditampilkan sekali. Wajib diganti saat login pertama.</span></div>`;
          d.addEventListener('close', () => render());
        } catch (x) { toast(x.message, true); }
      };
    });
    $$('[data-edit]', el).forEach((b) => (b.onclick = () => {
      const t = L.find((x) => String(x.id) === b.dataset.edit);
      dialog('Ubah guru', form(t), (d) => {
        $('#tf', d).onsubmit = async (e) => { e.preventDefault(); try { await api('teachers/' + t.id, { method: 'PUT', body: Object.fromEntries(new FormData(e.target)) }); d.close(); toast('Data guru disimpan.'); render(); } catch (x) { toast(x.message, true); } };
        $('#del', d).onclick = async () => { if (!confirm(`Hapus ${t.name}? Akun login guru ini ikut terhapus.`)) return; await api('teachers/' + t.id, { method: 'DELETE' }); d.close(); toast('Guru dihapus.'); render(); };
      });
    }));
    $$('[data-dev]', el).forEach((b) => (b.onclick = () => devices(b.dataset.dev, b.dataset.name)));
  },

  // ── Data siswa (admin) ──────────────────────────────────────────
  async siswa(el, q) {
    if (!isAdmin()) return go('dash');
    const cls = await classrooms();
    const qs = new URLSearchParams({ ...(q.q ? { q: q.q } : {}), ...(q.kelas ? { classroom_id: q.kelas } : {}), page: q.page || 1 });
    const P = await api('students?' + qs);
    // Kelas dikelompokkan per tingkat (7/8/9 atau X/XI/XII) sesuai jenjang sekolah.
    const opt = (c, sel) => `<option value="${c.id}" ${String(c.id) === String(sel) ? 'selected' : ''}>${esc(c.name)}</option>`;
    const grades = [...new Set(cls.map((c) => c.grade || ''))];
    const copt = (sel) => '<option value="">— Tanpa kelas —</option>' + grades.map((g) => {
      const list = cls.filter((c) => (c.grade || '') === g).map((c) => opt(c, sel)).join('');
      return g ? `<optgroup label="Tingkat ${esc(g)}">${list}</optgroup>` : `<optgroup label="Tingkat belum dikenali">${list}</optgroup>`;
    }).join('');
    el.innerHTML = hdr('Data Siswa', `${nf(P.total)} siswa · impor massal lewat <a href="${BOOT.portal.imports}">Impor Data Excel</a>`, '<button class="btn nf" id="add" type="button">+ Tambah siswa</button>') + `
      <form class="card row" id="sf"><div><label for="q">Cari nama atau NIS</label><input id="q" value="${esc(q.q || '')}"></div>
        <div><label for="k">Kelas</label><select id="k"><option value="">Semua kelas</option>${cls.map((c) => `<option value="${c.id}" ${String(c.id) === String(q.kelas) ? 'selected' : ''}>${esc(c.name)}</option>`).join('')}</select></div><button class="btn o nf">Terapkan</button></form>
      <div class="card">${table(['NIS', 'Nama', 'Kelas', 'Status', ''], P.data.map((s) => [esc(s.nis), withAvatar(s.name, s.photo_url)+`${s.class_role ? ` <span class="tag">${esc(s.class_role.replace('_', ' ').replace(/^./, (x) => x.toUpperCase()))}</span>` : ''}`, esc(s.classroom?.name || '-'),
        s.status === 'aktif' || (!s.status && s.is_active) ? '<span class="pl ok">Aktif</span>' : `<span class="pl mu">${esc(({ nonaktif: 'Nonaktif', lulus: 'Lulus', mutasi_keluar: 'Mutasi keluar', keluar: 'Keluar', arsip: 'Arsip' })[s.status] || 'Nonaktif')}</span>`, `<button class="linkbtn" data-edit="${s.id}" type="button">Ubah</button> · <button class="linkbtn" data-bio="${s.id}" data-name="${esc(s.name)}" type="button">Biodata</button> · <button class="linkbtn" data-dev="${s.user_id}" data-name="${esc(s.name)}" type="button">Perangkat</button>`]))}
        ${P.last_page > 1 ? `<div class="pager"><span class="mu">Hal. ${P.current_page} dari ${P.last_page}</span>${P.current_page > 1 ? `<a class="btn o" href="#/siswa?${new URLSearchParams({ ...q, page: P.current_page - 1 })}">‹</a>` : ''}${P.current_page < P.last_page ? `<a class="btn o" href="#/siswa?${new URLSearchParams({ ...q, page: P.current_page + 1 })}">›</a>` : ''}</div>` : ''}</div>`;
    $('#sf', el).onsubmit = (e) => { e.preventDefault(); go('siswa', { ...($('#q', el).value.trim() ? { q: $('#q', el).value.trim() } : {}), ...($('#k', el).value ? { kelas: $('#k', el).value } : {}) }); };
    const form = (s = {}) => `<form id="sf2">
      <label>NIS (dipakai untuk login)</label><input name="nis" required maxlength="32" value="${esc(s.nis || '')}">
      <label>Nama lengkap</label><input name="name" required value="${esc(s.name || '')}">
      <label>Kelas</label><select name="classroom_id">${copt(s.classroom_id)}</select>
      ${cls.length ? '' : `<p class="hint">Belum ada kelas. <a href="${BOOT.portal.classrooms}">Buat kelas dulu di Portal → Kelas</a>.</p>`}
      ${s.id ? '<p class="hint" id="hist">Memuat riwayat kelas…</p>' : ''}
      <label>Tanggal lahir (opsional)</label><input name="birth_date" type="date" value="${esc((s.birth_date || '').slice(0, 10))}">
      <button class="btn" style="margin-top:14px">${s.id ? 'Simpan' : 'Tambah siswa'}</button>${s.id ? ' <button class="btn er" type="button" id="del">Hapus siswa</button>' : ''}</form>`;
    const body = (f) => { const o = Object.fromEntries(new FormData(f)); Object.keys(o).forEach((k) => o[k] === '' && (o[k] = null)); return o; };
    $('#add', el).onclick = () => dialog('Tambah siswa', form(), (d) => {
      $('#sf2', d).onsubmit = async (e) => { e.preventDefault(); try { const r = await api('students', { method: 'POST', body: body(e.target) }); $('.db', d).innerHTML = `<div class="alert ok" style="margin-top:12px"><b>${esc(r.name)}</b> ditambahkan.<br>Login: NIS <b>${esc(r.nis)}</b> · password awal <span class="secret">${esc(r.initial_password)}</span><br><span class="mu">Ditampilkan sekali. Wajib diganti saat login pertama.</span></div>`; render(); } catch (x) { toast(x.message, true); } };
    });
    $$('[data-edit]', el).forEach((b) => (b.onclick = () => {
      const s = P.data.find((x) => String(x.id) === b.dataset.edit);
      dialog('Ubah siswa', form(s), (d) => {
        api('students/' + s.id + '/history').then((H) => { const h = $('#hist', d); if (h) h.innerHTML = H.length ? '<b>Riwayat kelas:</b> ' + H.map((x) => `${esc(x.academic_year)} · ${esc(x.classroom_name || 'tanpa kelas')} (${esc(x.status)})`).join(' · ') : 'Belum ada riwayat kelas.'; }).catch(() => {});
        $('#sf2', d).onsubmit = async (e) => { e.preventDefault(); try { await api('students/' + s.id, { method: 'PUT', body: body(e.target) }); d.close(); toast('Data siswa disimpan.'); render(); } catch (x) { toast(x.message, true); } };
        $('#del', d).onclick = async () => { if (!confirm(`Hapus ${s.name}? Riwayat presensinya ikut terhapus.\n\nUntuk siswa pindah sekolah/keluar, pakai "Mutasi / keluar" di portal Data Siswa agar riwayat tetap tersimpan.`)) return; await api('students/' + s.id, { method: 'DELETE' }); d.close(); toast('Siswa dihapus.'); render(); };
      });
    }));
    $$('[data-dev]', el).forEach((b) => (b.onclick = () => devices(b.dataset.dev, b.dataset.name)));
    $$('[data-bio]', el).forEach((b) => (b.onclick = async () => (await import('./biodata.js' + new URL(import.meta.url).search)).biodataDialog(+b.dataset.bio, b.dataset.name)));
  },

  // ── Jurnal ──────────────────────────────────────────────────────
  async jurnal(el, q) {
    const teacher = S.user.role === 'teacher';
    let form = '';
    if (teacher) {
      const { html, first } = await classOptions(q.kelas);
      const tt = await api('timetable');
      const nums = tt.periods.filter((p) => !p.is_break).map((p) => `<option value="${p.n}">${p.n} (${p.from}–${p.to})</option>`).join('');
      form = `<form class="card" id="jf"><b>Isi jurnal hari ini</b><p class="mu" style="margin-top:2px">Jam mengikuti jadwal mengajar; jumlah hadir/tidak hadir diambil otomatis dari presensi QR siswa.</p>
        <div class="row"><div><label for="jk">Kelas</label><select id="jk" name="classroom_id">${html}</select></div><div style="flex:2"><label for="js">Mata pelajaran</label><input id="js" name="subject"></div></div>
        <div class="row"><div><label for="ja">Dari jam ke-</label><select id="ja" name="period_start">${nums}</select></div><div><label for="jb">Sampai jam ke-</label><select id="jb" name="period_end">${nums}</select></div></div>
        <p class="mu" id="jsum" style="margin-top:8px"></p>
        <label for="jt">Materi yang diajarkan</label><textarea id="jt" name="topic" rows="2" required maxlength="500"></textarea>
        <label for="jn">Catatan (opsional)</label><input id="jn" name="notes" maxlength="2000">
        <div class="row"><div style="flex:2"><label for="tt">Tugas untuk siswa (opsional)</label><input id="tt" name="task_title" maxlength="160"></div><div><label for="td">Tenggat</label><input id="td" name="task_due" type="date" min="${todayStr()}"></div></div>
        <button class="btn" style="margin-top:14px">Simpan jurnal</button></form>`;
      if (!first) form = '<div class="alert wr">Belum ada kelas. Hubungi admin sekolah.</div>';
    }
    const filt = isAdmin() ? await classOptions(q.kelas) : null;
    const P = await api('journals' + (isAdmin() && q.kelas ? '?classroom_id=' + q.kelas : ''));
    el.innerHTML = hdr('Jurnal', teacher ? 'Catat materi tiap pertemuan' : 'Jurnal mengajar dari semua guru',
      isAdmin() ? `<select id="fk" style="max-width:220px"><option value="">Semua kelas</option>${filt.html}</select>` : '') + form + `
      <div class="card"><b>${teacher ? 'Jurnal saya' : 'Jurnal terbaru'}</b><div style="margin-top:10px">${table(['Tanggal', 'Jam', 'Kelas', 'Mapel', 'Materi', 'H/S/I/A', ...(teacher ? [''] : ['Guru'])],
        P.data.map((j) => [fd(j.date), `${j.period_start}${j.period_end > j.period_start ? '–' + j.period_end : ''}<div class="mu">${esc(j.from || '')}–${esc(j.to || '')}</div>`, esc(j.classroom?.name), esc(j.subject),
          esc(j.topic) + (j.notes ? `<div class="mu">${esc(j.notes)}</div>` : ''), `${j.present}/${j.sick}/${j.permit}/${j.absent}`,
          teacher ? `<button class="linkbtn" data-del="${j.id}" type="button">Hapus</button>` : esc(j.teacher?.name)]), 'Belum ada jurnal.')}</div></div>`;
    if (isAdmin()) { $('#fk', el).value = q.kelas || ''; $('#fk', el).onchange = (e) => go('jurnal', e.target.value ? { kelas: e.target.value } : {}); }
    $$('[data-del]', el).forEach((b) => (b.onclick = async () => { if (!confirm('Hapus jurnal ini?')) return; await api('journals/' + b.dataset.del, { method: 'DELETE' }); toast('Jurnal dihapus.'); render(); }));
    const f = $('#jf', el);
    if (!f) return;
    const sync = async () => {
      const p = await api('journals/prefill?classroom_id=' + $('#jk', f).value);
      if (p.period_start) { $('#ja', f).value = p.period_start; $('#jb', f).value = p.period_end; }
      $('#js', f).value = p.subject || '';
      const a = p.attendance;
      $('#jsum', f).innerHTML = `Presensi hari ini: <b style="color:var(--ok)">Hadir ${a.H}</b> · Sakit ${a.S} · Izin ${a.I} · Alpa ${a.A}${a.belum ? ' · Belum scan ' + a.belum : ''} dari ${a.total} siswa.`;
    };
    $('#jk', f).onchange = sync; sync().catch(() => {});
    $('#ja', f).onchange = () => { if (+$('#jb', f).value < +$('#ja', f).value) $('#jb', f).value = $('#ja', f).value; };
    f.onsubmit = async (e) => {
      e.preventDefault();
      const o = Object.fromEntries(new FormData(f)); if (!o.task_title) { delete o.task_title; delete o.task_due; }
      try { const r = await api('journals', { method: 'POST', body: o }); toast(r.message); render(); } catch (x) { toast(x.message, true); }
    };
  },

  // ── Penugasan (guru) ────────────────────────────────────────────
  async tugas(el, q) {
    if (S.user.role !== 'teacher') return go('dash');
    if (q.a) return tugasKumpul(el, +q.a, q);
    const { html } = await classOptions(q.kelas);
    const L = await api('assignments');
    const td = todayStr();
    el.innerHTML = hdr('Penugasan', 'Tugas langsung masuk ke dashboard dan “Tugas-Ku” siswa kelas terkait') + `
      <form class="card" id="tf"><div class="row"><div><label for="k">Kelas</label><select id="k" name="classroom_id">${html}</select></div>
        <div style="flex:2"><label for="m">Mata pelajaran</label><input id="m" name="subject" placeholder="Otomatis dari jadwal"></div></div>
        <div class="row"><div style="flex:3"><label for="j">Judul tugas</label><input id="j" name="title" required maxlength="160" placeholder="Contoh: Latihan soal bab 3"></div>
        <div><label for="d">Tanggal tenggat</label><input id="d" name="due_date" type="date" min="${td}" required></div>
        <div style="flex:0 1 130px"><label for="dt2">Jam batas</label><input id="dt2" name="due_time" type="time" value="23:59" lang="id" required></div></div>
        <p class="mu" id="dtl" style="margin:4px 0 0;font-size:13px">Pilih tanggal dan jam batas pengumpulan.</p>
        <label for="x">Keterangan / instruksi (opsional)</label><textarea id="x" name="description" rows="2" maxlength="2000"></textarea>
        <button class="btn" style="margin-top:14px">Berikan tugas</button></form>
      <div class="card"><b>Tugas yang saya berikan</b><div style="margin-top:10px">${table(['Kelas', 'Mapel', 'Tugas', 'Tenggat', 'Pengumpulan', ''],
        L.map((t) => [esc(t.classroom?.name), esc(t.subject), `<b>${esc(t.title)}</b>${t.description ? `<div class="mu">${esc(t.description)}</div>` : ''}`,
          dueText(t.due_date, t.due_time) + (pastDue(t.due_date, t.due_time) ? '<div class="mu">lewat tenggat</div>' : t.due_date === td ? '<div><b>hari ini</b></div>' : ''),
          `<a class="btn sm nf" href="#/tugas?a=${t.id}">${ti('clipboard-text')} ${t.done_count}/${t.student_count} terkumpul</a>${t.graded_count ? `<div class="mu" style="margin-top:3px">${t.graded_count} dinilai</div>` : ''}`,
          `<button class="linkbtn" data-del="${t.id}" type="button">Hapus</button>`]), 'Belum ada tugas.')}</div></div>`;
    const dl = () => { const d = $('#d', el).value, t = $('#dt2', el).value; $('#dtl', el).innerHTML = d ? `Batas pengumpulan: <b>${dueText(d, t)}</b>` : 'Pilih tanggal dan jam batas pengumpulan.'; };
    $('#d', el).oninput = dl; $('#dt2', el).oninput = dl;
    $('#tf', el).onsubmit = async (e) => {
      e.preventDefault();
      try { const r = await api('assignments', { method: 'POST', body: Object.fromEntries(new FormData(e.target)) }); toast(r.message); render(); } catch (x) { toast(x.message, true); }
    };
    $$('[data-del]', el).forEach((b) => (b.onclick = async () => { if (!confirm('Hapus tugas ini dari dashboard siswa?')) return; await api('assignments/' + b.dataset.del, { method: 'DELETE' }); toast('Tugas dihapus.'); render(); }));
  },

  // ── Jadwal ──────────────────────────────────────────────────────
  async jadwal(el, q) {
    if (isAdmin()) return jadwalKbm(el, q);
    let tools = '', path = 'timetable';
    if (isAdmin()) {
      const { html, first } = await classOptions(q.kelas);
      const kid = q.kelas || first;
      path += kid ? '?classroom_id=' + kid : '';
      tools = `<select id="k" style="max-width:220px">${html}</select>`;
    } else if (q.kelas) path += '?classroom_id=' + q.kelas;
    const t = await api(path);
    el.innerHTML = hdr(isAdmin() ? 'Jadwal' : 'Jadwal Mengajar', t.classroom ? 'Kelas ' + esc(t.classroom.name) : t.teacher ? esc(t.teacher.name) : '', tools) + timetableHtml(t);
    const k = $('#k', el); if (k) { if (q.kelas) k.value = q.kelas; k.onchange = (e) => go('jadwal', { kelas: e.target.value }); }
  },

  // ── Agenda ──────────────────────────────────────────────────────
  async agenda(el, q) {
    // Guru: Agenda kini satu tab di menu "Agenda". Admin: tab agenda dihapus → buka menu Agenda (informasi).
    const embed = !!q._embed;
    if (!embed) { go('info'); return; } // halaman agenda dihapus dari semua portal
    if (agendaMerged() && !embed) { go('info', { k: 'agenda', ...(q.jenis ? { jenis: q.jenis } : {}) }); return; }
    const nav = (jenis) => (embed ? go('info', { k: 'agenda', jenis }) : go('agenda', { jenis }));
    if ((BOOT.school.locked || []).includes('agenda')) {
      el.innerHTML = `<div class="card">${empty('Agenda belum termasuk paket ' + esc(BOOT.school.plan_label || '') + '.', `<a href="${BOOT.portal.billing}">Lihat paket langganan</a>`)}</div>`;
      return;
    }
    const A = await api('agenda');
    const kinds = Object.keys(A), k = kinds.includes(q.jenis) ? q.jenis : kinds[0], d = A[k], p = d.page;
    const tabs = kinds.length > 1 ? `<div class="tabs" role="tablist">${kinds.map((x) => `<button role="tab" type="button" class="${x === k ? 'on' : ''}" data-k="${x}">${esc(A[x].label)}</button>`).join('')}</div>` : '';
    let edit = '';
    if (!isAdmin() && d.can_edit) {
      // Guru koordinator agenda ini: ubah judul & isi (koordinator ditentukan admin).
      edit = `<form class="card noprint" id="af"><b>${ti('edit')} Kamu koordinator ${esc(d.label)}</b><p class="mu" style="margin:2px 0 6px">Perubahan langsung tampil di portal ${k === 'sekolah' ? 'guru dan siswa' : 'guru'} dan diberitahukan.</p>
        <label>Judul agenda</label><input name="title" required maxlength="160" value="${esc(p?.title || '')}">
        <div class="fmt-head"><label for="agB">Isi agenda</label><span class="fmt-bar" role="toolbar" aria-label="Format teks">
          <button type="button" data-fmt="*" title="Tebal" aria-label="Tebal"><b>B</b></button><button type="button" data-fmt="_" title="Miring" aria-label="Miring"><i>I</i></button><button type="button" data-fmt="~" title="Coret" aria-label="Coret"><s>S</s></button></span></div>
        <textarea id="agB" name="body" rows="12" required>${esc(p?.body || '')}</textarea>
        <div class="fmt-prev" id="agP" hidden><span class="mu">Pratinjau:</span><div class="prose"></div></div>
        <div style="margin-top:12px"><button class="btn">Simpan agenda</button></div></form>`;
    }
    if (isAdmin()) {
      const T = await api('teachers');
      edit = `<form class="card noprint" id="af"><b>${esc(d.label)}</b><p class="mu" style="margin:2px 0 6px">Ditampilkan di portal ${k === 'sekolah' ? 'guru dan siswa' : 'guru'}. Tetap bisa dibaca offline setelah dibuka sekali.</p>
        <label>Judul agenda</label><input name="title" required maxlength="160" value="${esc(p?.title || '')}" placeholder="Contoh: Agenda November 2026">
        <label>Koordinator (guru)</label><select name="coordinator_id"><option value="">— Pilih koordinator —</option>${T.map((t) => `<option value="${t.id}" ${p?.coordinator?.id === t.id ? 'selected' : ''}>${esc(t.name)}</option>`).join('')}</select>
        <label>Isi agenda</label><textarea name="body" rows="12" required placeholder="Ketik agenda di sini…">${esc(p?.body || '')}</textarea>
        <div style="margin-top:12px"><button class="btn">Simpan</button>${p ? ' <button class="btn o" type="button" id="del">Hapus laman</button>' : ''}</div></form>`;
    }
    const printBtn = '<button class="btn o nf noprint" type="button" data-action="print">' + ti('printer') + ' Cetak</button>';
    el.innerHTML = (embed ? `<div class="row noprint" style="justify-content:space-between;align-items:center;margin:4px 0 10px"><span class="mu">Agenda sekolah & agenda briefing · ditulis admin</span>${printBtn}</div>`
      : hdr('Agenda', isAdmin() ? 'Agenda sekolah & agenda briefing' : d.can_edit ? 'Kamu koordinator agenda ini: bisa melihat & mengedit' : 'Dari admin sekolah', printBtn)) + tabs + edit +
      (p ? `<div class="card"><b style="font-size:16px">${esc(p.title)}</b><p class="mu" style="margin:2px 0 12px">${p.coordinator ? 'Koordinator: ' + esc(p.coordinator.name) + ' · ' : ''}diperbarui ${fd(p.updated_at)}${p.updated_by ? ' oleh ' + esc(p.updated_by) : ''}</p><div class="prose">${rich(p.body)}</div></div>`
        : `<div class="card">${empty('Belum ada ' + d.label.toLowerCase() + '.', isAdmin() ? 'Isi formulir di atas.' : '')}</div>`);
    $$('[data-k]', el).forEach((b) => (b.onclick = () => nav(b.dataset.k)));
    const f = $('#af', el);
    if (f) {
      if ($('#agB', f)) bindFormat($('#agB', f), $('#agP', f), f);
      f.onsubmit = async (e) => { e.preventDefault(); const o = Object.fromEntries(new FormData(f)); if (isAdmin() && !o.coordinator_id) o.coordinator_id = null; try { const r = await api('agenda/' + k, { method: 'PUT', body: o }); toast(r.message); render(); } catch (x) { toast(x.message, true); } };
      const del = $('#del', f); if (del) del.onclick = async () => { if (!confirm('Hapus laman agenda ini?')) return; await api('agenda/' + k, { method: 'DELETE' }); toast('Agenda dihapus.'); render(); };
    }
  },

  // ── Wali kelas ──────────────────────────────────────────────────
  async wali(el, q) {
    if (isAdmin() && !q.kelas) {
      const [H, T] = await Promise.all([api('homeroom'), api('teachers')]);
      el.innerHTML = hdr('Wali Kelas', 'Tetapkan guru sebagai wali kelas') + `<div class="card">${table(['Kelas', 'Siswa', 'Wali kelas', ''], H.classrooms.map((c) => [`<b>${esc(c.name)}</b>`, c.students,
        `<select data-c="${c.id}" data-name="${esc(c.name)}" style="min-width:220px" aria-label="Wali kelas ${esc(c.name)}"><option value="">— Belum ditetapkan —</option>${T.map((t) => `<option value="${t.id}" ${c.homeroom?.id === t.id ? 'selected' : ''}>${esc(t.name)}</option>`).join('')}</select>`,
        `<a href="#/wali?kelas=${c.id}">Lihat</a>`]))}</div>`;
      $$('select[data-c]', el).forEach((s) => (s.onchange = async () => {
        try { await api('classrooms/' + s.dataset.c, { method: 'PUT', body: { name: s.dataset.name, homeroom_teacher_id: s.value ? +s.value : null } }); classesCache = null; toast('Wali kelas ' + s.dataset.name + ' disimpan.'); } catch (x) { toast(x.message, true); }
      }));
      return;
    }
    const H = await api('homeroom' + (q.kelas ? '?classroom_id=' + q.kelas : ''));
    if (!H.classrooms.length) { el.innerHTML = hdr('Wali Kelas') + `<div class="card">${empty('Kamu belum ditetapkan sebagai wali kelas.', 'Admin menetapkan wali kelas lewat menu Wali Kelas.')}</div>`; return; }
    const CR = H.class_roles || {}, CORE = H.core_roles || [];
    const kasOn = !(BOOT.school.locked || []).includes('kas');
    const ROLES = { '': '—', ...CR };
    // Susunan struktur kelas: tiap jabatan dipilih siswanya (pengurus inti + seksi-seksi).
    const slot = (c, k) => `<label class="sk"><span>${esc(CR[k])}</span><select data-pos="${k}" data-cls="${c.id}" aria-label="${esc(CR[k])}"><option value="">— Belum ditetapkan —</option>${c.students.map((s) => `<option value="${s.id}" ${s.class_role === k ? 'selected' : ''}>${esc(s.name)}${s.class_role && s.class_role !== k ? ' (' + esc(CR[s.class_role] || s.class_role) + ')' : ''}</option>`).join('')}</select></label>`;
    const filled = (c, keys) => keys.filter((k) => c.students.some((s) => s.class_role === k)).length;
    const struktur = (c) => {
      const seksi = Object.keys(CR).filter((k) => !CORE.includes(k));
      return `<div class="card sk-card"><div class="row" style="justify-content:space-between;align-items:center"><b style="font-size:16px">${ti('hierarchy-2')} Struktur Kelas ${esc(c.name)}</b>
        <span class="mu">${filled(c, Object.keys(CR))}/${Object.keys(CR).length} jabatan terisi</span></div>
        <p class="mu" style="margin:2px 0 4px">Pilih siswa untuk setiap jabatan. Satu siswa memegang satu jabatan; memilih siswa yang sudah menjabat akan memindahkan jabatannya. Pengurus inti dapat mengisi Jurnal Kelas.</p>
        <div class="sk-h">Pengurus inti <span class="mu">${filled(c, CORE)}/${CORE.length}</span></div><div class="sk-grid">${CORE.map((k) => slot(c, k)).join('')}</div>
        <div class="sk-h">Seksi-seksi <span class="mu">${filled(c, seksi)}/${seksi.length}</span></div><div class="sk-grid">${seksi.map((k) => slot(c, k)).join('')}</div></div>`;
    };
    el.innerHTML = hdr('Wali Kelas', 'Struktur kelas, presensi hari ini, dan perangkat siswa', isAdmin() ? '<a class="btn o nf" href="#/wali">‹ Semua kelas</a>' : '') + H.classrooms.map((c) => struktur(c) + (kasOn ? `<div class="card" data-kasbox="${c.id}" data-name="${esc(c.name)}"><span class="mu">Memuat kas kelas…</span></div>` : '') + `
      <div class="card"><b style="font-size:16px">Kelas ${esc(c.name)}</b>
        <p class="mu" style="margin:2px 0 12px">${c.summary.total} siswa · Hadir ${c.summary.H} · Sakit ${c.summary.S} · Izin ${c.summary.I} · Alpa ${c.summary.A}${c.summary.belum ? ' · Belum ' + c.summary.belum : ''}</p>
        <div class="lc-bar" data-lc="${c.id}"><span class="mu grow">${ti('id-badge-2')} <b>Kartu login</b>: centang siswa lalu cetak kartu berisi password sementara baru, link & QR pasang aplikasi.</span>
          <button type="button" class="btn o sm nf" data-lcpick="${c.id}">Pilih yang belum aktif (${c.students.filter((s) => s.account !== 'aktif').length})</button>
          <button type="button" class="btn sm nf" data-lcgo="${c.id}" disabled>${ti('printer')} <span>Cetak kartu login (<b>0</b>)</span></button></div>
        ${table(['<input type="checkbox" data-lcall="' + c.id + '" aria-label="Pilih semua siswa">', 'NIS', 'Nama', 'Akun', 'Jabatan', 'Presensi hari ini', 'HP', ''], c.students.map((s) => [`<input type="checkbox" data-lcs="${c.id}" value="${s.id}" data-acc="${s.account}" aria-label="Pilih ${esc(s.name)}">`, esc(s.nis), withAvatar(s.name, s.photo_url, esc(s.name)),
          s.account === 'aktif' ? '<span class="pl ok">Aktif</span>' : s.account === 'none' ? '<span class="pl er">Tanpa akun</span>' : '<span class="pl wr">Belum aktif</span>',
          `<select data-role="${s.id}" style="min-width:150px" aria-label="Jabatan ${esc(s.name)}">${Object.entries(ROLES).map(([v, l]) => `<option value="${v}" ${(s.class_role || '') === v ? 'selected' : ''}>${l}</option>`).join('')}</select>`,
          pill(s.attendance?.status, s.attendance?.time ? ` <span class="mu">${esc(s.attendance.time)}</span>` : ''), s.devices ? `${s.devices} HP` : '<span class="mu">belum</span>',
          s.devices ? `<button class="linkbtn" data-reset="${s.user_id}" data-name="${esc(s.name)}" type="button">Reset HP</button>` : '']), 'Belum ada siswa.')}</div>`).join('');
    // Kartu login per siswa (password sementara baru).
    const lcSync = (cid) => { const n = $$(`[data-lcs="${cid}"]:checked`, el).length; const b = $(`[data-lcgo="${cid}"]`, el); b.disabled = !n; $('b', b).textContent = n; };
    $$('[data-lcs]', el).forEach((x) => (x.onchange = () => lcSync(x.dataset.lcs)));
    $$('[data-lcall]', el).forEach((x) => (x.onchange = () => { $$(`[data-lcs="${x.dataset.lcall}"]`, el).forEach((c) => (c.checked = x.checked)); lcSync(x.dataset.lcall); }));
    $$('[data-lcpick]', el).forEach((x) => (x.onclick = () => { $$(`[data-lcs="${x.dataset.lcpick}"]`, el).forEach((c) => (c.checked = c.dataset.acc !== 'aktif')); lcSync(x.dataset.lcpick); }));
    $$('[data-lcgo]', el).forEach((x) => (x.onclick = () => {
      const picked = $$(`[data-lcs="${x.dataset.lcgo}"]:checked`, el); const active = picked.filter((c) => c.dataset.acc === 'aktif').length;
      const d = dialog('Cetak kartu login', `<p>${picked.length} siswa akan mendapat <b>password sementara baru</b> dan wajib menggantinya saat masuk. Password lama mereka langsung tidak berlaku.</p>
        ${active ? `<div class="alert wr">${active} siswa sudah aktif memakai aplikasi. Lanjutkan hanya bila mereka lupa password.</div>` : ''}
        <p class="mu">Kartu bisa dibuka 30 menit. Berikan langsung ke siswanya, jangan difoto ke grup.</p>
        <div class="row" style="justify-content:flex-end;margin-top:12px"><button type="button" class="btn o nf" data-no>Batal</button><button type="button" class="btn nf" data-ok>${ti('printer')} Buat & cetak</button></div>`);
      $('[data-no]', d).onclick = () => d.close();
      $('[data-ok]', d).onclick = async (e) => {
        const btn = e.currentTarget; btn.disabled = true;
        const tab = window.open('about:blank', '_blank'); // dibuka saat diklik agar tidak diblokir browser
        try {
          const r = await api('students/login-cards', { method: 'POST', body: { student_ids: picked.map((c) => +c.value) } });
          d.close(); toast(r.message);
          if (tab) tab.location = r.url; else location.href = r.url;
          render();
        } catch (x) { if (tab) tab.close(); toast(x.message, true); btn.disabled = false; }
      };
    }));
    // Kas Kelas: wali kelas hanya memantau (ringkasan + dialog "Pantau kas").
    if (kasOn && H.classrooms.length) {
      import('./kas.js' + new URL(import.meta.url).search).then((K) => $$('[data-kasbox]', el).forEach((b) => K.kasSummary(b, +b.dataset.kasbox, b.dataset.name)));
    }
    $$('[data-pos]', el).forEach((x) => (x.onchange = async () => {
      const k = x.dataset.pos, c = H.classrooms.find((r) => String(r.id) === x.dataset.cls);
      const holder = c.students.find((s) => s.class_role === k);
      x.disabled = true;
      try {
        if (x.value) await api('students/' + x.value + '/class-role', { method: 'PUT', body: { class_role: k } });
        else if (holder) await api('students/' + holder.id + '/class-role', { method: 'PUT', body: { class_role: null } });
        toast(CR[k] + (x.value ? ' disimpan.' : ' dikosongkan.')); render();
      } catch (e) { toast(e.message, true); x.disabled = false; }
    }));
    $$('[data-role]', el).forEach((s) => (s.onchange = async () => { try { await api('students/' + s.dataset.role + '/class-role', { method: 'PUT', body: { class_role: s.value || null } }); toast('Jabatan disimpan.'); render(); } catch (x) { toast(x.message, true); } }));
    $$('[data-reset]', el).forEach((b) => (b.onclick = async () => {
      if (!confirm(`Reset HP ${b.dataset.name}? Siswa bisa masuk dari HP baru (mis. HP hilang/ganti).`)) return;
      try { const r = await api('users/' + b.dataset.reset + '/devices', { method: 'DELETE' }); toast(r.message); render(); } catch (x) { toast(x.message, true); }
    }));
  },

  // ── Informasi (admin) ───────────────────────────────────────────
  async info(el, q) {
    const admin = isAdmin(), merged = agendaMerged();
    // Menu "Agenda": guru = tab Agenda (sekolah + briefing) + tab bidang; admin = tab bidang saja.
    const CATS = merged ? [['agenda', 'Agenda', 'calendar-event'], ...INFO_CATS] : INFO_CATS;
    const k = CATS.some(([v]) => v === q.k) ? q.k : (merged ? 'agenda' : 'kepegawaian');
    const title = S.user.role === 'student' ? 'Informasi' : 'Agenda';
    if (k === 'agenda') {
      el.innerHTML = hdr(title, 'Agenda sekolah, agenda briefing, dan informasi per bidang dari guru koordinator')
        + `<div class="tabs" role="tablist">${CATS.map(([v, l, i]) => `<button type="button" role="tab" data-k="${v}" aria-selected="${v === k}" class="${v === k ? 'on' : ''}">${ti(i)} ${esc(l)}</button>`).join('')}</div><div id="agBox"></div>`;
      $$('.tabs [data-k]', el).forEach((t) => (t.onclick = () => go('info', { k: t.dataset.k })));
      await PAGES.agenda($('#agBox', el), { ...q, _embed: 1 });
      return;
    }
    const [L, C, T] = await Promise.all([api('announcements?category=' + k), api('info-coordinators'), admin ? api('teachers').catch(() => []) : []]);
    let LV = L;
    const cat = INFO_CATS.find(([v]) => v === k);
    const coord = C[k];
    const mine = (C.mine || []).includes(k); // hanya guru koordinator bidang ini yang menulis
    el.innerHTML = hdr(title, mine ? `Anda koordinator ${esc(cat[1])}: informasi yang Anda kirim tampil di dashboard guru dan/atau siswa` : admin ? 'Informasi per bidang dari guru koordinator' : 'Informasi sekolah per bidang, ditulis oleh guru koordinator masing-masing bidang')
      + `<div class="tabs" role="tablist">${CATS.map(([v, l, i]) => `<button type="button" role="tab" data-k="${v}" aria-selected="${v === k}" class="${v === k ? 'on' : ''}">${ti(i)} ${esc(l)}${(C.mine || []).includes(v) ? ' <span class="chip nf" style="font-size:10px">Anda</span>' : ''}</button>`).join('')}</div>`
      + (admin ? `<form class="card coord" id="cf">${coord?.photo_url ? avatar(coord.name, coord.photo_url, 'coord-ic coord-ph') : `<span class="coord-ic">${ti('user')}</span>`}<div class="coord-body"><span class="mu">Koordinator ${esc(cat[1])} · yang mengisi informasi bidang ini</span>
          <b>${coord ? esc(coord.name) : '<span class="mu">Belum dipilih</span>'}</b></div>
          <select name="teacher_id" aria-label="Pilih koordinator"><option value="">— Pilih guru —</option>${T.map((t) => `<option value="${t.id}" ${coord?.id === t.id ? 'selected' : ''}>${esc(t.name)}</option>`).join('')}</select>
          <button class="btn o">Simpan koordinator</button>${T.length ? '' : `<span class="mu" style="flex-basis:100%;font-size:12px">Belum ada data guru. Impor guru dulu di <a href="${BOOT.portal.imports}">Impor Data</a>.</span>`}</form>`
        : `<div class="card coord">${coord?.photo_url ? avatar(coord.name, coord.photo_url, 'coord-ic coord-ph') : `<span class="coord-ic">${ti('user')}</span>`}<div class="coord-body"><span class="mu">Koordinator ${esc(cat[1])}</span><b>${coord ? esc(coord.name) : '<span class="mu">Belum ditentukan admin</span>'}</b></div></div>`)
      + (admin && !mine ? `<div class="alert in">Admin menentukan koordinator. Informasi ${esc(cat[1])} ditulis oleh guru koordinatornya; admin, guru, dan siswa membaca.</div>` : '')
      + (k === 'sarpras' ? `<div class="card" id="dnBox"><div class="mu">Memuat denah…</div></div>` : '')
      + (mine ? `<form class="card" id="if"><b>${ti(cat[2])} Kirim informasi ${esc(cat[1])}</b><p class="mu" style="margin:2px 0 0">${esc(cat[3])}</p>
        <input type="hidden" name="category" value="${k}">
        <div class="row"><div style="flex:3"><label>Judul</label><input name="title" required maxlength="120" placeholder="${esc(cat[4])}"></div>
        <div><label>Tujuan</label><select name="audience"><option value="semua">Guru dan siswa</option><option value="guru">Guru saja</option><option value="siswa">Siswa saja</option></select></div></div>
        <div class="fmt-head"><label for="ib">Isi informasi</label><span class="fmt-bar" role="toolbar" aria-label="Format teks">
          <button type="button" data-fmt="*" title="Tebal (*teks*)" aria-label="Tebal"><b>B</b></button><button type="button" data-fmt="_" title="Miring (_teks_)" aria-label="Miring"><i>I</i></button><button type="button" data-fmt="~" title="Coret (~teks~)" aria-label="Coret"><s>S</s></button></span></div>
        <textarea id="ib" name="body" rows="4" required maxlength="3000" placeholder="Blok teks lalu tekan B / I, atau ketik *tebal* dan _miring_ seperti di WhatsApp."></textarea>
        <div class="fmt-prev" id="ibp" hidden><span class="mu">Pratinjau:</span><div class="prose"></div></div>
        ${k === 'sarpras' ? `<label>Lampiran denah / dokumen (JPG, PNG, atau PDF · maks. 10 MB, opsional)</label><input type="file" name="file" accept=".jpg,.jpeg,.png,.pdf,image/jpeg,image/png,application/pdf"><p class="mu" style="margin:4px 0 0">Mis. denah ruang kelas, denah gedung, atau jadwal pemakaian ruangan.</p>` : ''}
        <button class="btn" style="margin-top:12px">Kirim informasi</button></form>` : '')
      + `<div class="card"><div class="row" style="align-items:center;justify-content:space-between"><b>Informasi ${esc(cat[1])}</b>
          ${L.some((a) => a.mine) ? `<label class="opt" style="flex:none;margin:0;font-size:13px"><input type="checkbox" id="imine" ${q.saya ? 'checked' : ''}> Hanya postingan saya</label>` : ''}</div>
        <div class="list" style="margin-top:10px">${(LV = q.saya ? L.filter((a) => a.mine) : L).length ? LV.map((a) => `<div class="info-row">${announcementItem(a, true)}<span class="ann-act">${announcementActions(a)}</span></div>`).join('') : empty('Belum ada informasi ' + cat[1] + '.', coord ? 'Informasi ditulis oleh ' + esc(coord.name) + '.' : 'Admin belum menentukan koordinator bidang ini.')}</div></div>`;
    $$('.tabs [data-k]', el).forEach((t) => (t.onclick = () => go('info', { k: t.dataset.k })));
    const cf = $('#cf', el);
    if (cf) cf.onsubmit = async (e) => {
      e.preventDefault();
      try { const r = await api('info-coordinators/' + k, { method: 'PUT', body: { teacher_id: e.target.teacher_id.value ? +e.target.teacher_id.value : null } }); toast(r.message); render(); }
      catch (x) { toast(x.message, true); }
    };
    if (k === 'sarpras') denahCard($('#dnBox', el)).catch((x) => { $('#dnBox', el).innerHTML = `<div class="mu">${esc(x.message)}</div>`; });
    const f = $('#if', el);
    if (f) {
      bindFormat($('#ib', el), $('#ibp', el), el);
      f.onsubmit = async (e) => {
        e.preventDefault();
        const btn = e.target.querySelector('button.btn'); btn.disabled = true;
        try { const r = await api('announcements', { method: 'POST', form: new FormData(e.target) }); toast(r.message); render(); }
        catch (x) { toast(x.message, true); btn.disabled = false; }
      };
    }
    bindAnnouncementActions(el, L, render);
    const im = $('#imine', el); if (im) im.onchange = () => go('info', { k, ...(im.checked ? { saya: 1 } : {}) });
  },

  // ── Pengaturan presensi (admin) ─────────────────────────────────
  async pengaturan(el) {
    if (!isAdmin()) return go('dash');
    const [s, tt] = await Promise.all([api('settings/attendance'), api('timetable')]);
    const nums = tt.periods.filter((p) => !p.is_break);
    el.innerHTML = hdr('Pengaturan Presensi', 'Aturan scan QR untuk semua kelas') + `
      <form class="card" id="sf"><b>Mode QR</b>
        <label class="opt"><input type="radio" name="qr_mode" value="static" ${s.qr_mode === 'static' ? 'checked' : ''}><span><b>QR statis (dicetak)</b><span class="mu">Ditempel di depan kelas. Aman karena scan wajib dari WiFi sekolah dan HP terdaftar.</span></span></label>
        <label class="opt"><input type="radio" name="qr_mode" value="dynamic" ${s.qr_mode === 'dynamic' ? 'checked' : ''}><span><b>QR dinamis (layar guru)</b><span class="mu">Berganti tiap 30 detik di HP/laptop guru.</span></span></label>
        <div class="row"><div><label>Batas presensi (alpa otomatis setelah)</label><select name="cutoff_period">${nums.map((p) => `<option value="${p.n}" ${p.n === s.cutoff_period ? 'selected' : ''}>Akhir jam ke-${p.n} (${p.to})</option>`).join('')}</select></div>
          <div><label>Boleh scan sebelum jam pertama (menit)</label><input name="early_minutes" type="number" min="0" max="120" value="${s.early_minutes}"></div></div>
        <label class="check"><input type="checkbox" name="restrict_to_lessons" ${s.restrict_to_lessons ? 'checked' : ''}> Scan hanya pada jam pelajaran kelas itu (sesuai jadwal)</label>
        <div class="row"><div><label>Maks. HP per siswa</label><input name="student" type="number" min="1" max="3" value="${s.device_limits.student}"></div><div><label>Maks. perangkat per guru</label><input name="teacher" type="number" min="1" max="5" value="${s.device_limits.teacher}"></div></div>
        <button class="btn" style="margin-top:14px">Simpan pengaturan</button></form>
      <div class="card"><b>WiFi sekolah</b><p class="mu" style="margin:2px 0 10px">Siswa hanya bisa presensi dari jaringan ini. Daftarkan dari HP/laptop yang sedang tersambung ke WiFi sekolah.</p>
        <div class="alert ${s.current_ip_allowed ? 'ok' : 'wr'}">IP kamu sekarang: <b>${esc(s.current_ip)}</b> — ${s.current_ip_allowed ? 'sudah termasuk WiFi sekolah ✓' : 'belum terdaftar'}.</div>
        <div class="list" id="nets">${s.networks.map((n, i) => `<div class="item"><div class="grow"><div class="t code" style="font-size:13px">${esc(n.cidr)}</div><div class="mu">${esc(n.label || '')}</div></div><button class="linkbtn" data-rm="${i}" type="button">Hapus</button></div>`).join('') || empty('Belum ada jaringan.', 'Tanpa jaringan terdaftar, presensi QR ditolak.')}</div>
        <div class="row" style="margin-top:10px"><div><label>Nama jaringan</label><input id="nl" placeholder="Contoh: WiFi Gedung A"></div><button class="btn nf" id="reg" type="button">Daftarkan jaringan ini (${esc(s.current_range || s.current_ip)})</button></div></div>
      <div class="card"><b>QR cetak</b><p class="mu" style="margin:2px 0 10px">Lembar QR semua kelas untuk dicetak dan ditempel.</p><a class="btn o" href="${BOOT.portal.print_qr}" target="_blank" rel="noopener">${ti('printer')} Cetak QR semua kelas</a></div>`;
    $('#sf', el).onsubmit = async (e) => {
      e.preventDefault();
      const f = new FormData(e.target);
      const body = { qr_mode: f.get('qr_mode'), cutoff_period: +f.get('cutoff_period'), early_minutes: +f.get('early_minutes'), restrict_to_lessons: f.has('restrict_to_lessons'), device_limits: { student: +f.get('student'), teacher: +f.get('teacher') } };
      try { await api('settings/attendance', { method: 'PUT', body }); toast('Pengaturan presensi disimpan.'); } catch (x) { toast(x.message, true); }
    };
    $$('[data-rm]', el).forEach((b) => (b.onclick = async () => {
      if (!confirm('Hapus jaringan ini? Siswa tidak bisa presensi dari jaringan tersebut.')) return;
      const nets = s.networks.filter((_, i) => String(i) !== b.dataset.rm);
      try { await api('settings/attendance', { method: 'PUT', body: { networks: nets } }); toast('Jaringan dihapus.'); render(); } catch (x) { toast(x.message, true); }
    }));
    $('#reg', el).onclick = async () => { try { const r = await api('settings/attendance/networks/register-current', { method: 'POST', body: { label: $('#nl', el).value || null } }); toast(r.message || 'Jaringan didaftarkan.'); render(); } catch (x) { toast(x.message, true); } };
  },
};

// ── Komponen bersama ──────────────────────────────────────────────
export function timetableHtml(t) {
  if (!t.blocks.length) return `<div class="card">${empty('Belum ada jadwal.', 'Jadwal diimpor admin dari file aSc Timetables / Excel.')}</div>`;
  const days = (t.school_days || [1, 2, 3, 4, 5]).filter((d) => t.blocks.some((b) => b.day === d));
  const order = [...days.filter((d) => d >= t.today), ...days.filter((d) => d < t.today)];
  return order.map((d) => `<div class="day card ${d === t.today ? 'today' : ''}"><h3>${esc(t.days[d])}${d === t.today ? ' <span class="tag">Hari ini</span>' : ''}</h3>
    ${t.blocks.filter((b) => b.day === d).map((b) => `<div class="lesson"><div class="tm">${esc(b.from || '')}–${esc(b.to || '')}<small>Jam ke-${b.period_start}${b.period_end > b.period_start ? '–' + b.period_end : ''}</small></div>
      <div><b>${esc(b.subject)}</b><div class="mu">${t.classroom ? esc(b.teacher?.name || '-') : esc(b.classroom?.name || '-')}</div></div></div>`).join('')}</div>`).join('')
    + `<details class="card"><summary>Jam KBM</summary><div style="margin-top:10px">${table(['Jam ke', 'Mulai', 'Selesai'], t.periods.map((p) => [p.is_break ? `<span class="mu">${esc(p.label)}</span>` : p.n, p.from, p.to]))}</div></details>`;
}

let qrTimer;
async function qrCard(box, id) {
  clearInterval(qrTimer);
  const draw = async () => {
    const q = await api('classrooms/' + id + '/qr');
    const dyn = q.mode === 'dynamic';
    box.innerHTML = `<div class="card"><div class="qrwrap"><div class="qrb"><img src="/api/classrooms/${id}/qr.svg?t=${Date.now()}" alt="QR presensi kelas ${esc(q.classroom.name)}"></div>
      <div><div class="mu">Kode QR kelas</div><div class="big" style="font-size:22px">${esc(q.classroom.name)}</div><div class="code" style="margin-top:4px">${esc(q.payload)}</div>
        <p class="mu" style="margin-top:8px">${dyn ? 'QR dinamis: berganti otomatis tiap 30 detik. Tampilkan layar ini di depan kelas.' : 'QR statis: cetak dan tempel di kelas. Siswa memindai dari aplikasi saat tersambung WiFi sekolah.'}</p>
        ${dyn ? `<div class="countdown"><i id="cd" style="width:100%"></i></div>` : ''}
        <div class="row" style="margin-top:12px">${isAdmin() ? `<button class="btn o nf" id="rot" type="button">Perbarui QR</button>${dyn ? '' : `<a class="btn o nf" href="${BOOT.portal.print_qr}?kelas=${id}" target="_blank" rel="noopener">${ti('printer')} Cetak QR</a>`}` : ''}</div></div></div></div>`;
    const r = $('#rot', box);
    if (r) r.onclick = async () => { if (!confirm('QR lama langsung tidak berlaku. Untuk QR statis, kamu harus mencetak dan menempel ulang. Lanjutkan?')) return; const x = await api('classrooms/' + id + '/qr/rotate', { method: 'POST' }); toast(x.message || 'QR diperbarui.'); draw(); };
    if (dyn) {
      let left = q.expires_in;
      qrTimer = setInterval(() => {
        if (!document.body.contains(box)) return clearInterval(qrTimer);
        left -= 1; const cd = $('#cd', box); if (cd) cd.style.width = Math.max(0, (left / 30) * 100) + '%';
        if (left <= 0) { clearInterval(qrTimer); draw(); }
      }, 1000);
    }
  };
  await draw();
}

async function rekap(box, id) {
  const r = await api('classrooms/' + id + '/attendance');
  const s = r.summary;
  box.innerHTML = `<div class="card"><b>Rekap ${esc(r.classroom.name)} · ${fd(r.date)}</b>
    <p class="mu" style="margin:4px 0 10px">Hadir ${s.H} · Sakit ${s.S} · Izin ${s.I} · Alpa ${s.A}${s.belum ? ' · Belum scan ' + s.belum : ''} dari ${s.total}. Yang tidak scan sampai batas presensi dihitung alpa otomatis; guru hanya bisa mengoreksi ke Hadir, Sakit, atau Izin.</p>
    ${table(['NIS', 'Nama', 'Status', 'Keterangan'], r.rows.map(({ student: st, attendance: a }) => [esc(st.nis), esc(st.name),
      `<div class="sg">${['H', 'S', 'I'].map((x) => `<button type="button" class="${x} ${a?.status === x ? 'on' : ''}" data-s="${st.id}" data-v="${x}" aria-label="${ST[x][0]}">${x}</button>`).join('')}${a?.status === 'A' ? '<button class="A on" disabled title="Alpa otomatis">A</button>' : ''}</div>`,
      a ? (a.method === 'qr' ? `Scan ${esc(a.time || '')}${a.is_late ? ' · terlambat' : ''}` : a.method === 'auto' ? 'Tidak scan (otomatis)' : 'Koreksi' + (a.note ? ': ' + esc(a.note) : '')) : '<span class="mu">Belum scan</span>']), 'Belum ada siswa di kelas ini.')}
    <div class="row" style="margin-top:10px"><button class="btn o nf" id="rf" type="button">↻ Muat ulang</button></div></div>`;
  $$('[data-s]', box).forEach((b) => (b.onclick = async () => {
    try { await api('students/' + b.dataset.s + '/attendance', { method: 'PUT', body: { status: b.dataset.v } }); toast('Presensi dikoreksi.'); rekap(box, id); } catch (x) { toast(x.message, true); }
  }));
  $('#rf', box).onclick = () => rekap(box, id);
}

async function devices(userId, name) {
  try {
    const d = await api('users/' + userId + '/devices');
    const act = d.devices.filter((x) => x.active);
    dialog('Perangkat ' + name, `<p class="mu" style="margin-top:10px">Batas ${d.limit} perangkat. Reset jika HP hilang atau ganti HP.</p>
      <div class="list" style="margin-top:10px">${act.map((x) => `<div class="item"><div class="grow"><div class="t">${esc(x.name || 'Perangkat')}</div><div class="mu">Terdaftar ${fd(x.registered_at)} · terakhir ${fd(x.last_seen_at)}</div></div></div>`).join('') || empty('Belum ada perangkat terdaftar.')}</div>
      ${act.length ? '<button class="btn er" id="rs" type="button" style="margin-top:12px">Reset perangkat</button>' : ''}`, (dl) => {
      const b = $('#rs', dl); if (b) b.onclick = async () => { const r = await api('users/' + userId + '/devices', { method: 'DELETE' }); toast(r.message); dl.close(); };
    });
  } catch (x) { toast(x.message, true); }
}
