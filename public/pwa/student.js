/* MyPresence PWA — halaman siswa: scan QR lewat kamera, rekap, jadwal, jurnal kelas, tugas, agenda. */
const { api, S, $, $$, esc, fd, dueText, pastDue, hdr, empty, toast, go, render, todayStr } = window.MP;

const ST = { H: ['Hadir', 'ok'], S: ['Sakit', 'wr'], I: ['Izin', 'wr'], A: ['Alpa', 'er'] };
const statusPill = (a) => a ? `<span class="pl ${ST[a.status][1]}">${ST[a.status][0]}${a.time ? ' · ' + esc(a.time) : ''}${a.is_late ? ' · terlambat' : ''}</span>` : '<span class="pl mu">Belum presensi</span>';
const dueLabel = (d, td, t) => pastDue(d, t) ? '<span style="color:var(--er);font-weight:600">Lewat tenggat</span>' : d === td ? `<b>Hari ini pukul ${(t || '23:59').slice(0, 5).replace(':', '.')}</b>` : 'Tenggat ' + dueText(d, t);

async function announcements() {
  // Sama dengan portal guru: agenda disematkan + informasi terbaru tiap bidang (tidak saling menggeser).
  const { announcementsCard } = await import('./pages.js' + new URL(import.meta.url).search);
  return announcementsCard(false);
}

export const PAGES = {
  async dash(el) {
    const d = await api('dashboard');
    const td = d.date, first = S.user.name.split(' ')[0];
    const by = {};
    d.pending_tasks.forEach((t) => (by[t.subject || 'Lainnya'] ??= []).push(t));
    el.innerHTML = hdr('Dashboard', `Halo, ${esc(first)} · ${esc(S.user.student?.classroom?.name || '')} · ${esc(d.day_name)}, ${fd(td)}`) + await announcements() + `
      <div class="gr">
        <div class="card"><div class="mu">Presensi hari ini</div><div style="margin:8px 0">${statusPill(d.attendance)}</div>${d.attendance ? '' : `<a class="btn" href="#/scan">${ti('qrcode')} Scan QR kelas</a>`}</div>
        <div class="card"><div class="mu">Tugas belum selesai</div><div class="big">${d.pending_tasks.length}</div><div class="mu">dari ${Object.keys(by).length} mata pelajaran</div></div>
      </div>
      <div class="card"><div class="row" style="align-items:center"><b>Pelajaran hari ini</b><a class="btn o nf" href="#/jadwal">Jadwal lengkap</a></div><div style="margin-top:10px">
        ${d.today_lessons.length ? d.today_lessons.map((b) => `<div class="lesson"><div class="tm">${esc(b.from || '')}–${esc(b.to || '')}<small>Jam ke-${b.period_start}${b.period_end > b.period_start ? '–' + b.period_end : ''}</small></div><div><b>${esc(b.subject)}</b><div class="mu">${esc(b.teacher?.name || '-')}</div></div></div>`).join('') : empty(d.is_school_day ? 'Tidak ada jadwal hari ini.' : 'Hari ini libur.')}</div></div>
      <div class="card"><div class="row" style="align-items:center"><b>Tugas per mata pelajaran</b><a class="btn o nf" href="#/tugas">Tugas-Ku</a></div>
        ${Object.keys(by).length ? Object.keys(by).sort().map((m) => `<div style="padding:10px 0;border-top:1px solid var(--bd);margin-top:8px"><b>${esc(m)}</b>${by[m].map((t) => `<div class="mu" style="margin-top:3px">• ${esc(t.title)} — ${dueLabel(t.due_date, td, t.due_time)}</div>`).join('')}</div>`).join('') : empty('Semua tugas sudah selesai. 🎉')}</div>`;
  },

  // ── Absensi: scan QR kelas ──────────────────────────────────────
  async scan(el) {
    const m = await api('attendance/mine');
    const ok = (b) => `<span class="d ${b ? 'ok' : 'er'}">${b ? '✓' : '!'}</span>`;
    const online = navigator.onLine && !S.offline;
    el.innerHTML = hdr('Absensi Masuk Kelas', `Scan QR yang ditempel di kelas · batas presensi ${esc(m.cutoff)} (akhir jam ke-${m.cutoff_period})`) + `
      <div class="card"><div class="mu">Status hari ini</div><div style="margin:8px 0">${statusPill(m.today)}</div>
        <div class="checks">
          <div class="check-row">${ok(online)}<span>${online ? 'Tersambung ke internet' : 'Tidak ada internet — presensi wajib online'}</span></div>
          <div class="check-row">${ok(m.on_school_network)}<span>${m.on_school_network ? 'Tersambung ke WiFi sekolah' : 'Belum tersambung ke WiFi sekolah. Sambungkan HP ke WiFi sekolah dulu.'}</span></div>
          <div class="check-row">${ok(m.on_registered_device)}<span>${m.on_registered_device ? 'HP ini terdaftar untuk akunmu' : 'HP ini belum terdaftar. Keluar lalu masuk lagi, atau minta wali kelas mereset HP.'}</span></div>
          <div class="check-row"><span class="d ${m.is_school_day ? 'ok' : 'mu'}">${m.is_school_day ? '✓' : '–'}</span><span>${m.is_school_day ? 'Waktu scan: ' + (m.scan_windows.length ? m.scan_windows.map((w) => w.from + '–' + w.to).slice(0, 3).join(', ') + (m.scan_windows.length > 3 ? ', …' : '') : 'sesuai jam pelajaran') : 'Hari ini bukan hari sekolah'}</span></div>
        </div>
        ${m.today && m.today.status === 'H' ? '<div class="alert ok">Kamu sudah presensi hari ini. Selamat belajar!</div>' : `
        <div id="cam"></div>
        <div class="row" style="margin-top:8px"><button class="btn" id="start" type="button" ${online ? '' : 'disabled'}>${ti('camera')} Buka kamera & scan</button></div>
        <details style="margin-top:14px"><summary class="linkbtn" style="font-size:13px">Kamera bermasalah? Ketik kode di bawah QR</summary>
          <form class="row" id="mf" style="margin-top:8px"><div><label for="code">Kode QR</label><input id="code" autocapitalize="characters" autocomplete="off" placeholder="MP-12-ABCD…" maxlength="40" required></div><button class="btn nf" ${online ? '' : 'disabled'}>Kirim</button></form></details>`}
      </div>`;
    const submit = async (code) => {
      stopCamera();
      try {
        const r = await api('attendance/scan', { method: 'POST', body: { code: code.trim() } });
        toast(r.message);
        navigator.vibrate?.(120);
        render();
      } catch (x) { toast(x.message, true); const b = $('#start', el); if (b) b.disabled = false; }
    };
    const mf = $('#mf', el); if (mf) mf.onsubmit = (e) => { e.preventDefault(); submit($('#code', el).value); };
    const st = $('#start', el);
    if (st) st.onclick = async () => {
      st.disabled = true;
      try { await startCamera($('#cam', el), submit); } catch (x) { toast(x.message, true); st.disabled = false; }
    };
  },

  async rekap(el) {
    const m = await api('attendance/mine');
    const c = { H: 0, S: 0, I: 0, A: 0 };
    m.history.forEach((a) => c[a.status]++);
    el.innerHTML = hdr('Rekap Absensi', '30 hari sekolah terakhir') + `
      <div class="gr">${Object.entries(ST).map(([k, [l, cl]]) => `<div class="card"><div class="mu">${l}</div><div class="big" style="color:var(--${cl === 'ok' ? 'ok' : cl === 'er' ? 'er' : 'wr'})">${c[k]}</div></div>`).join('')}</div>
      <div class="card"><div class="list">${m.history.length ? m.history.map((a) => `<div class="item"><div class="grow"><div class="t">${fd(a.date)}</div><div class="mu">${a.method === 'qr' ? 'Scan QR' : a.method === 'auto' ? 'Tidak scan (otomatis)' : 'Dikoreksi guru'}${a.note ? ' · ' + esc(a.note) : ''}</div></div>${statusPill(a)}</div>`).join('') : empty('Belum ada riwayat presensi.')}</div></div>`;
  },

  async jkelas(el) {
    const P = await api('journals');
    el.innerHTML = hdr('Jurnal Kelas', 'Materi yang sudah diajarkan di kelasmu') + `<div class="card"><div class="list">${P.data.length ? P.data.map((j) => `
      <div class="item"><div class="grow"><div class="t">${esc(j.subject)} <span class="mu" style="font-weight:400">· ${fd(j.date)} · jam ke-${j.period_start}${j.period_end > j.period_start ? '–' + j.period_end : ''}</span></div>
        <div>${esc(j.topic)}</div>${j.notes ? `<div class="mu">${esc(j.notes)}</div>` : ''}<div class="mu" style="font-size:12px;margin-top:2px">${esc(j.teacher?.name || '')}</div></div></div>`).join('') : empty('Belum ada jurnal untuk kelasmu.')}</div></div>`;
  },

  async tugas(el, q) {
    const L = await api('assignments'), td = todayStr();
    const show = q.f === 'selesai' ? L.filter((t) => t.done) : L.filter((t) => !t.done);
    el.innerHTML = hdr('Tugas-Ku', 'Centang tugas yang sudah kamu kerjakan') + `
      <div class="tabs"><button type="button" class="${q.f !== 'selesai' ? 'on' : ''}" data-f="">Belum selesai (${L.filter((t) => !t.done).length})</button><button type="button" class="${q.f === 'selesai' ? 'on' : ''}" data-f="selesai">Selesai (${L.filter((t) => t.done).length})</button></div>
      <div class="list">${show.length ? show.map((t) => `<label class="item ${t.done ? 'done' : ''}" style="cursor:pointer"><input type="checkbox" data-id="${t.id}" ${t.done ? 'checked' : ''} style="margin-top:3px;width:20px;height:20px">
        <div class="grow"><div class="t">${esc(t.title)}</div><div class="mu"><span class="tag">${esc(t.subject)}</span> ${dueLabel(t.due_date, td, t.due_time)} · ${esc(t.teacher?.name || '')}</div>${t.description ? `<div style="margin-top:4px;font-size:13px">${esc(t.description)}</div>` : ''}</div></label>`).join('')
        : `<div class="card">${empty(q.f === 'selesai' ? 'Belum ada tugas yang dicentang.' : 'Tidak ada tugas. 🎉')}</div>`}</div>`;
    $$('[data-f]', el).forEach((b) => (b.onclick = () => go('tugas', b.dataset.f ? { f: b.dataset.f } : {})));
    $$('input[data-id]', el).forEach((c) => (c.onchange = async () => {
      try { await api('assignments/' + c.dataset.id + '/done', { method: c.checked ? 'PUT' : 'DELETE' }); toast(c.checked ? 'Ditandai selesai.' : 'Ditandai belum selesai.'); render(); }
      catch (x) { c.checked = !c.checked; toast(x.message, true); }
    }));
  },
};

// Jadwal & agenda siswa memakai komponen yang sama dengan staf (dibaca saat dipanggil).
PAGES.jadwal = async (el) => {
  const t = await api('timetable');
  el.innerHTML = hdr('Jadwal Pelajaran', 'Kelas ' + esc(t.classroom?.name || '')) + window.MP.timetableHtml(t);
};

// ── Kamera & pemindai QR: modul bersama (camera.js) ─────────────────
const { startCamera, stopCamera } = window.MP.camera;
