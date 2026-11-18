/* MyPresence PWA — menu prototipe yang dilengkapi:
 * Admin/guru : Absensi guru (hadir sekolah → masuk kelas), CBT, Leger Rapor, Pembiasaan, MP-Library, piket & jurnal kelas di Wali Kelas.
 * Siswa      : Pembiasaan, Struktur Kelas, Jadwal Piket, MP-Library, Jurnal Kelas pengurus, Tugas-Ku per folder mapel.
 */
const { api, S, $, $$, esc, avatar, withAvatar, fd, dueText, pastDue, hdr, empty, toast, dialog, go, render, todayStr } = window.MP;
const { startCamera, stopCamera } = window.MP.camera;

const isAdmin = () => S.user.role === 'admin';
const isStudent = () => S.user.role === 'student';
const table = (head, rows, emptyText = 'Belum ada data.') => `<div class="sc"><table><tr>${head.map((h) => `<th>${h}</th>`).join('')}</tr>${rows.length
  ? rows.map((r) => `<tr>${r.map((c) => `<td class="${String(c).length > 60 ? 'wrap' : ''}">${c ?? ''}</td>`).join('')}</tr>`).join('')
  : `<tr><td colspan="${head.length}" class="mu">${emptyText}</td></tr>`}</table></div>`;
const pl = (label, tone) => `<span class="pl ${tone}">${esc(label)}</span>`;
const fsz = (n) => !n ? '-' : n < 1048576 ? Math.max(1, Math.round(n / 1024)) + ' KB' : (n / 1048576).toFixed(1).replace('.', ',') + ' MB';
const dt = (iso) => iso ? new Date(iso).toLocaleDateString('id-ID', { day: 'numeric', month: 'short', year: 'numeric' }) : '-';
const tabs = (items, cur) => `<div class="tabs" role="tablist">${items.map(([v, l]) => `<button type="button" role="tab" data-tab="${v}" aria-selected="${cur === v}" class="${cur === v ? 'on' : ''}">${l}</button>`).join('')}</div>`;
/** Tab mengubah satu parameter hash (mis. ?k=literasi) agar bisa dibagikan & tombol kembali bekerja. */
const bindTabs = (el, key, extra = {}) => $$('[data-tab]', el).forEach((b) => (b.onclick = () => go(S.route, { ...S.query, ...extra, [key]: b.dataset.tab })));
const busy = async (btn, fn) => { if (btn) btn.disabled = true; try { await fn(); } catch (x) { toast(x.message, true); } finally { if (btn) btn.disabled = false; } };
/** Buka tab baru langsung saat diklik (agar tidak diblokir), lalu arahkan setelah alamatnya didapat. */
/**
 * Foto tugas dikecilkan di HP sebelum diunggah (hemat kuota, cepat di sinyal lemah): sisi terpanjang ≤ 1600 px, JPG ±0,75,
 * target ≤ 500 KB. Didekode langsung dalam ukuran kecil bila browser mendukung agar RAM HP tidak melonjak pada foto 48 MP.
 * Bila gagal (browser lama/format aneh), berkas asli tetap dikirim dan dikecilkan server.
 */
async function shrinkPhoto(file, maxSide = 1600, target = 500 * 1024) {
  if (file.size <= 350 * 1024 || typeof createImageBitmap !== 'function') return file;
  let im = null; const c = document.createElement('canvas');
  try {
    try { im = await createImageBitmap(file, { imageOrientation: 'from-image', resizeWidth: maxSide, resizeQuality: 'high' }); } catch { im = await createImageBitmap(file); }
    const k = Math.min(1, maxSide / Math.max(im.width, im.height));
    c.width = Math.round(im.width * k); c.height = Math.round(im.height * k);
    const g = c.getContext('2d'); g.fillStyle = '#fff'; g.fillRect(0, 0, c.width, c.height); g.drawImage(im, 0, 0, c.width, c.height);
    let best = null;
    for (const q of [0.78, 0.68, 0.58]) { const b = await new Promise((r) => c.toBlob(r, 'image/jpeg', q)); if (b) best = b; if (b && b.size <= target) break; }
    if (!best || best.size >= file.size) return file;
    return new File([best], (file.name || 'tugas').replace(/\.[^.]+$/, '') + '.jpg', { type: 'image/jpeg' });
  } catch { return file; }
  finally { im?.close?.(); c.width = c.height = 0; }
}

async function openInNewTab(getUrl) {
  const w = window.open('about:blank', '_blank');
  try { const url = await getUrl(); if (w) w.location = url; else location.href = url; } catch (x) { w?.close(); toast(x.message, true); }
}

// ══════════════════════════ ABSENSI GURU ══════════════════════════
const STATUS_TONE = { hadir: 'ok', telat: 'wr', belum: 'wr', tidak: 'er', jadwal: 'mu' };

async function teacherSelf(box) {
  const m = await api('teacher-attendance/me');
  const ok = (b) => `<span class="d ${b ? 'ok' : 'er'}">${b ? '✓' : '!'}</span>`;
  const online = navigator.onLine && !S.offline;
  const done = m.blocks.filter((b) => b.entry).length;
  box.innerHTML = `
    <div class="card"><div class="steps2">
      <span class="pl ${m.check_in ? 'ok' : 'er'}">① Hadir di sekolah ${m.check_in ? '✓ ' + esc(m.check_in.time) : '· belum'}</span><span class="mu">→</span>
      <span class="pl ${!m.check_in ? 'mu' : m.blocks.length && done === m.blocks.length ? 'ok' : 'wr'}">② Masuk kelas ${!m.check_in ? '(terkunci)' : done + '/' + m.blocks.length}</span></div>
      <p class="mu" style="margin-top:8px">Wajib dua langkah setiap hari: absen kehadiran di sekolah, lalu absen masuk kelas sesuai jadwal. Langkah ② terbuka setelah langkah ①. Terlambat bila lewat ${m.late_minutes} menit dari jam mulai.</p>
      <div class="checks" style="margin-top:10px">
        <div class="check-row">${ok(online)}<span>${online ? 'Tersambung ke internet' : 'Tidak ada internet — absensi wajib online'}</span></div>
        <div class="check-row">${ok(m.on_school_network)}<span>${m.on_school_network ? 'Tersambung ke WiFi sekolah' : 'Sambungkan HP ke WiFi sekolah dulu'}</span></div>
        <div class="check-row">${ok(m.on_registered_device)}<span>${m.on_registered_device ? 'HP ini terdaftar untuk akunmu' : 'HP ini belum terdaftar. Keluar lalu masuk lagi dari HP-mu.'}</span></div>
      </div></div>
    <div class="card"><b>① Kehadiran di sekolah</b>
      ${m.check_in ? `<div class="alert ok" style="margin-top:10px">Hadir pukul <b>${esc(m.check_in.time)}</b>${m.check_in.method === 'manual' ? ' (dicatat admin)' : ''}.</div>` : !m.is_school_day ? `<p class="mu" style="margin-top:8px">Hari ini bukan hari sekolah.</p>` : `
        <p class="mu" style="margin:4px 0 10px">Scan <b>QR Kehadiran Guru</b> yang ditempel di ruang guru / gerbang.</p>
        <div id="gcam"></div><button class="btn" id="gscan" type="button" ${online ? '' : 'disabled'}>${ti('qrcode')} Scan QR kehadiran</button>
        <details style="margin-top:12px"><summary class="linkbtn" style="font-size:13px">Kamera bermasalah? Ketik kode di bawah QR</summary>
          <form class="row" id="gform" style="margin-top:8px"><div><label for="gcode">Kode QR kehadiran</label><input id="gcode" autocapitalize="characters" autocomplete="off" placeholder="MPG-…" maxlength="40" required></div><button class="btn nf">Kirim</button></form></details>`}
    </div>
    <div class="card"><b>② Masuk kelas hari ini (sesuai jadwal)</b><div style="margin-top:10px">
      ${m.blocks.length ? m.blocks.map((b) => `<div class="lesson blk"><div class="tm">${esc(b.from || '')}–${esc(b.to || '')}<small>Jam ke-${b.period_start}${b.period_end > b.period_start ? '–' + b.period_end : ''}</small></div>
        <div><div style="display:flex;flex-wrap:wrap;justify-content:space-between;align-items:center;gap:6px"><b>${esc(b.classroom?.name)}</b>${pl(b.status_label, b.status_tone)}</div>
          <div class="mu">${esc(b.subject)}${b.entry ? ' · masuk ' + esc(b.entry.time) + (b.entry.method === 'manual' ? ' (manual)' : '') : ''}</div>
          ${b.entry ? '' : !m.check_in ? '<button class="btn o sm" type="button" disabled title="Absen kehadiran di sekolah dulu">Absen sekolah dulu</button>'
            : (b.status === 'jadwal' || b.status === 'belum') ? `<button class="btn sm" type="button" data-enter="${b.schedule_id}" data-kelas="${esc(b.classroom?.name)}">${ti('qrcode')} Scan QR kelas</button>` : ''}</div></div>`).join('')
        : empty(m.is_school_day ? 'Tidak ada jadwal mengajar hari ini.' : 'Hari ini bukan hari sekolah.')}</div></div>
    <div class="card"><b>Riwayat kehadiran saya</b><div style="margin-top:10px">${table(['Tanggal', 'Jenis', 'Waktu', 'Status'],
      m.history.map((h) => [fd(h.date), esc(h.kind), esc(h.time) + (h.method === 'manual' ? ' <span class="mu">manual</span>' : ''), pl(h.status === 'telat' ? 'Terlambat' : 'Hadir', h.status === 'telat' ? 'wr' : 'ok')]), 'Belum ada riwayat.')}</div></div>`;

  const checkIn = async (code) => {
    stopCamera();
    try { const r = await api('teacher-attendance/check-in', { method: 'POST', body: { code: code.trim() } }); toast(r.message); navigator.vibrate?.(120); render(); }
    catch (x) { toast(x.message, true); const b = $('#gscan', box); if (b) b.disabled = false; }
  };
  const gs = $('#gscan', box);
  if (gs) gs.onclick = async () => { gs.disabled = true; try { await startCamera($('#gcam', box), checkIn, { pattern: /^MPG-\d+-/i, hint: 'Arahkan kamera ke QR Kehadiran Guru.' }); } catch (x) { toast(x.message, true); gs.disabled = false; } };
  const gf = $('#gform', box); if (gf) gf.onsubmit = (e) => { e.preventDefault(); checkIn($('#gcode', box).value); };

  $$('[data-enter]', box).forEach((b) => (b.onclick = () => {
    const id = +b.dataset.enter;
    const d = dialog('Scan QR kelas ' + b.dataset.kelas, `<div id="ecam"></div>
      <form class="row" id="eform" style="margin-top:10px"><div><label for="ecode">atau ketik kode QR kelas</label><input id="ecode" autocapitalize="characters" autocomplete="off" placeholder="MP-…" maxlength="40" required></div><button class="btn nf">Kirim</button></form>`, async (dlg) => {
      const send = async (code) => {
        stopCamera();
        try { const r = await api('teacher-attendance/enter', { method: 'POST', body: { schedule_id: id, code: code.trim() } }); dlg.close(); toast(r.message); navigator.vibrate?.(120); render(); }
        catch (x) { toast(x.message, true); }
      };
      $('#eform', dlg).onsubmit = (e) => { e.preventDefault(); send($('#ecode', dlg).value); };
      try { await startCamera($('#ecam', dlg), send, { hint: 'Arahkan kamera ke QR kelas ' + b.dataset.kelas + '.' }); } catch (x) { $('#ecam', dlg).innerHTML = `<p class="mu">${esc(x.message)}</p>`; }
    });
    d.addEventListener('close', stopCamera);
  }));
}

async function teacherMonitor(box, q) {
  const date = q.tgl || todayStr(), view = q.v || 'rekap';
  const [r, gate] = await Promise.all([api('teacher-attendance?date=' + date), api('teacher-attendance/gate').catch(() => null)]);
  const s = r.summary;
  box.innerHTML = `
    <div class="gr">
      <div class="card"><div class="mu">Guru hadir di sekolah</div><div class="big">${s.checked_in}/${s.teachers}</div><div class="bar"><i style="width:${s.teachers ? s.checked_in / s.teachers * 100 : 0}%"></i></div></div>
      <div class="card"><div class="mu">Masuk kelas</div><div class="big">${s.entered}/${s.blocks}</div><div class="mu">${s.blocks} blok jadwal</div></div>
      <div class="card"><div class="mu">Terlambat</div><div class="big">${s.telat}</div></div>
      <div class="card"><div class="mu">Belum masuk</div><div class="big">${s.belum}</div></div>
      <div class="card"><div class="mu">Tidak masuk</div><div class="big" style="color:${s.tidak ? 'var(--er)' : 'inherit'}">${s.tidak}</div></div>
    </div>
    <div class="card">
      <div class="tabs" role="tablist" style="margin-bottom:14px">${[['rekap', 'Rekap per guru', 'users'], ['kelas', 'Masuk per jadwal', 'calendar-week']].map(([v, l, i]) => `<button type="button" role="tab" data-tab="${v}" aria-selected="${view === v}" class="${view === v ? 'on' : ''}">${ti(i)} ${l}</button>`).join('')}</div>
      <div class="row" style="align-items:end;gap:10px">
        <div style="flex:none;width:200px;min-width:0"><label for="gdt">Tanggal</label><input id="gdt" type="date" value="${esc(date)}" max="${todayStr()}"></div>
        <button class="btn o nf" id="gtoday" type="button" style="flex:none;min-width:0">${ti('calendar')} Hari ini</button></div>
      <p class="mu" style="margin-top:8px">Dua langkah: (1) hadir di sekolah lewat QR Kehadiran Guru, (2) masuk kelas sesuai jadwal lewat QR kelas. Langkah 2 hanya terbuka setelah langkah 1; terlambat bila lewat 10 menit. Guru yang tidak scan sampai jam pelajaran selesai dihitung tidak masuk.</p></div>
    <div class="card">${view === 'rekap'
      ? `<b>Rekap kehadiran guru · ${fd(date)}</b><div style="margin-top:10px">${table(['Guru', 'Mapel', 'Hadir sekolah', 'Jadwal', 'Masuk', 'Terlambat', 'Belum', 'Tidak', 'Status'], r.teachers.map((t) => [
          withAvatar(t.teacher.name, t.teacher.photo_url, esc(t.teacher.name)), esc(t.teacher.subject || '-'),
          t.check_in ? esc(t.check_in.time) + (t.check_in.method === 'manual' ? ' <span class="mu">manual</span>' : '') : `<button class="btn o sm" type="button" data-man="${t.teacher.id}" data-name="${esc(t.teacher.name)}">Catat hadir</button>`,
          t.blocks ? `${t.blocks} blok · ${t.hours} jam` : '-', t.hadir + t.telat, t.telat, t.belum, t.tidak, pl(t.state[0], t.state[1])]), 'Belum ada guru.')}</div>`
      : `<b>Masuk kelas sesuai jadwal · ${fd(date)}</b><div style="margin-top:10px">${table(['Jam ke-', 'Waktu', 'Kelas', 'Mapel', 'Guru', 'Masuk', 'Status', ''], r.blocks.map((b) => [
          b.period_start + (b.period_end > b.period_start ? '–' + b.period_end : ''), esc((b.from || '') + '–' + (b.to || '')), esc(b.classroom?.name), esc(b.subject), esc(b.teacher?.name),
          b.entry ? esc(b.entry.time) + (b.entry.method === 'manual' ? ' <span class="mu">manual</span>' : '') : '-', pl(b.status_label, b.status_tone),
          b.entry || b.status === 'jadwal' ? '' : `<button class="btn o sm" type="button" data-ment="${b.schedule_id}">Catat masuk</button>`]),
          r.is_school_day ? 'Tidak ada jadwal mengajar pada tanggal ini.' : 'Bukan hari sekolah.')}</div>`}</div>
    ${gate ? `<div class="card"><div class="pf" style="align-items:center"><img src="/api/teacher-attendance/gate.svg?t=${Date.now()}" alt="QR Kehadiran Guru" width="150" height="150" style="border-radius:10px;background:#fff">
      <div><b>QR Kehadiran Guru</b><p class="mu" style="margin:4px 0 10px">Cetak dan tempel di ruang guru / gerbang. Guru scan dari aplikasi saat tiba (wajib dari WiFi sekolah).</p><code class="code">${esc(gate.payload)}</code>
      <div class="row" style="margin-top:10px"><a class="btn o nf" href="/cetak-qr?guru=1" target="_blank" rel="noopener">${ti('printer')} Cetak</a><button class="btn o nf" id="grot" type="button">Perbarui QR</button></div></div></div></div>` : ''}`;

  $('#gdt', box).onchange = (e) => go('qr', { t: 'guru', v: view, tgl: e.target.value || todayStr() });
  $('#gtoday', box).onclick = () => go('qr', { t: 'guru', v: view });
  bindTabs(box, 'v');
  $$('[data-man]', box).forEach((b) => (b.onclick = () => busy(b, async () => {
    const x = await api('teacher-attendance/manual', { method: 'POST', body: { teacher_id: +b.dataset.man, date } }); toast(x.message); render();
  })));
  $$('[data-ment]', box).forEach((b) => (b.onclick = () => busy(b, async () => {
    const x = await api('teacher-attendance/manual-enter', { method: 'POST', body: { schedule_id: +b.dataset.ment, date } }); toast(x.message); render();
  })));
  const rot = $('#grot', box);
  if (rot) rot.onclick = () => { if (confirm('Perbarui QR Kehadiran Guru? QR yang sudah ditempel tidak berlaku lagi dan harus dicetak ulang.')) busy(rot, async () => { const x = await api('teacher-attendance/gate/rotate', { method: 'POST' }); toast(x.message); render(); }); };
}

// ══════════════════════════ PEMBIASAAN (CREO & LITERASI) ══════════════════════════
async function pembiasaan(el, q) {
  const kind = q.k === 'literasi' ? 'literasi' : 'creo', title = kind === 'creo' ? 'CREO' : 'Literasi';
  const admin = isAdmin();
  const [items, C, T, INFO, P] = await Promise.all([
    api('habits?kind=' + kind),
    api('habit-coordinators').catch(() => ({ mine: [] })),
    admin ? api('teachers').catch(() => []) : [],
    api('announcements?category=' + kind).catch(() => []),
    import('./pages.js' + new URL(import.meta.url).search), // announcementItem & bilah format B/I/S (sama dengan menu Informasi)
  ]);
  const coord = C[kind];
  const mine = (C.mine || []).includes(kind); // hanya guru koordinator yang mengisi data
  const desc = kind === 'creo' ? 'Program pembiasaan CREO: kegiatan rutin pembentukan karakter siswa.' : 'Program pembiasaan literasi: membaca 15 menit, pojok baca, jurnal literasi.';
  const PROG = [['creo', 'CREO', 'heartbeat'], ['literasi', 'Literasi', 'book-2']];
  const icon = PROG.find(([v]) => v === kind)[2];
  // Tampilan disamakan dengan menu "Agenda": tab berikon, kartu koordinator, formulir, daftar entri.
  el.innerHTML = hdr('Pembiasaan', mine ? `Anda koordinator ${title}: dokumentasi yang Anda unggah dilihat admin, guru, dan siswa` : 'Program CREO dan Literasi · diisi oleh guru koordinator masing-masing program')
    + `<div class="tabs" role="tablist">${PROG.map(([v, l, i]) => `<button type="button" role="tab" data-tab="${v}" aria-selected="${v === kind}" class="${v === kind ? 'on' : ''}">${ti(i)} ${esc(l)}${(C.mine || []).includes(v) ? ' <span class="chip nf" style="font-size:10px">Anda</span>' : ''}</button>`).join('')}</div>`
    + (admin ? `<form class="card coord" id="cf">${coord?.photo_url ? avatar(coord.name, coord.photo_url, 'coord-ic coord-ph') : `<span class="coord-ic">${ti('user')}</span>`}<div class="coord-body"><span class="mu">Koordinator ${title} · yang mengisi data pembiasaan ini</span>
        <b>${coord ? esc(coord.name) : '<span class="mu">Belum dipilih</span>'}</b></div>
        <select name="teacher_id" aria-label="Pilih koordinator ${title}"><option value="">— Pilih guru —</option>${T.map((t) => `<option value="${t.id}" ${coord?.id === t.id ? 'selected' : ''}>${esc(t.name)}</option>`).join('')}</select>
        <button class="btn o">Simpan koordinator</button></form>`
      : `<div class="card coord">${coord?.photo_url ? avatar(coord.name, coord.photo_url, 'coord-ic coord-ph') : `<span class="coord-ic">${ti('user')}</span>`}<div class="coord-body"><span class="mu">Koordinator ${title}</span><b>${coord ? esc(coord.name) : '<span class="mu">Belum ditentukan admin</span>'}</b></div></div>`)
    + (admin && !mine ? `<div class="alert in">Admin menentukan koordinator. Dokumentasi ${title} diisi oleh guru koordinatornya; admin, guru, dan siswa melihat.</div>` : '')
    // ── Kolom informasi program (sama dengan menu Informasi): koordinator menulis, semua peran membaca ──
    + (mine ? `<form class="card" id="pif"><b>${ti('speakerphone')} Kirim informasi ${title}</b><p class="mu" style="margin:2px 0 0">Pengumuman kegiatan ${title}: jadwal, petugas, tema pekan ini. Tampil di dashboard dan menu Pembiasaan.</p>
        <input type="hidden" name="category" value="${kind}">
        <div class="row"><div style="flex:3"><label for="piT">Judul</label><input id="piT" name="title" required maxlength="120" placeholder="${kind === 'creo' ? 'Mis. Jadwal kegiatan CREO pekan ini' : 'Mis. Tema literasi bulan November'}"></div>
        <div><label for="piA">Tujuan</label><select id="piA" name="audience"><option value="semua">Guru dan siswa</option><option value="guru">Guru saja</option><option value="siswa">Siswa saja</option></select></div></div>
        <div class="fmt-head"><label for="pib">Isi informasi</label><span class="fmt-bar" role="toolbar" aria-label="Format teks">
          <button type="button" data-fmt="*" title="Tebal (*teks*)" aria-label="Tebal"><b>B</b></button><button type="button" data-fmt="_" title="Miring (_teks_)" aria-label="Miring"><i>I</i></button><button type="button" data-fmt="~" title="Coret (~teks~)" aria-label="Coret"><s>S</s></button></span></div>
        <textarea id="pib" name="body" rows="4" required maxlength="3000" placeholder="Blok teks lalu tekan B / I, atau ketik *tebal* dan _miring_ seperti di WhatsApp."></textarea>
        <div class="fmt-prev" id="pibp" hidden><span class="mu">Pratinjau:</span><div class="prose"></div></div>
        <button class="btn" style="margin-top:12px">Kirim informasi</button></form>` : '')
    + `<div class="card"><b>Informasi ${title}</b><div class="list" style="margin-top:10px">${INFO.length
      ? INFO.map((a) => `<div class="info-row">${P.announcementItem(a, true)}<span class="ann-act">${P.announcementActions(a)}</span></div>`).join('')
      : empty('Belum ada informasi ' + title + '.', coord ? 'Informasi ditulis oleh ' + esc(coord.name) + '.' : 'Admin belum menentukan koordinator program ini.')}</div></div>`
    + (mine ? `<form class="card" id="hf"><b>${ti(icon)} Kirim dokumentasi ${title}</b><p class="mu" style="margin:2px 0 0">${desc}</p>
      <div class="row">
        <div style="flex:3;min-width:200px"><label for="ht">Judul / nama kegiatan</label><input id="ht" name="title" maxlength="120" placeholder="Mis. Dokumentasi kegiatan ${title} pekan ini"></div>
        <div style="flex:2;min-width:200px"><label for="hfile">Berkas JPG, PNG, atau PDF</label><input id="hfile" name="file" type="file" accept=".jpg,.jpeg,.png,.pdf,image/jpeg,image/png,application/pdf" required></div></div>
      <p class="mu" style="margin:6px 0 0;font-size:12px">Foto dikecilkan otomatis; PDF maksimal 2 MB.</p>
      <button class="btn" style="margin-top:12px">Kirim dokumentasi</button></form>` : '')
    + `<div class="card"><div class="row" style="align-items:center"><b style="flex:1">Dokumentasi ${title}</b><span class="chip nf" style="flex:none;min-width:0;width:auto">${items.length} berkas</span></div><div class="list" style="margin-top:10px">${items.length
      ? items.map((x) => `<div class="info-row"><div class="item"><div class="grow"><div class="t"><span class="chip ann-cat ann-${kind}">${title}</span> ${esc(x.title)}</div>
          <a class="ann-file" href="${esc(x.url)}" target="_blank" rel="noopener">${ti(x.file_type === 'PDF' ? 'file-type-pdf' : 'eye')} ${x.file_type === 'PDF' ? 'Buka PDF' : 'Lihat foto'}</a>
          <div class="mu" style="font-size:11px;margin-top:4px">${dt(x.created_at)}${x.by ? ' · ' + esc(x.by) : ''}${x.by_student ? ' (kiriman siswa lama)' : ''}</div></div></div>
          ${x.can_delete ? `<button class="linkbtn" data-del="${x.id}" type="button">Hapus</button>` : ''}</div>`).join('')
      : empty('Belum ada dokumentasi ' + title + '.', coord ? 'Dokumentasi diisi oleh ' + esc(coord.name) + '.' : 'Admin belum menentukan koordinator program ini.')}</div></div>`;
  bindTabs(el, 'k');
  const cf = $('#cf', el);
  if (cf) cf.onsubmit = (e) => { e.preventDefault(); busy($('button', cf), async () => { const r = await api('info-coordinators/' + kind, { method: 'PUT', body: { teacher_id: cf.teacher_id.value ? +cf.teacher_id.value : null } }); toast(r.message); render(); }); };
  const hf = $('#hf', el);
  if (hf) hf.onsubmit = (e) => {
    e.preventDefault();
    const fd2 = new FormData(e.target); fd2.append('kind', kind);
    busy($('button', e.target), async () => { const r = await api('habits', { method: 'POST', form: fd2 }); toast(r.message); render(); });
  };
  const pif = $('#pif', el);
  if (pif) {
    P.bindFormat($('#pib', el), $('#pibp', el), el);
    pif.onsubmit = (e) => { e.preventDefault(); busy($('button.btn', pif), async () => { const r = await api('announcements', { method: 'POST', form: new FormData(pif) }); toast(r.message); render(); }); };
  }
  P.bindAnnouncementActions(el, INFO, render);
  $$('[data-del]', el).forEach((b) => (b.onclick = () => { if (confirm('Hapus berkas ini?')) busy(b, async () => { await api('habits/' + b.dataset.del, { method: 'DELETE' }); toast('Berkas dihapus.'); render(); }); }));
}

// ══════════════════════════ MP-LIBRARY ══════════════════════════
// Penampil terlindung (watermark, blokir unduh/salin/cetak, log audit) ada di library-viewer.js.
const LV = () => import('./library-viewer.js' + new URL(import.meta.url).search);
const accessPill = (x) => x.type === 'link' ? pl('Tautan luar', 'mu') : x.allow_download ? pl(`Unduh ≤${x.download_limit}x/hari`, 'wr') : pl('Hanya baca', 'ok');
/** Tautan luar → tab baru (harus dibuka langsung saat klik agar tidak diblokir); berkas → penampil terlindung. */
function openLib(btn, x, onOpened) {
  if (x.type === 'link') return openInNewTab(async () => { const r = await api('library/' + x.id + '/open', { method: 'POST' }); onOpened?.(r); return r.url; });
  busy(btn, async () => (await LV()).openProtected(x.id, x.type, { onOpened }));
}
const fdt = (iso) => new Date(iso).toLocaleString('id-ID', { day: '2-digit', month: 'short', hour: '2-digit', minute: '2-digit' });

async function libraryStaff(el) {
  const [L, classes] = await Promise.all([api('library'), api('classrooms').catch(() => [])]);
  const mp = S.user.teacher?.subject || '';
  el.innerHTML = hdr('MP-Library', 'Perpustakaan digital sekolah. Koleksi dibaca di penampil terlindung ber-watermark; setiap akses tercatat') + `
    <div class="gr"><div class="card"><div class="mu">Total koleksi</div><div class="big">${L.stats.total}</div></div>
      <div class="card"><div class="mu">Total dibuka siswa</div><div class="big">${L.stats.reads}</div></div>
      <div class="card"><div class="mu">Siswa yang aktif membaca</div><div class="big">${L.stats.active_readers}</div></div></div>
    <form class="card" id="lf"><b>Tambah bahan bacaan</b><p class="mu" style="margin:4px 0 10px">Unggah PDF, JPG, atau PNG (PDF maks. 20 MB), atau isi tautan untuk berkas besar seperti Google Drive (tautan luar tidak bisa dilindungi).${isAdmin() ? '' : ' Kamu hanya bisa menghapus koleksi yang kamu tambahkan sendiri.'}</p>
      <div class="row"><div style="flex:3;min-width:200px"><label for="lbJ">Judul</label><input id="lbJ" name="title" maxlength="120" required placeholder="Contoh: Modul Farmakologi Dasar"></div>
        <div style="flex:2;min-width:160px"><label for="lbP">Penulis / sumber</label><input id="lbP" name="author" maxlength="80"></div></div>
      <div class="row"><div><label for="lbC">Kategori</label><select id="lbC" name="category">${L.categories.map((c) => `<option>${esc(c)}</option>`).join('')}</select></div>
        <div><label for="lbM">Mata pelajaran</label><input id="lbM" name="subject" maxlength="60" value="${esc(mp)}" placeholder="Opsional"></div>
        <div><label for="lbK">Tampil untuk kelas</label><select id="lbK" name="classroom_id"><option value="">Semua kelas</option>${classes.map((c) => `<option value="${c.id}">${esc(c.name)}</option>`).join('')}</select></div></div>
      <div class="row"><div style="flex:3;min-width:200px"><label for="lbD">Deskripsi singkat</label><input id="lbD" name="description" maxlength="200" placeholder="Opsional"></div></div>
      <div class="row"><div style="flex:2;min-width:200px"><label for="lbF">Berkas (PDF / JPG / PNG)</label><input id="lbF" name="file" type="file" accept=".pdf,.jpg,.jpeg,.png,application/pdf,image/jpeg,image/png"></div>
        <div style="flex:3;min-width:200px"><label for="lbU">atau tautan (https://…)</label><input id="lbU" name="url" type="url" inputmode="url" placeholder="https://drive.google.com/…"></div></div>
      <div class="row" style="align-items:end"><div style="flex:3;min-width:220px"><label for="lbA">Akses berkas</label><select id="lbA" name="allow_download">
          <option value="0">Hanya baca di aplikasi (disarankan)</option><option value="1">Boleh diunduh (ber-watermark)</option></select></div>
        <div style="flex:1;min-width:120px"><label for="lbL">Batas unduh / hari</label><input id="lbL" name="download_limit" type="number" min="1" max="20" value="3"></div>
        <button class="btn nf">Tambahkan</button></div></form>
    <div class="card"><b>Koleksi MP-Library</b><div style="margin-top:10px">${table(['Judul', 'Kategori', 'Mapel', 'Untuk kelas', 'Format', 'Akses', 'Pengunggah', 'Dibuka siswa', ''],
      L.items.map((x) => [`<b>${esc(x.title)}</b>${x.author ? `<div class="mu">${esc(x.author)}</div>` : ''}`, esc(x.category), esc(x.subject || '-'), esc(x.classroom?.name || 'Semua kelas'),
        esc(x.type_label) + (x.file_size ? ` <span class="mu">${fsz(x.file_size)}</span>` : ''), accessPill(x), esc(x.uploader), x.reads,
        `<div style="display:flex;gap:4px;flex-wrap:wrap;min-width:150px"><button class="btn o sm" type="button" data-open="${x.id}">Buka</button>${x.can_delete ? `${x.type !== 'link' ? `<button class="btn o sm" type="button" data-pol="${x.id}">Akses</button>` : ''}<button class="btn o sm" type="button" data-log="${x.id}">Log</button><button class="btn o sm" type="button" data-del="${x.id}">Hapus</button>` : ''}</div>`]),
      'Belum ada koleksi. Tambahkan bahan bacaan pertama di atas.')}</div></div>
    <div class="card" id="lba"></div>`;
  $('#lf', el).onsubmit = (e) => {
    e.preventDefault();
    const f = new FormData(e.target);
    if (!f.get('file')?.size) f.delete('file');
    if (!f.get('url')) f.delete('url');
    busy($('button.btn:not(.o)', e.target), async () => { const r = await api('library', { method: 'POST', form: f }); toast(r.message); render(); });
  };
  const item = (id) => L.items.find((i) => i.id === +id);
  $$('[data-open]', el).forEach((b) => (b.onclick = () => openLib(b, item(b.dataset.open))));
  $$('[data-del]', el).forEach((b) => (b.onclick = () => { if (confirm('Hapus bahan bacaan ini dari MP-Library?')) busy(b, async () => { await api('library/' + b.dataset.del, { method: 'DELETE' }); toast('Dihapus.'); render(); }); }));
  $$('[data-pol]', el).forEach((b) => (b.onclick = () => {
    const x = item(b.dataset.pol);
    dialog('Akses: ' + x.title, `<form id="pf"><p class="mu">Semua pembaca melihat watermark nama, NIS/peran, sekolah, waktu, dan kode jejak. Setiap pembukaan dan unduhan tercatat di log audit.</p>
      <label><input type="radio" name="dl" value="0" ${x.allow_download ? '' : 'checked'}> <b>Hanya baca di aplikasi</b><div class="mu" style="margin:2px 0 8px 24px">Tidak ada tombol unduh. Disarankan untuk modul dan buku.</div></label>
      <label><input type="radio" name="dl" value="1" ${x.allow_download ? 'checked' : ''}> <b>Boleh diunduh</b><div class="mu" style="margin:2px 0 8px 24px">Berkas unduhan dicap watermark di setiap halaman.</div></label>
      <label for="pl2">Batas unduh per orang per hari</label><input id="pl2" name="limit" type="number" min="1" max="20" value="${x.download_limit || 3}">
      <div class="row" style="margin-top:12px"><button class="btn nf">Simpan</button></div></form>`, (d) => {
      $('#pf', d).onsubmit = (e) => {
        e.preventDefault();
        busy($('button', e.target), async () => {
          const r = await api('library/' + x.id + '/policy', { method: 'PUT', body: { allow_download: e.target.dl.value === '1', download_limit: +e.target.limit.value || 3 } });
          toast(r.message); d.close(); render();
        });
      };
    });
  }));
  $$('[data-log]', el).forEach((b) => (b.onclick = () => { auditCard($('#lba', el), { item: b.dataset.log, title: item(b.dataset.log).title }); $('#lba', el).scrollIntoView({ behavior: 'smooth' }); }));
  auditCard($('#lba', el), {});
}

/** Log audit & keamanan MP-Library: admin semua koleksi, guru koleksi miliknya. */
async function auditCard(box, f) {
  const qs = new URLSearchParams();
  if (f.item) qs.set('item', f.item);
  if (f.trace) qs.set('trace', f.trace);
  if (f.suspicious) qs.set('suspicious', '1');
  box.innerHTML = '<b>Log audit & keamanan</b><p class="mu">Memuat…</p>';
  let A;
  try { A = await api('library-audit?' + qs); } catch (x) { box.innerHTML = `<b>Log audit & keamanan</b><div class="alert er">${esc(x.message)}</div>`; return; }
  const s = A.summary;
  box.innerHTML = `<div class="row" style="justify-content:space-between;align-items:center"><div><b>Log audit & keamanan</b><div class="mu">${f.item ? 'Koleksi: ' + esc(f.title || '') : isAdmin() ? 'Semua koleksi sekolah' : 'Koleksi yang kamu unggah'} · 7 hari terakhir</div></div>
      <a class="btn o sm nf" href="/api/library-audit/export?${qs}">${ti('download')} Ekspor Excel</a></div>
    <div class="gr" style="margin:10px 0"><div class="card"><div class="mu">Dibuka</div><div class="big">${s.opens_7d}</div></div><div class="card"><div class="mu">Pembaca unik</div><div class="big">${s.readers_7d}</div></div>
      <div class="card"><div class="mu">Unduhan</div><div class="big">${s.downloads_7d}</div></div><div class="card"><div class="mu">Percobaan mencurigakan</div><div class="big" style="color:${s.suspicious_7d ? 'var(--er,#dc2626)' : 'inherit'}">${s.suspicious_7d}</div></div></div>
    <form class="row" id="laf" style="align-items:end"><div style="flex:2;min-width:170px"><label for="laT">Lacak kode jejak (dari watermark bocoran)</label><input id="laT" name="trace" maxlength="8" placeholder="Contoh: K7Q2MX9A" value="${esc(f.trace || '')}" style="text-transform:uppercase"></div>
      <label style="flex:none;display:flex;gap:6px;align-items:center"><input type="checkbox" name="sus" ${f.suspicious ? 'checked' : ''}> Hanya mencurigakan</label>
      <button class="btn o nf">Terapkan</button>${f.item || f.trace || f.suspicious ? '<button class="btn o nf" type="button" id="lar">Reset</button>' : ''}</form>
    <div style="margin-top:10px">${table(['Waktu', 'Koleksi', 'Pengguna', 'Peristiwa', 'Kode jejak', 'Keterangan', 'Perangkat'],
      A.logs.map((l) => [fdt(l.at), esc(l.item), `${esc(l.user)} <span class="mu">${esc(l.role)}</span>`, pl(l.label, l.suspicious ? 'er' : l.event === 'download' ? 'wr' : 'mu'),
        l.trace ? `<code>${esc(l.trace)}</code>` : '-', esc(l.detail || '-'), esc(l.device || '-') + (l.ip ? `<div class="mu">${esc(l.ip)}</div>` : '')]),
      'Belum ada aktivitas tercatat.')}</div>`;
  $('#laf', box).onsubmit = (e) => { e.preventDefault(); auditCard(box, { ...f, trace: e.target.trace.value.trim().toUpperCase(), suspicious: e.target.sus.checked }); };
  const rs = $('#lar', box); if (rs) rs.onclick = () => auditCard(box, {});
}

async function libraryStudent(el) {
  const L = await api('library');
  let qtext = '', cat = '', view = 'all';
  el.innerHTML = hdr('MP-Library', 'Perpustakaan digital sekolah. Bahan bacaan ditambahkan guru dan admin untuk kelasmu') + `
    <div class="gr"><div class="card"><div class="mu">Koleksi untukmu</div><div class="big">${L.stats.total}</div></div>
      <div class="card"><div class="mu">Sudah kamu buka</div><div class="big" id="lbo">${L.stats.opened}</div></div>
      <div class="card"><div class="mu">Tersimpan</div><div class="big" id="lbf">${L.stats.favorites}</div></div></div>
    <div class="card"><div class="row" style="align-items:end">
      <div style="flex:3;min-width:200px"><label for="lbS">Cari bacaan</label><input id="lbS" type="search" placeholder="Judul, penulis, atau mapel"></div>
      <div style="flex:2;min-width:160px"><label for="lbC">Kategori</label><select id="lbC"><option value="">Semua kategori</option>${L.categories.map((c) => `<option>${esc(c)}</option>`).join('')}</select></div>
      <div style="flex:none">${tabs([['all', 'Semua'], ['fav', 'Tersimpan']], 'all')}</div></div></div>
    <div id="lbL"></div>`;
  const draw = () => {
    const t = qtext.toLowerCase().trim();
    const R = L.items.filter((x) => (view !== 'fav' || x.favorite) && (!cat || x.category === cat) && (!t || [x.title, x.author, x.subject, x.description, x.category].join(' ').toLowerCase().includes(t)));
    $('#lbL', el).innerHTML = R.length ? '<div class="fg">' + R.map((x) => `<div class="card book">
        <div class="chips">${pl(x.category, 'mu')}${x.subject ? pl(x.subject, 'mu') : ''}${x.opened ? pl('Sudah dibuka', 'ok') : ''}</div>
        <b class="bt">${esc(x.title)}</b>${x.author ? `<div class="mu">${esc(x.author)}</div>` : ''}${x.description ? `<div class="mu">${esc(x.description)}</div>` : ''}
        <div class="mu" style="font-size:12px">Oleh ${esc(x.uploader)} · ${esc(x.type_label)}${x.classroom ? ' · kelas ' + esc(x.classroom.name) : ''}${x.type !== 'link' ? ' · ' + (x.allow_download ? 'boleh diunduh' : 'hanya baca') : ''}</div>
        <div class="row" style="gap:6px;margin-top:auto"><button class="btn sm nf" type="button" data-open="${x.id}">${x.type === 'link' ? 'Buka tautan' : 'Baca'}</button>
          <button class="btn o sm nf" type="button" data-fav="${x.id}" aria-pressed="${x.favorite}">${x.favorite ? '★ Tersimpan' : '☆ Simpan'}</button></div></div>`).join('') + '</div>'
      : `<div class="card">${empty(L.items.length ? 'Tidak ada yang cocok' : 'Belum ada koleksi', L.items.length ? 'Coba kata kunci, kategori, atau tab lain.' : 'Guru dan admin belum menambahkan bahan bacaan untuk kelasmu.')}</div>`;
    $$('[data-open]', el).forEach((b) => (b.onclick = () => {
      const x = L.items.find((i) => i.id === +b.dataset.open);
      openLib(b, x, () => { if (!x.opened) { x.opened = true; $('#lbo', el).textContent = L.items.filter((i) => i.opened).length; draw(); } });
    }));
    $$('[data-fav]', el).forEach((b) => (b.onclick = () => busy(b, async () => {
      const r = await api('library/' + b.dataset.fav + '/favorite', { method: 'POST' });
      L.items.find((i) => i.id === +b.dataset.fav).favorite = r.favorite; $('#lbf', el).textContent = L.items.filter((i) => i.favorite).length; toast(r.message); draw();
    })));
  };
  $('#lbS', el).oninput = (e) => { qtext = e.target.value; draw(); };
  $('#lbC', el).onchange = (e) => { cat = e.target.value; draw(); };
  $$('[data-tab]', el).forEach((b) => (b.onclick = () => { view = b.dataset.tab; $$('[data-tab]', el).forEach((x) => { x.classList.toggle('on', x === b); x.setAttribute('aria-selected', x === b); }); draw(); }));
  draw();
}

// ══════════════════════════ CBT ══════════════════════════
// Modul terpisah (cbt.js), dimuat saat menu CBT dibuka.
const CBT_V = new URL(import.meta.url).search;
const cbt = async (el, q) => (await import('./cbt.js' + CBT_V)).cbtStaff(el, q);
const cbtStudent = async (el, q) => (await import('./cbt.js' + CBT_V)).cbtStudent(el, q);

// ══════════════════════════ LEGER RAPOR ══════════════════════════
const SHEETS = [['data', 'DATA'], ['capaian', 'CAPAIAN MATERI'], ['nilai', 'nilai'], ['raport', 'RAPORT']];
const num = (v) => v === null || v === undefined ? '' : String(v).replace('.', ',');

async function legerSheet(box, classroomId, q) {
  const L = await api('leger/classes/' + classroomId);
  const st = L.structure, ed = L.editable, sh = q.sh || 'nilai', rows = L.rows;
  const flow = `<p class="mu" style="margin:4px 0 12px">${SHEETS.map((s) => '<b>' + s[1] + '</b>').join(' → ')} · DATA diisi otomatis dari data sekolah, tiga lembar lain mengikuti. ${ed ? 'Perubahan tersimpan otomatis.' : 'Tampilan baca saja.'}</p>`;
  const save = async (r, body, input) => {
    try {
      const x = await api(`leger/classes/${classroomId}/students/${r.student.id}`, { method: 'PUT', body });
      x.ranks.forEach((k) => { const a = $(`#av${k.id}`, box), s = $(`#sm${k.id}`, box), rk = $(`#rk${k.id}`, box); if (a) a.textContent = k.average ?? '-'; if (s) s.textContent = k.sum ?? '-'; if (rk) rk.textContent = k.rank ?? '-'; });
      input?.classList.remove('bad');
    } catch (e) { toast(e.message, true); input?.classList.add('bad'); }
  };
  let body = '';
  if (!rows.length) body = `<div class="card">${empty('Kelas ini belum punya siswa.')}</div>`;
  else if (sh === 'data') {
    body = `<div class="card">${table(['Data', 'Isi'], [['Sekolah', esc(L.school.name)], ['Alamat', esc(L.school.address || '-')], ['Kelas', esc(L.classroom.name)], ['Fase', L.classroom.phase],
      ['Semester', esc(L.settings.semester)], ['Tahun ajaran', esc(L.settings.year)], ['Wali kelas', esc(L.homeroom || '-')], ['Kepala sekolah', esc(L.settings.principal_name || '-')]])}</div>
      <div class="card"><b>Siswa kelas ${esc(L.classroom.name)}</b><div style="margin-top:10px">${table(['No', 'NISN', 'Nama'], rows.map((r, i) => [i + 1, esc(r.student.nisn || r.student.nis || '-'), esc(r.student.name)]))}</div></div>`;
  } else if (sh === 'capaian') {
    const m = Math.min(+(q.m || 0), st.subjects.length - 1);
    body = `<div class="card"><div class="row" style="align-items:end"><div style="max-width:380px"><label for="lgm">Mata pelajaran</label><select id="lgm">${st.subjects.map((s, j) => `<option value="${j}" ${j === m ? 'selected' : ''}>${j + 1}. ${esc(s.name)}</option>`).join('')}</select></div>
      ${ed ? '<button class="btn o nf" id="lgall" type="button">Terapkan teks siswa pertama ke semua</button>' : ''}</div>
      <div style="margin-top:12px">${table(['No', 'Nama', 'Sangat dipahami', 'Belum dipahami'], rows.map((r, i) => [i + 1, esc(r.student.name),
        `<textarea rows="3" data-cp="${i}" data-z="0" ${ed ? '' : 'disabled'}>${esc(r.competencies[m][0])}</textarea>`, `<textarea rows="3" data-cp="${i}" data-z="1" ${ed ? '' : 'disabled'}>${esc(r.competencies[m][1])}</textarea>`]))}</div></div>`;
    setTimeout(() => {
      $('#lgm', box).onchange = (e) => go('leger', { ...S.query, sh: 'capaian', m: e.target.value });
      $$('[data-cp]', box).forEach((t) => (t.onchange = () => { const r = rows[+t.dataset.cp]; r.competencies[m][+t.dataset.z] = t.value; save(r, { competencies: r.competencies }, t); }));
      const all = $('#lgall', box); if (all) all.onclick = () => busy(all, async () => { const x = await api(`leger/classes/${classroomId}/apply-competency`, { method: 'POST', body: { subject: m } }); toast(x.message); render(); });
    });
  } else if (sh === 'nilai') {
    const short = (s) => s.length > 18 ? s.slice(0, 17) + '…' : s;
    body = `<div class="card"><b>Nilai akhir per mata pelajaran</b><p class="mu" style="margin:4px 0 10px">Isi 0 sampai 100. Rata-rata, jumlah, dan ranking dihitung otomatis.</p>
      ${table(['No', 'Nama', ...st.subjects.map((s, j) => `<span title="${esc(s.name)}">${j + 1}. ${esc(short(s.name))}</span>`), 'Rata-rata', 'Jumlah', 'Rank'], rows.map((r, i) => [i + 1, esc(r.student.name),
        ...st.subjects.map((_, j) => `<input class="ns" data-sc="${i}" data-j="${j}" value="${num(r.scores[j])}" inputmode="decimal" ${ed ? '' : 'disabled'} aria-label="Nilai ${esc(r.student.name)} mapel ${j + 1}">`),
        `<span id="av${r.student.id}">${r.average ?? '-'}</span>`, `<span id="sm${r.student.id}">${r.sum ?? '-'}</span>`, `<b id="rk${r.student.id}">${r.rank ?? '-'}</b>`]))}</div>
      <div class="card"><b>Ekstrakurikuler dan ketidakhadiran</b><p class="mu" style="margin:4px 0 10px">Ekstrakurikuler diisi keterangan (misalnya A atau B); kosongkan bila tidak ikut. Ketidakhadiran dalam hari.</p>
      ${table(['No', 'Nama', ...st.extracurriculars.map(esc), 'Sakit', 'Izin', 'Tanpa ket.'], rows.map((r, i) => [i + 1, esc(r.student.name),
        ...st.extracurriculars.map((_, j) => `<input class="ns" style="width:52px" data-ex="${i}" data-j="${j}" value="${esc(r.extras[j])}" maxlength="20" ${ed ? '' : 'disabled'}>`),
        ...[0, 1, 2].map((j) => `<input class="ns" style="width:52px" data-hd="${i}" data-j="${j}" value="${r.absences[j] || ''}" inputmode="numeric" ${ed ? '' : 'disabled'}>`)]))}</div>`;
    setTimeout(() => {
      $$('[data-sc]', box).forEach((t) => (t.onchange = () => { const r = rows[+t.dataset.sc]; r.scores[+t.dataset.j] = t.value.trim() === '' ? null : t.value.trim().replace(',', '.'); save(r, { scores: r.scores }, t); }));
      $$('[data-ex]', box).forEach((t) => (t.onchange = () => { const r = rows[+t.dataset.ex]; r.extras[+t.dataset.j] = t.value.trim(); save(r, { extras: r.extras }, t); }));
      $$('[data-hd]', box).forEach((t) => (t.onchange = () => { const r = rows[+t.dataset.hd]; r.absences[+t.dataset.j] = +t.value || 0; save(r, { absences: r.absences }, t); }));
    });
  } else {
    const i = Math.min(+(q.s || 0), rows.length - 1), r = rows[i], nm = esc(r.student.name);
    const ex = st.extracurriculars.map((e, j) => r.extras[j] ? esc(e) + ': ' + esc(r.extras[j]) : '').filter(Boolean);
    body = `<div class="card"><div class="row" style="align-items:end"><div style="max-width:380px"><label for="lgs">Siswa</label><select id="lgs">${rows.map((x, k) => `<option value="${k}" ${k === i ? 'selected' : ''}>${k + 1}. ${esc(x.student.name)}</option>`).join('')}</select></div>
      <a class="btn nf" href="/rapor/${classroomId}?siswa=${r.student.id}" target="_blank" rel="noopener">${ti('printer')} Cetak rapor siswa ini</a><a class="btn o nf" href="/rapor/${classroomId}" target="_blank" rel="noopener">Cetak semua (${rows.length})</a></div>
      <div class="row" style="margin-top:8px;align-items:center"><span class="mu" style="flex:1;min-width:200px">${ti('id-badge-2')} Halaman identitas (cover) buku rapor:</span><button class="btn o nf" type="button" id="lgbio">Biodata siswa ini</button><a class="btn o nf" href="/biodata/cetak?siswa=${r.student.id}" target="_blank" rel="noopener">${ti('printer')} Cetak biodata</a><a class="btn o nf" href="/biodata/cetak?kelas=${classroomId}" target="_blank" rel="noopener">Cetak biodata semua</a></div></div>
      <div class="card rapor"><div style="text-align:center;margin-bottom:12px"><b>LAPORAN HASIL BELAJAR (RAPOR)</b></div>
        <dl class="rid">${[['Nama Peserta Didik', nm], ['NISN', esc(r.student.nisn || r.student.nis || '-')], ['Sekolah', esc(L.school.name)], ['Kelas / Fase', esc(L.classroom.name) + ' / ' + L.classroom.phase], ['Semester / Tahun Pelajaran', esc(L.settings.semester) + ' / ' + esc(L.settings.year)]].map(([k, v]) => `<dt>${k}</dt><dd>${v}</dd>`).join('')}</dl>
        <div class="rsub">${st.subjects.map((s, j) => `<div class="rs"><div class="rs-h"><span>${j + 1}. ${esc(s.name)}</span><b>${r.scores[j] ?? '-'}</b></div>
          <div class="rs-c">${r.competencies[j][0] ? nm + ' menunjukkan pemahaman dalam ' + esc(r.competencies[j][0]) : ''}${r.competencies[j][0] && r.competencies[j][1] ? '<br>' : ''}${r.competencies[j][1] ? nm + ' membutuhkan bimbingan dalam ' + esc(r.competencies[j][1]) : ''}${!r.competencies[j][0] && !r.competencies[j][1] ? '<span class="mu">Capaian belum diisi.</span>' : ''}</div></div>`).join('')}</div>
        <p style="margin-top:12px"><b>Ekstrakurikuler</b><br>${ex.length ? ex.join(' · ') : '-'}</p>
        <p style="margin-top:8px"><b>Ketidakhadiran</b><br>Sakit ${r.absences[0]} hari · Izin ${r.absences[1]} hari · Tanpa keterangan ${r.absences[2]} hari</p></div>`;
    setTimeout(() => {
      $('#lgs', box).onchange = (e) => go('leger', { ...S.query, sh: 'raport', s: e.target.value });
      $('#lgbio', box).onclick = async () => (await import('./biodata.js' + new URL(import.meta.url).search)).biodataDialog(r.student.id, r.student.name);
    });
  }
  box.innerHTML = flow + tabs(SHEETS, sh) + body;
  bindTabs(box, 'sh');
}

async function leger(el, q) {
  const D = await api('leger');
  if (!isAdmin()) {
    if (!D.classes.length) { el.innerHTML = hdr('Leger Rapor', 'Master dari admin untuk wali kelas') + `<div class="card">${empty('Leger rapor hanya untuk wali kelas', 'Admin menetapkan wali kelas di menu Wali Kelas.')}</div>`; return; }
    const c = D.classes.find((x) => String(x.id) === String(q.kelas)) || D.classes[0];
    el.innerHTML = hdr('Leger Rapor', 'Master dari admin untuk kelas yang Anda walikan', D.classes.length > 1 ? `<select id="lgk" style="max-width:200px">${D.classes.map((x) => `<option value="${x.id}" ${x.id === c.id ? 'selected' : ''}>${esc(x.name)}</option>`).join('')}</select>` : '')
      + `<p class="mu" style="margin-bottom:10px">Kelas <b>${esc(c.name)}</b> · ${esc(D.term)}${c.active ? ' · terdistribusi ' + dt(c.distributed_at) : ''}</p>` + (c.active ? '<div id="lgb"></div>' : `<div class="card">${empty('Belum didistribusikan admin', 'Leger kelas ' + esc(c.name) + ' akan tampil di sini setelah admin mendistribusikannya.')}</div>`);
    const k = $('#lgk', el); if (k) k.onchange = () => go('leger', { kelas: k.value });
    if (c.active) await legerSheet($('#lgb', el), c.id, q);
    return;
  }
  const st = D.settings, dist = D.classes.filter((c) => c.state === 'distributed');
  const STATE = { distributed: ['Terdistribusi', 'ok'], pending: ['Belum', 'wr'], redistribute: ['Perlu distribusi ulang (wali berganti)', 'er'], no_homeroom: ['Belum ada wali', 'mu'] };
  const mon = dist.find((c) => String(c.id) === String(q.kelas)) || dist[0];
  el.innerHTML = hdr('Leger Rapor', 'Master program leger rapor, didistribusikan hanya ke guru wali kelas') + `
    <div class="two">
      <div class="card"><b>Master leger rapor</b><p class="mu" style="margin:4px 0 10px">${SHEETS.map((s) => '<b>' + s[1] + '</b>').join(' → ')}</p>
        <p>📗 ${D.structure.subjects.length} mata pelajaran · ${D.structure.extracurriculars.length} ekstrakurikuler <span class="mu">(${esc(D.structure.source)})</span></p>
        <form class="row" id="lmf" style="margin-top:10px"><div><label for="lmfile">Ganti master (.xlsx, sheet DATA · CAPAIAN MATERI · nilai · RAPORT)</label><input id="lmfile" name="file" type="file" accept=".xlsx" required></div><button class="btn o nf">Unggah</button></form>
        ${st.master_name ? '<button class="linkbtn" id="lmreset" type="button" style="margin-top:8px">Kembali ke struktur bawaan</button>' : ''}</div>
      <form class="card" id="lsf"><b>Periode & penandatangan</b>
        <div class="row"><div><label for="lsm">Semester</label><select id="lsm" name="semester">${['Gasal', 'Genap'].map((x) => `<option ${x === st.semester ? 'selected' : ''}>${x}</option>`).join('')}</select></div>
          <div><label for="lta">Tahun ajaran</label><input id="lta" name="year" value="${esc(st.year)}" placeholder="2026/2027" required></div></div>
        <div class="row"><div><label for="lks">Kepala sekolah</label><input id="lks" name="principal_name" value="${esc(st.principal_name)}" maxlength="120"></div>
          <div><label for="lnip">NIP</label><input id="lnip" name="principal_nip" value="${esc(st.principal_nip)}" maxlength="40"></div>
          <div><label for="lct">Kota (tanggal rapor)</label><input id="lct" name="city" value="${esc(st.city)}" maxlength="60"></div></div>
        <button class="btn" style="margin-top:12px">Simpan pengaturan</button>
        <p class="mu" style="margin-top:8px">Mengganti semester/tahun ajaran membuka leger baru; leger lama tetap tersimpan.</p></form>
    </div>
    <div class="card"><div class="row" style="align-items:center"><b>Distribusi ke wali kelas · ${esc(D.term)}</b><button class="btn nf" id="lgdall" type="button">Distribusikan ke semua wali kelas</button></div>
      <p class="mu" style="margin:4px 0 10px">${D.classes.filter((c) => c.homeroom).length} dari ${D.classes.length} kelas sudah punya wali kelas. Guru non-wali tidak menerima leger.</p>
      ${table(['Kelas', 'Wali kelas', 'Siswa', 'Status', ''], D.classes.map((c) => [`<b>${esc(c.name)}</b>`, esc(c.homeroom?.name || '—'), c.students,
        pl(STATE[c.state][0] + (c.state === 'distributed' ? ' ' + dt(c.distributed_at) : ''), STATE[c.state][1]),
        c.homeroom ? (c.state === 'distributed' ? `<button class="btn o sm" type="button" data-wd="${c.id}">Tarik</button>` : `<button class="btn o sm" type="button" data-ds="${c.id}">Distribusikan</button>`) : '']))}</div>
    <div class="card"><div class="row" style="align-items:center"><b>Pantau leger kelas</b>${dist.length ? `<select id="lgmon" style="max-width:200px;flex:none">${dist.map((c) => `<option value="${c.id}" ${c.id === mon.id ? 'selected' : ''}>${esc(c.name)}</option>`).join('')}</select>` : ''}</div>
      <p class="mu" style="margin:4px 0 10px">Tampilan baca saja; nilai diisi wali kelas di portalnya.</p>${dist.length ? '<div id="lgb"></div>' : empty('Belum ada kelas yang didistribusikan.')}</div>`;
  $('#lmf', el).onsubmit = (e) => { e.preventDefault(); busy($('button', e.target), async () => { const r = await api('leger/master', { method: 'POST', form: new FormData(e.target) }); toast(r.message); render(); }); };
  const rs = $('#lmreset', el); if (rs) rs.onclick = () => busy(rs, async () => { const r = await api('leger/master', { method: 'DELETE' }); toast(r.message); render(); });
  $('#lsf', el).onsubmit = (e) => { e.preventDefault(); busy($('button', e.target), async () => { const r = await api('leger/settings', { method: 'PUT', body: Object.fromEntries(new FormData(e.target)) }); toast(r.message); render(); }); };
  $('#lgdall', el).onclick = (e) => busy(e.target, async () => { const r = await api('leger/distribute', { method: 'POST', body: {} }); toast(r.message); render(); });
  $$('[data-ds]', el).forEach((b) => (b.onclick = () => busy(b, async () => { const r = await api('leger/distribute', { method: 'POST', body: { classroom_id: +b.dataset.ds } }); toast(r.message); render(); })));
  $$('[data-wd]', el).forEach((b) => (b.onclick = () => { if (confirm('Tarik leger dari wali kelas ini? Nilai yang sudah diisi tetap tersimpan.')) busy(b, async () => { const r = await api('leger/distribute/' + b.dataset.wd, { method: 'DELETE' }); toast(r.message); render(); }); }));
  const m = $('#lgmon', el); if (m) m.onchange = () => go('leger', { kelas: m.value });
  if (mon) await legerSheet($('#lgb', el), mon.id, q);
}

// ══════════════════════════ WALI KELAS: JADWAL PIKET & JURNAL KELAS ══════════════════════════
function piketGrid(v, { today = 0, me = 0 } = {}) {
  const days = Object.entries(v.days);
  return `<div class="pkt"><div class="pkt-h">JADWAL PIKET</div><div class="sc"><table class="pkg"><tr>${days.map(([d, n]) => `<th class="${+d === today ? 'td' : ''}">${esc(n.toUpperCase())}</th>`).join('')}</tr>
    ${Array.from({ length: v.rows }, (_, i) => `<tr>${days.map(([d]) => { const p = (v.grid[d] || [])[i]; return `<td class="${[+d === today ? 'td' : '', p && p.id === me ? 'me' : ''].join(' ').trim()}">${p ? esc(p.name) + (p.id === me ? ' ★' : '') : ''}</td>`; }).join('')}</tr>`).join('')}</table></div></div>`;
}

async function rosterCard(box, c) {
  const v = await api('rosters/' + c.id);
  const dayOf = {}; Object.entries(v.grid).forEach(([d, L]) => L.forEach((p) => (dayOf[p.id] = d)));
  box.innerHTML = `<div class="row" style="align-items:center"><b>Jadwal piket ${esc(c.name)}</b>
      <button class="btn o nf" type="button" data-act="rand">Susun acak</button><a class="btn o nf" href="/api/rosters/${c.id}/export">${ti('download')} Unduh Excel</a>
      <label class="btn o nf" style="cursor:pointer">${ti('upload')} Impor Excel<input type="file" accept=".xlsx,.csv" hidden data-act="imp"></label></div>
    <p class="mu" style="margin:4px 0 10px">Format FORMAT_JADWAL_PIKET.xlsx (Senin–Jumat). Bila kolom hari berisi nama siswa, jadwal dipakai apa adanya; bila masih kosong, sistem mengisi acak. ${v.note ? '<b>' + esc(v.note) + '</b>' : ''}</p>
    ${piketGrid(v)}
    <details style="margin-top:10px"><summary class="linkbtn" style="font-size:13px">Atur per siswa</summary>
      <div style="margin-top:8px">${table(['NIS', 'Nama', 'Hari piket'], v.students.map((s) => [esc(s.nis), esc(s.name),
        `<select data-sid="${s.id}" aria-label="Hari piket ${esc(s.name)}"><option value="">—</option>${Object.entries(v.days).map(([d, n]) => `<option value="${d}" ${dayOf[s.id] === d ? 'selected' : ''}>${n}</option>`).join('')}</select>`]))}</div>
      <button class="btn" type="button" data-act="save" style="margin-top:10px">Simpan jadwal piket</button></details>`;
  const reload = () => rosterCard(box, c);
  $('[data-act="rand"]', box).onclick = (e) => busy(e.target, async () => { const r = await api(`rosters/${c.id}/random`, { method: 'POST' }); toast(r.message); reload(); });
  $('[data-act="imp"]', box).onchange = (e) => { const f = new FormData(); f.append('file', e.target.files[0]); busy(null, async () => { const r = await api(`rosters/${c.id}/import`, { method: 'POST', form: f }); toast(r.message); reload(); }); };
  $('[data-act="save"]', box).onclick = (e) => busy(e.target, async () => {
    const grid = Object.fromEntries(Object.keys(v.days).map((d) => [d, []]));
    $$('[data-sid]', box).forEach((s) => { if (s.value) grid[s.value].push(+s.dataset.sid); });
    const r = await api('rosters/' + c.id, { method: 'PUT', body: { grid } }); toast(r.message); reload();
  });
}

async function notesCard(box, c) {
  const N = await api('class-notes?classroom_id=' + c.id);
  box.innerHTML = `<b>Jurnal kelas ${esc(c.name)} (diisi pengurus kelas)</b><div style="margin-top:8px">${N.length ? N.slice(0, 20).map((n) => `<div class="note"><div class="row" style="justify-content:space-between;align-items:baseline"><b>${esc(n.author)} · ${esc(n.role)}</b><span class="mu">${fd(n.date)}</span></div><div class="prose">${esc(n.note)}</div></div>`).join('') : empty('Belum ada catatan dari pengurus kelas.')}</div>`;
}

// ══════════════════════════ HALAMAN STAF ══════════════════════════
export function staffPages(base) {
  return {
    async dash(el, q) {
      await base.dash(el, q);
      const d = await api('dashboard').catch(() => null);
      // Dua render beruntun bisa berbalapan: sisipkan sekali saja.
      if (!d || !el.isConnected || $('#tadash', el)) return;
      $('.gr', el)?.insertAdjacentHTML('beforebegin', '<i id="tadash" hidden></i>');
      if (isAdmin() && d.teacher_attendance) {
        const t = d.teacher_attendance, gr = $('.gr', el);
        gr?.insertAdjacentHTML('beforeend', `<div class="card"><div class="mu">Guru hadir di sekolah</div><div class="big">${t.checked_in}/${t.teachers}</div><div class="bar"><i style="width:${t.teachers ? t.checked_in / t.teachers * 100 : 0}%"></i></div></div>
          <div class="card"><div class="mu">Guru masuk kelas</div><div class="big">${t.entered}/${t.blocks}</div><div class="mu">blok jadwal hari ini</div></div>`);
        gr?.insertAdjacentHTML('afterend', `<div class="card"><div class="row" style="align-items:center"><b>Record absensi guru hari ini</b><a class="btn o nf" href="#/qr?t=guru">Pantau</a></div><div style="margin-top:10px">
          ${table(['Guru', 'Hadir sekolah', 'Masuk kelas', 'Terlambat', 'Belum', 'Tidak masuk', 'Status'], d.teacher_rows.slice(0, 12).map((x) => [withAvatar(x.teacher.name, x.teacher.photo_url, esc(x.teacher.name)),
            x.check_in ? esc(x.check_in.time) : '<span class="mu">Belum</span>', (x.hadir + x.telat) + '/' + x.blocks, x.telat, x.belum, x.tidak, pl(x.state[0], x.state[1])]), 'Belum ada jadwal mengajar hari ini.')}
          ${d.teacher_rows.length > 12 ? `<p class="mu" style="margin-top:6px">dan ${d.teacher_rows.length - 12} guru lainnya di menu Absensi.</p>` : ''}</div></div>`);
      } else if (S.user.role === 'teacher') {
        $('.gr', el)?.insertAdjacentHTML('afterend', `<div class="card"><b>Absensi masuk kelas saya hari ini</b><p style="margin-top:6px">${d.my_check_in
          ? `<b style="color:var(--ok)">Sudah absen di sekolah ${esc(d.my_check_in)}</b> · <a href="#/qr?t=guru">Absen masuk kelas</a>`
          : `<b style="color:var(--er)">Belum absen</b> · <a href="#/qr?t=guru">Absen sekarang</a>`}</p></div>`);
      }
    },

    async qr(el, q) {
      const t = q.t || (q.kelas ? 'siswa' : 'guru');
      el.innerHTML = hdr('Absensi Masuk Kelas', isAdmin() ? 'Pantau kehadiran guru (di sekolah dan masuk kelas) serta presensi siswa lewat QR' : 'Absen kehadiran di sekolah, masuk kelas sesuai jadwal, dan presensi kelas lewat QR')
        + tabs([['guru', isAdmin() ? 'Kehadiran guru' : 'Kehadiran saya'], ['siswa', 'Presensi siswa']], t) + '<div id="qb"></div>';
      $$('[data-tab]', el).forEach((b) => (b.onclick = () => go('qr', { t: b.dataset.tab })));
      const box = $('#qb', el);
      if (t === 'siswa') {
        await base.qr(box, q); // pilihan kelas di halaman lama → #/qr?kelas=… (tetap di tab ini)
        box.querySelector('.hdr')?.firstElementChild?.remove(); // judul sudah ada di atas tab
        return;
      }
      if (isAdmin()) await teacherMonitor(box, q); else await teacherSelf(box);
    },

    async wali(el, q) {
      await base.wali(el, q);
      if (isAdmin() && !q.kelas) return;
      const H = await api('homeroom' + (q.kelas ? '?classroom_id=' + q.kelas : '')).catch(() => ({ classrooms: [] }));
      for (const c of H.classrooms) {
        el.insertAdjacentHTML('beforeend', `<div class="card" data-roster="${c.id}"></div><div class="card" data-notes="${c.id}"></div>`);
        await Promise.all([rosterCard($(`[data-roster="${c.id}"]`, el), c), notesCard($(`[data-notes="${c.id}"]`, el), c)]);
      }
    },

    pembiasaan, cbt, leger,
    library: libraryStaff,
    // Daftar Nilai Sumatif (guru): modul terpisah, dimuat saat dibuka.
    nilai: async (el, q) => (await import('./daftar-nilai.js' + new URL(import.meta.url).search)).daftarNilai(el, q),
  };
}

// ══════════════════════════ HALAMAN SISWA ══════════════════════════
export function studentPages(base) {
  return {
    pembiasaan,
    cbt: cbtStudent,
    library: libraryStudent,

    async struktur(el) {
      const C = await api('my-class');
      el.innerHTML = hdr('Struktur Kelas', 'Kelas ' + esc(C.classroom.name)) + `
        <div class="card"><div class="mu">Wali kelas</div><div class="pp" style="margin-top:8px">${C.homeroom
          ? `${avatar(C.homeroom.name, C.homeroom.photo_url, 'av')}<span><b>${esc(C.homeroom.name)}</b><br><span class="mu">${esc(C.homeroom.subject || '')}</span></span>`
          : '<span class="mu">Belum ditetapkan</span>'}</div></div>
        ${[['inti', 'Pengurus inti'], ['seksi', 'Seksi-seksi']].map(([g, gl]) => `<div class="card"><b>${gl}</b><div class="gr" style="margin-top:10px">${C.officers.filter((o) => (o.group || 'inti') === g).map((o) => `<div class="officer"><div class="mu">${esc(o.label)}</div>
          ${o.name ? `<div class="pp">${avatar(o.name, o.photo_url, 'av')}<span>${esc(o.name)}${o.is_me ? ' <span class="chip">Kamu</span>' : ''}</span></div>` : '<span class="mu">Belum ditetapkan</span>'}</div>`).join('')}</div></div>`).join('')}
        <div class="card"><b>Anggota kelas (${C.members.length})</b><div style="margin-top:10px">${table(['No', 'Nama', 'Jabatan'], C.members.map((m, i) => [i + 1, withAvatar(m.name, m.photo_url, esc(m.name) + (m.is_me ? ' <span class="chip">Kamu</span>' : '')), esc(m.role)]))}</div></div>`;
    },

    async piket(el) {
      const C = await api('my-class'), v = C.roster, today = v.today;
      el.innerHTML = hdr('Jadwal Piket', 'Kelas ' + esc(C.classroom.name) + ' · ' + Object.values(v.days).join(', '))
        + `<div class="card">${v.mine.length ? `<span class="mu">Jadwal piketmu</span> <b>${esc(v.mine.join(', '))}</b>${v.mine.includes(v.days[today]) ? ' <span class="chip">Hari ini giliranmu</span>' : ''}` : '<span class="mu">Kamu belum punya jadwal piket pekan ini.</span>'}</div>`
        + piketGrid(v, { today, me: S.user.student?.id });
    },

    async jkelas(el, q) {
      const t = q.t || 'catatan';
      const C = await api('my-class');
      el.innerHTML = hdr('Jurnal Kelas', 'Catatan kegiatan kelas ' + esc(C.classroom.name)) + tabs([['catatan', 'Catatan pengurus kelas'], ['guru', 'Jurnal mengajar guru']], t) + '<div id="jkb"></div>';
      bindTabs(el, 't');
      const box = $('#jkb', el);
      if (t === 'guru') { await base.jkelas(box, q); box.querySelector('.hdr')?.firstElementChild?.remove(); return; }
      const N = await api('class-notes');
      box.innerHTML = (C.can_write_notes ? `<form class="card" id="jkf"><b>Isi Jurnal Kelas</b><p class="mu">Diisi oleh siswa yang menjadi pengurus kelas (${esc(C.my_role)}).</p>
          <label for="jkd">Tanggal</label><input id="jkd" name="date" type="date" value="${todayStr()}" max="${todayStr()}" required>
          <label for="jkn">Kegiatan / catatan kelas</label><textarea id="jkn" name="note" rows="4" maxlength="1500" required placeholder="Tuliskan kegiatan, kondisi kelas, atau catatan penting"></textarea>
          <button class="btn" style="margin-top:10px">Simpan jurnal</button></form>`
        : `<div class="card"><b>Jurnal Kelas</b><p class="mu">Jurnal ini hanya dapat diisi oleh pengurus kelas. Kamu tetap dapat melihat catatan kelas.</p></div>`)
        + `<div class="card"><b>Riwayat Jurnal Kelas</b>${N.length ? N.map((n) => `<div class="note"><div class="row" style="justify-content:space-between;align-items:baseline"><b>${esc(n.author)} · ${esc(n.role)}</b><span class="mu">${fd(n.date)}</span></div><div class="prose">${esc(n.note)}</div></div>`).join('') : `<p class="mu" style="margin-top:8px">Belum ada catatan jurnal kelas.</p>`}</div>`;
      const f = $('#jkf', box);
      if (f) f.onsubmit = (e) => { e.preventDefault(); busy($('button', f), async () => { const r = await api('class-notes', { method: 'POST', body: Object.fromEntries(new FormData(f)) }); toast(r.message); render(); }); };
    },

    async tugas(el, q) {
      const F = await api('my-folders');
      const td = todayStr();
      if (!q.f) {
        el.innerHTML = hdr('Tugas-Ku', `${F.pending ? F.pending + ' tugas belum selesai' : 'Semua tugas selesai 🎉'} · satu folder per mata pelajaran`)
          + (F.folders.length ? `<div class="fg">${F.folders.map((f, i) => `<a class="card folder" href="#/tugas?f=${encodeURIComponent(f.name)}"><span class="fi" aria-hidden="true">${ti('folder')}</span><b>${esc(f.name)}</b>
              <span class="mu">${f.tasks.length} tugas · ${f.files.length} berkas</span>${f.pending ? `<span class="pl wr">${f.pending} belum selesai</span>` : f.tasks.length ? '<span class="pl ok">Selesai</span>' : ''}</a>`).join('')}</div>`
            : `<div class="card">${empty('Belum ada folder', 'Folder muncul dari jadwal pelajaran kelasmu.')}</div>`);
        return;
      }
      const f = F.folders.find((x) => x.name === q.f);
      if (!f) return go('tugas');
      const due = (d, t) => pastDue(d, t) ? `<span style="color:var(--er);font-weight:600">Lewat tenggat</span><div class="mu" style="font-size:12px">${dueText(d, t)}</div>` : d === td ? `<b>Hari ini pukul ${(t || '23:59').slice(0, 5).replace(':', '.')}</b>` : dueText(d, t);
      el.innerHTML = `<p style="margin-bottom:10px"><a class="btn o sm" href="#/tugas">← Tugas-Ku</a></p>` + hdr(f.name, 'Tugas dari guru dan berkas yang kamu kumpulkan') + `
        <div class="card"><b>Tugas dari guru</b><div style="margin-top:10px">${table(['Tugas', 'Tenggat', 'Berkas', 'Nilai', 'Selesai'], f.tasks.map((t) => [
          `<b>${esc(t.title)}</b>${t.description ? `<div class="mu">${esc(t.description)}</div>` : ''}${t.teacher ? `<div class="mu">Guru: ${esc(t.teacher)}</div>` : ''}${t.feedback ? `<div class="tk-fb">${ti('edit')} ${esc(t.feedback)}</div>` : ''}`, due(t.due_date, t.due_time), t.files,
          t.graded ? (t.score !== null ? `<b class="tk-score">${esc(String(t.score).replace('.', ','))}</b>` : '<span class="pl ok">Dinilai</span>') : t.done ? '<span class="mu">Menunggu dinilai</span>' : '<span class="mu">—</span>',
          `<input type="checkbox" data-done="${t.id}" ${t.done ? 'checked' : ''} ${t.graded ? 'disabled' : ''} aria-label="Tandai selesai ${esc(t.title)}" style="width:auto">`]), 'Belum ada tugas dari guru di folder ini.')}</div></div>
        <form class="card" id="tff"><b>Kumpulkan berkas</b><div class="row" style="margin-top:10px">
          <div style="flex:2;min-width:180px"><label for="tft">Untuk tugas</label><select id="tft" name="assignment_id"><option value="">Tanpa tugas (arsip pribadi)</option>${f.tasks.map((t) => `<option value="${t.id}">${esc(t.title)}</option>`).join('')}</select></div>
          <div style="flex:3;min-width:200px"><label for="tfx">File Word, PDF, JPG, atau PNG</label><input id="tfx" name="file" type="file" accept=".doc,.docx,.pdf,.jpg,.jpeg,.png,image/jpeg,image/png,application/pdf,application/msword,application/vnd.openxmlformats-officedocument.wordprocessingml.document" required></div>
          <button class="btn nf">Unggah</button></div>
          <p class="mu" style="margin-top:8px">Berkas untuk tugas tertentu otomatis menandai tugas selesai. Foto dikecilkan otomatis di HP sebelum dikirim (hemat kuota); Word (.docx/.doc) dan PDF maksimal 2 MB.</p></form>
        <div class="card"><b>Berkas saya di folder ini</b><div style="margin-top:10px">${table(['Tanggal', 'Nama berkas', 'Untuk tugas', 'Format', 'Ukuran', ''], f.files.map((x) => [dt(x.created_at), esc(x.name), x.task ? esc(x.task) : '<span class="mu">Tanpa tugas</span>', esc(x.type), fsz(x.size),
          `<a class="btn o sm" href="${esc(x.url)}" target="_blank" rel="noopener">Buka</a> <button class="btn o sm" type="button" data-del="${x.id}">Hapus</button>`]), 'Belum ada berkas di folder ini.')}</div></div>`;
      $$('[data-done]', el).forEach((c) => (c.onchange = async () => {
        try { await api(`assignments/${c.dataset.done}/done`, { method: c.checked ? 'PUT' : 'DELETE' }); toast(c.checked ? 'Ditandai selesai' : 'Tanda selesai dihapus'); } catch (x) { c.checked = !c.checked; toast(x.message, true); }
      }));
      $('#tff', el).onsubmit = (e) => { e.preventDefault(); const fd2 = new FormData(e.target); fd2.append('folder', f.name); if (!fd2.get('assignment_id')) fd2.delete('assignment_id');
        const btn = $('button', e.target); const label = btn.textContent;
        busy(btn, async () => { try {
          const raw = fd2.get('file');
          if (raw && !/^image\//.test(raw.type) && raw.size > 2 * 1048576) throw new Error('Word/PDF maksimal 2 MB. Perkecil berkasnya dulu.');
          if (raw && /^image\/(jpeg|png)$/.test(raw.type)) { btn.textContent = 'Mengecilkan foto…'; const small = await shrinkPhoto(raw); fd2.set('file', small, small.name); btn.textContent = 'Mengunggah…'; }
          const r = await api('assignment-files', { method: 'POST', form: fd2 }); toast(r.message); render();
        } finally { btn.textContent = label; } }); };
      $$('[data-del]', el).forEach((b) => (b.onclick = () => { if (confirm('Hapus berkas ini?')) busy(b, async () => { await api('assignment-files/' + b.dataset.del, { method: 'DELETE' }); toast('Berkas dihapus.'); render(); }); }));
    },
  };
}
