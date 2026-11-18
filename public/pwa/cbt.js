/* MyPresence PWA — CBT (paket All Service).
 * Guru  : paket soal (PG/essay + gambar JPG per soal & opsi, impor Excel, kirim ke admin) · Ulangan Harian sendiri
 *         (soal dari paket sendiri, nilai → kolom S Daftar Nilai) · pantau & nilai UH · nilai essay.
 * Admin : soal masuk · bank soal · PTS / PAS (token beda tiap kelas + kartu token cetak) · daftar pantau · record siswa. UH tidak terlihat admin.
 * Siswa : daftar ujian → token kelas → mengerjakan (simpan per jawaban, lanjut bila terputus) → nilai langsung.
 */
const { api, S, $, $$, esc, hdr, empty, toast, dialog, go, BOOT } = window.MP;
const ti = globalThis.ti;
const L = 'ABCDE';
const TZ = BOOT.school.timezone || 'Asia/Jakarta';
const isAdmin = () => S.user.role === 'admin';

const pl = (label, tone = 'mu') => `<span class="pl ${tone}">${esc(label)}</span>`;
const PKG = { draf: ['Draf', 'mu'], dikirim: ['Dikirim · menunggu admin', 'wr'], diterima: ['Diterima · di bank soal', 'ok'], dikembalikan: ['Dikembalikan', 'er'] };
const KIND = { pts: ['PTS', 'in'], pas: ['PAS', 'in'], ujian: ['Ujian', 'mu'], uh: ['Ulangan Harian', 'ok'] };
const KIND_OPT = [['pts', 'PTS · tengah semester'], ['pas', 'PAS · akhir semester'], ['ujian', 'Ujian lain']];
const kindPl = (k) => pl(...(KIND[k] || KIND.ujian));
const PHASE = { draf: ['Draf', 'mu'], terbit: ['Terbit · menunggu jadwal', 'wr'], berlangsung: ['Berlangsung', 'ok'], selesai: ['Selesai', 'mu'] };
const num = (v) => v === null || v === undefined || v === '' ? '—' : String(Math.round(v * 100) / 100).replace('.', ',');
const fdt = (iso, opt = { weekday: 'short', day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' }) => iso ? new Intl.DateTimeFormat('id-ID', { timeZone: TZ, ...opt }).format(new Date(iso)) : '—';
const EXIT = { ulang: 'Ulang dari awal: jawaban dihapus, mulai dari soal 1', kunci: 'Kunci ujian: hanya pengawas yang bisa membuka', catat: 'Hanya dicatat untuk pengawas' };
/** Kalimat aturan untuk siswa. */
const exitRule = (act, allow) => act === 'catat' ? 'Keluar aplikasi dicatat dan terlihat oleh pengawas.'
  : `${allow ? `Toleransi keluar ${allow}×. Setelah itu, ` : 'Sekali saja '}keluar / meminimalkan aplikasi ${act === 'ulang' ? '<b>menghapus semua jawabanmu dan kamu mengulang dari soal 1</b> (waktu tetap berjalan)' : '<b>mengunci ujianmu</b> sampai dibuka pengawas (waktu tetap berjalan)'}.`;
const clock = (s) => { s = Math.max(0, Math.floor(s)); const h = Math.floor(s / 3600), m = Math.floor(s % 3600 / 60), d = s % 60; return (h ? h + ':' : '') + String(m).padStart(2, '0') + ':' + String(d).padStart(2, '0'); };
const busy = async (btn, fn) => { if (btn) btn.disabled = true; try { await fn(); } catch (x) { toast(x.message, true); } finally { if (btn && btn.isConnected) btn.disabled = false; } };
const tabs = (items, cur) => `<div class="tabs" role="tablist">${items.map(([v, l, n]) => `<button type="button" role="tab" data-tab="${v}" aria-selected="${cur === v}" class="${cur === v ? 'on' : ''}">${l}${n ? ` <span class="cb-cnt">${n}</span>` : ''}</button>`).join('')}</div>`;
/** Konfirmasi di dalam aplikasi (pengganti confirm()). */
function ask(title, html, okLabel = 'Ya, lanjutkan', tone = '') {
  return new Promise((res) => {
    let ok = false;
    const d = dialog(title, `<div>${html}</div><div class="row" style="justify-content:flex-end;margin-top:14px"><button type="button" class="btn o nf" data-no>Batal</button><button type="button" class="btn nf ${tone}" data-ok>${esc(okLabel)}</button></div>`);
    d.querySelector('[data-no]').onclick = () => d.close();
    d.querySelector('[data-ok]').onclick = () => { ok = true; d.close(); };
    d.addEventListener('close', () => res(ok));
  });
}
/**
 * Foto HP (3–8 MB) dikecilkan di HP sebelum diunggah: sisi terpanjang 1600 px, JPEG.
 * Batas unggah server cukup 2 MB dan unggahan cepat walau WiFi sekolah lambat.
 */
async function shrink(file) {
  if (file.size < 600 * 1024 && /^image\/(jpeg|png)$/.test(file.type)) return file;
  let img;
  try { img = await createImageBitmap(file, { imageOrientation: 'from-image' }); }
  catch (_) {
    img = await new Promise((res, rej) => { const i = new Image(); i.onload = () => res(i); i.onerror = () => rej(new Error('Gambar tidak bisa dibaca. Pilih foto JPG atau PNG.')); i.src = URL.createObjectURL(file); });
  }
  const w0 = img.width || img.naturalWidth, h0 = img.height || img.naturalHeight;
  const k = Math.min(1, 1600 / Math.max(w0, h0));
  const c = document.createElement('canvas'); c.width = Math.round(w0 * k); c.height = Math.round(h0 * k);
  const x = c.getContext('2d'); x.fillStyle = '#fff'; x.fillRect(0, 0, c.width, c.height); x.drawImage(img, 0, 0, c.width, c.height);
  for (const q of [0.85, 0.7, 0.55]) {
    const b = await new Promise((r) => c.toBlob(r, 'image/jpeg', q));
    if (b && b.size <= 1.5 * 1048576) return b;
  }
  return await new Promise((r) => c.toBlob(r, 'image/jpeg', 0.45));
}
function zoom(src) { dialog('Gambar', `<img src="${esc(src)}" alt="Gambar soal diperbesar" style="width:100%;background:#fff;border-radius:8px">`); }
/** Pratinjau soal (guru/admin). */
function qView(q, i, key = true) {
  return `<div class="cb-q"><div class="cb-qh"><b>No. ${i + 1}</b>${pl(q.type === 'pg' ? 'Pilihan ganda' : 'Essay', q.type === 'pg' ? 'ok' : 'wr')}<span class="mu">bobot ${num(q.score)}</span></div>
    ${q.body ? `<div class="cb-body">${esc(q.body)}</div>` : ''}${q.image_url ? `<img class="cb-img" src="${esc(q.image_url)}" alt="Gambar soal no. ${i + 1}" data-zoom>` : ''}
    ${q.type === 'pg' ? `<div class="cb-opts">${q.options.map((o, k) => `<div class="cb-o ${key && q.answer === k ? 'key' : ''}"><b>${L[k]}.</b><span>${esc(o.text)}</span>${o.image_url ? `<img src="${esc(o.image_url)}" alt="Gambar opsi ${L[k]}" data-zoom>` : ''}${key && q.answer === k ? ti('check') : ''}</div>`).join('')}</div>`
      : key && q.guide ? `<div class="alert ok" style="margin:0"><b>Pedoman penilaian:</b> ${esc(q.guide)}</div>` : ''}</div>`;
}
const box = (el, html) => { el.innerHTML = html; const w = el.firstElementChild; w.addEventListener('click', (e) => { const z = e.target.closest('[data-zoom]'); if (z) zoom(z.src); }); return w; };

// ════════════════════════════ STAF (guru & admin) ════════════════════════════
export async function cbtStaff(el, q) {
  if (isAdmin()) return adminPage(el, q);
  return teacherPage(el, q);
}

// ── Guru ─────────────────────────────────────────────────────────
async function teacherPage(el, q) {
  const t = q.t || 'paket';
  if (q.p) return editor(el, +q.p, q);
  if (q.g) return gradingView(el, +q.g);
  if (q.e && t === 'uh') return examWizard(el, q.e === 'baru' ? null : +q.e, true, q);
  const [M, P, G] = await Promise.all([api('cbt/meta'), api('cbt/packages'), api('cbt/grading')]);
  const pending = G.reduce((a, c) => a + c.pending, 0);
  const w = box(el, `<div>${hdr('CBT', 'PTS / PAS: buat paket soal lalu kirim ke admin. Ulangan Harian: tulis/impor soal, terbitkan, pantau, dan nilai sendiri — tanpa admin.')}
    ${tabs([['paket', 'Paket soal PTS / PAS'], ['uh', 'Ulangan Harian'], ['pantau', 'Pantau UH'], ['record', 'Nilai UH'], ['nilai', 'Nilai essay', pending]], t)}<div id="cbb"></div></div>`);
  $$('[data-tab]', w).forEach((b) => (b.onclick = () => go('cbt', { t: b.dataset.tab })));
  const b = $('#cbb', w);
  if (t === 'uh') return examsTab(b, q, M, 'uh');
  if (t === 'pantau') return monitorTab(b, q);
  if (t === 'record') return recordTab(b, q);
  if (t === 'nilai') {
    b.innerHTML = G.length ? `<div class="fg">${G.map((c) => `<a class="card cb-tile" href="#/cbt?g=${c.id}"><b>${esc(c.exam)}</b><span class="mu">${esc(c.class)} · ${esc(c.subject)} · ${fdt(c.starts_at, { day: 'numeric', month: 'short' })}</span>
      <span class="cb-bar"><i style="width:${c.finished ? (c.finished - c.pending) / c.finished * 100 : 0}%"></i></span><span class="mu">${c.finished - c.pending}/${c.finished} siswa dinilai · ${c.finished}/${c.participants} selesai</span>
      ${c.pending ? pl(c.pending + ' menunggu dinilai', 'wr') : c.finished ? pl('Semua sudah dinilai', 'ok') : pl('Belum ada yang selesai')}</a>`).join('')}</div>`
      : empty('Belum ada tugas menilai essay', '<p class="mu">Essay PTS/PAS: admin menunjuk guru penilai per kelas. Essay Ulangan Harian: Anda sendiri yang menilai.</p>');
    return;
  }
  b.innerHTML = `<div class="card cb-head"><span class="mu grow">Mapel yang Anda ajar (Jadwal KBM): <b>${esc(M.subjects.join(', ') || 'belum ada di jadwal')}</b></span>
      <button class="btn nf" type="button" id="cbnew">${ti('plus')} Buat paket soal</button></div>
    ${P.length ? `<div class="list">${P.map((p) => `<div class="item"><div class="grow"><div class="cb-qh"><span class="t">${esc(p.name)}</span>${pl(...PKG[p.status])}</div>
      <div class="mu">${esc(p.subject || '-')}${p.grade ? ' · Kelas ' + esc(p.grade) : ''} · ${p.questions} soal (${p.pg} PG, ${p.essay} essay) · total bobot ${num(p.total_score)}${p.images ? ' · ' + p.images + ' gambar' : ''}</div>
      ${p.status === 'dikembalikan' && p.note ? `<div class="alert er" style="margin:8px 0 0"><b>Catatan admin:</b> ${esc(p.note)}</div>` : ''}</div>
      <a class="btn ${p.status === 'draf' || p.status === 'dikembalikan' ? '' : 'o'} sm nf" href="#/cbt?p=${p.id}">${p.status === 'draf' || p.status === 'dikembalikan' ? ti('edit') + ' Ubah soal' : ti('eye') + ' Lihat'}</a></div>`).join('')}</div>`
      : empty('Belum ada paket soal', '<p class="mu">Buat paket, isi soal pilihan ganda/essay, tambahkan gambar rumus bila perlu. lalu kirim ke admin untuk PTS / PAS. Soal Ulangan Harian ditulis langsung di tab Ulangan Harian.</p>')}`;
  $('#cbnew', w).onclick = () => {
    if (!M.subjects.length) return toast('Mapel Anda belum ada di Jadwal KBM. Minta admin mengimpor jadwal dulu.', true);
    const d = dialog('Paket soal baru', `<form id="pf"><label for="pn">Judul paket</label><input id="pn" name="name" maxlength="120" required placeholder="Contoh: PTS Ganjil Matematika X">
      <div class="row"><div><label for="ps">Mata pelajaran</label><select id="ps" name="subject">${M.subjects.map((s) => `<option>${esc(s)}</option>`).join('')}</select></div>
      <div><label for="pg">Tingkat</label><select id="pg" name="grade"><option value="">—</option>${M.grades.map((g) => `<option>${esc(g)}</option>`).join('')}</select></div></div>
      <button class="btn w">Buat & isi soal</button></form>`);
    $('#pf', d).onsubmit = (e) => { e.preventDefault(); busy($('button', e.target), async () => { const f = Object.fromEntries(new FormData(e.target)); const r = await api('cbt/packages', { method: 'POST', body: f }); d.close(); go('cbt', { p: r.id }); }); };
  };
}

const blankQ = (type = 'pg') => ({ id: null, type, body: '', image: null, image_url: null, options: type === 'pg' ? [0, 1, 2, 3].map(() => ({ text: '', image: null, image_url: null })) : [], answer: null, score: type === 'pg' ? 1 : 5, guide: '' });
function qProblems(q) {
  const p = [];
  if (!q.body.trim() && !q.image) p.push('teks/gambar soal kosong');
  if (q.type === 'pg') { if (q.options.length < 2) p.push('minimal 2 pilihan'); if (q.options.some((o) => !o.text.trim() && !o.image)) p.push('ada pilihan kosong'); if (q.answer === null || q.answer === undefined) p.push('kunci belum dipilih'); }
  if (!(+q.score > 0)) p.push('bobot belum diisi');
  return p;
}

async function editor(el, id, q = {}) {
  const P = await api('cbt/packages/' + id);
  const uh = P.kind === 'uh';
  /** Soal UH: kembali ke langkah "Ambil soal" ulangan; soal paket ini otomatis terpilih. */
  const back = uh ? (q.uh ? `#/cbt?t=uh&e=${q.uh}&s=1&pk=${id}` : '#/cbt?t=uh') : '#/cbt';
  const st = { cur: 0, dirty: false, saving: false, timer: 0 };
  if (!P.questions.length && P.editable) P.questions.push(blankQ());
  const ro = !P.editable;
  const w = box(el, `<div>${hdr(esc(P.name), `${esc(P.subject || '')}${P.grade ? ' · Kelas ' + esc(P.grade) : ''}`, `<a class="btn o nf" href="${back}">${ti('arrow-left')} ${uh ? 'Kembali ke ulangan' : 'Daftar paket'}</a>`)}
    ${P.status === 'dikembalikan' && P.note ? `<div class="alert er"><b>Catatan admin:</b> ${esc(P.note)}</div>` : ''}
    ${ro ? `<div class="alert wr">Paket berstatus <b>${PKG[P.status][0]}</b> sehingga hanya bisa dilihat.</div>` : `<div class="card cb-head"><div class="grow"><label for="en" style="margin-top:0">${uh ? 'Judul soal ulangan' : 'Judul paket'}</label><input id="en" maxlength="120" value="${esc(P.name)}"></div>
      <span class="mu" id="sv">Tersimpan</span><button type="button" class="btn o nf" id="eimp">${ti('file-spreadsheet')} Impor Excel</button></div>`}
    <div class="cb-ed"><div class="card cb-nums"><b>Nomor soal</b><div id="nums"></div>${ro ? '' : `<button type="button" class="btn o sm" id="addq" style="width:100%;justify-content:center;margin-top:8px">${ti('plus')} Tambah soal</button>`}<div class="mu" id="sum" style="margin-top:8px"></div></div>
    <div class="card" id="qf"></div></div>
    ${ro ? '' : uh ? `<div class="card cb-head"><span class="mu grow">Soal Ulangan Harian tersimpan otomatis dan <b>tidak dikirim ke admin</b>. Setelah selesai, kembali ke ulangan untuk mengatur jadwal & kelas lalu terbitkan.</span><a class="btn nf" id="done" href="${back}">${ti('check')} Selesai, kembali ke ulangan</a></div>` : `<div class="card cb-head"><span class="mu grow">Setelah dikirim, paket terkunci sampai admin menerima atau mengembalikannya. Gambar akan dikompres otomatis.</span><button class="btn nf" type="button" id="send">${ti('arrow-right')} Kirim ke admin</button></div>`}</div>`);

  const Q = () => P.questions[st.cur];
  const setSv = (t) => { const s = $('#sv', w); if (s) s.textContent = t; };
  const save = async () => {
    if (ro) return;
    clearTimeout(st.timer); if (st.saving) { st.timer = setTimeout(save, 600); return; }
    st.saving = true; st.dirty = false; setSv('Menyimpan…');
    try {
      const r = await api('cbt/packages/' + id, { method: 'PUT', body: { name: $('#en', w)?.value || P.name, questions: P.questions } });
      r.ids.forEach((qid, i) => { if (P.questions[i]) P.questions[i].id = qid; });
      setSv(st.dirty ? 'Menyimpan…' : 'Tersimpan');
    } catch (x) { setSv('Gagal menyimpan'); toast(x.message, true); st.dirty = true; }
    finally { st.saving = false; if (st.dirty) st.timer = setTimeout(save, 800); }
  };
  const touch = () => { if (ro) return; st.dirty = true; setSv('Belum tersimpan…'); clearTimeout(st.timer); st.timer = setTimeout(save, 1200); drawNums(); };
  addEventListener('hashchange', () => { if (st.dirty) save(); }, { once: true });

  const drawNums = () => {
    $('#nums', w).innerHTML = `<div class="cb-grid">${P.questions.map((x, i) => `<button type="button" class="cb-n ${i === st.cur ? 'on' : ''} ${qProblems(x).length ? 'bad' : ''}" data-n="${i}" title="${esc(qProblems(x).join(', '))}">${i + 1}<small>${x.type === 'pg' ? 'PG' : 'Essay'}</small></button>`).join('')}</div>`;
    const pg = P.questions.filter((x) => x.type === 'pg').length;
    $('#sum', w).innerHTML = `${pg} PG · ${P.questions.length - pg} essay<br>Total bobot ${num(P.questions.reduce((a, x) => a + (+x.score || 0), 0))}${P.questions.some((x) => qProblems(x).length) ? '<br><span style="color:var(--er)">●</span> belum lengkap' : ''}`;
    $$('[data-n]', w).forEach((b) => (b.onclick = () => { st.cur = +b.dataset.n; drawNums(); drawQ(); }));
  };
  const imgPick = (label, cb) => `<label class="cb-up">${ti('upload')} ${label}<input type="file" accept="image/*" data-up="${cb}"></label>`;
  const drawQ = () => {
    const q = Q(); const f = $('#qf', w);
    if (!q) { f.innerHTML = empty('Belum ada soal'); return; }
    const dis = ro ? 'disabled' : '';
    f.innerHTML = `<div class="cb-qh"><b>Soal no. ${st.cur + 1}</b><span class="grow"></span>
        ${ro ? pl(q.type === 'pg' ? 'Pilihan ganda' : 'Essay') : `<div class="sg cb-type">${['pg', 'essay'].map((t) => `<button type="button" class="${q.type === t ? 'on' : ''}" data-type="${t}">${t === 'pg' ? 'Pilihan ganda' : 'Essay'}</button>`).join('')}</div>
        <button type="button" class="btn o sm nf" data-dup title="Duplikat soal">Duplikat</button><button type="button" class="btn o sm nf" data-del title="Hapus soal">${ti('trash')}</button>`}</div>
      <label for="qb">Teks soal</label><textarea id="qb" rows="3" ${dis} placeholder="Tulis pertanyaan. Rumus/gambar bisa ditambahkan sebagai gambar di bawah.">${esc(q.body)}</textarea>
      <label>Gambar soal (rumus, grafik, diagram) · JPG/PNG, opsional · foto HP otomatis dikecilkan</label>
      <div class="cb-imgbox">${q.image_url ? `<img src="${esc(q.image_url)}" alt="Gambar soal" data-zoom>` : '<span class="mu">Belum ada gambar. Bisa foto tulisan tangan, tangkapan layar rumus dari Word, atau gambar dari buku.</span>'}
        ${ro ? '' : imgPick(q.image ? 'Ganti gambar' : 'Tambah gambar (JPG)', 'q') + (q.image ? `<button type="button" class="linkbtn" data-rmimg="q" style="color:var(--er)">Hapus gambar</button>` : '')}</div>
      ${q.type === 'pg' ? `<label>Pilihan jawaban · pilih bulatan untuk menandai <b>kunci</b></label><div class="cb-optlist">${q.options.map((o, k) => `<div class="cb-opt ${q.answer === k ? 'key' : ''}">
          <input type="radio" name="key" value="${k}" ${q.answer === k ? 'checked' : ''} ${dis} aria-label="Jadikan ${L[k]} kunci"><span class="cb-lt">${L[k]}</span>
          <input type="text" value="${esc(o.text)}" data-o="${k}" ${dis} placeholder="Teks opsi ${L[k]}" aria-label="Teks opsi ${L[k]}">
          ${o.image_url ? `<img src="${esc(o.image_url)}" alt="Gambar opsi ${L[k]}" data-zoom>` : '<span></span>'}
          ${ro ? '<span></span>' : `<span class="cb-oact">${imgPick('', k)}${o.image ? `<button type="button" class="linkbtn" data-rmimg="${k}" title="Hapus gambar opsi" style="color:var(--er)">${ti('x')}</button>` : ''}${q.options.length > 2 ? `<button type="button" class="linkbtn" data-delo="${k}" title="Hapus opsi ${L[k]}">${ti('trash')}</button>` : ''}</span>`}</div>`).join('')}</div>
        ${ro || q.options.length >= 5 ? '' : `<button type="button" class="btn o sm" data-addo style="margin-top:8px">${ti('plus')} Tambah opsi ${L[q.options.length]}</button>`}`
      : `<label for="qg">Pedoman penilaian (dilihat guru penilai, tidak tampil ke siswa)</label><textarea id="qg" rows="3" ${dis} placeholder="Jawaban yang diharapkan dan pembagian skor">${esc(q.guide || '')}</textarea>`}
      <div class="row"><div style="max-width:160px"><label for="qs">Bobot nilai</label><input id="qs" type="number" min="0.5" max="100" step="0.5" value="${q.score}" ${dis}></div></div>
      <div id="qp"></div>
      <div class="row" style="justify-content:space-between;margin-top:12px"><button type="button" class="btn o nf" data-go="-1" ${st.cur === 0 ? 'disabled' : ''}>${ti('arrow-left')} Sebelumnya</button><button type="button" class="btn o nf" data-go="1" ${st.cur >= P.questions.length - 1 ? 'disabled' : ''}>Berikutnya ${ti('arrow-right')}</button></div>`;
    const showP = () => { const p = qProblems(q); $('#qp', f).innerHTML = p.length && !ro ? `<div class="alert wr" style="margin:10px 0 0">Belum lengkap: ${esc(p.join(', '))}.</div>` : ''; };
    showP();
    if (ro) return;
    $('#qb', f).oninput = (e) => { q.body = e.target.value; showP(); touch(); };
    const g = $('#qg', f); if (g) g.oninput = (e) => { q.guide = e.target.value; touch(); };
    $('#qs', f).oninput = (e) => { q.score = +e.target.value || 0; showP(); touch(); };
    $$('[data-o]', f).forEach((i) => (i.oninput = () => { q.options[+i.dataset.o].text = i.value; showP(); touch(); }));
    $$('input[name="key"]', f).forEach((r) => (r.onchange = () => { q.answer = +r.value; drawQ(); touch(); }));
    $$('[data-type]', f).forEach((b) => (b.onclick = () => { const t = b.dataset.type; if (t === q.type) return; q.type = t; if (t === 'pg' && !q.options.length) q.options = blankQ().options; if (t === 'essay' && +q.score <= 1) q.score = 5; drawQ(); touch(); }));
    $('[data-addo]', f)?.addEventListener('click', () => { q.options.push({ text: '', image: null, image_url: null }); drawQ(); touch(); });
    $$('[data-delo]', f).forEach((b) => (b.onclick = () => { const k = +b.dataset.delo; q.options.splice(k, 1); if (q.answer === k) q.answer = null; else if (q.answer > k) q.answer--; drawQ(); touch(); }));
    $$('[data-rmimg]', f).forEach((b) => (b.onclick = () => { const k = b.dataset.rmimg; if (k === 'q') { q.image = null; q.image_url = null; } else { q.options[+k].image = null; q.options[+k].image_url = null; } drawQ(); touch(); }));
    $$('[data-up]', f).forEach((inp) => (inp.onchange = () => {
      const raw = inp.files[0]; if (!raw) return;
      if (!/^image\//.test(raw.type)) return toast('Pilih gambar JPG atau PNG.', true);
      setSv('Mengecilkan gambar…');
      busy(null, async () => {
        const file = await shrink(raw);
        if (file.size > 1.9 * 1048576) throw new Error('Gambar terlalu besar. Coba potong (crop) dulu lalu unggah lagi.');
        const fd = new FormData(); fd.append('file', file, 'gambar.jpg'); setSv('Mengunggah gambar…');
        const r = await api('cbt/images', { method: 'POST', form: fd });
        const k = inp.dataset.up; if (k === 'q') { q.image = r.path; q.image_url = r.url; } else { q.options[+k].image = r.path; q.options[+k].image_url = r.url; }
        drawQ(); touch();
      });
    }));
    $('[data-dup]', f).onclick = () => { const c = JSON.parse(JSON.stringify(q)); c.id = null; P.questions.splice(st.cur + 1, 0, c); st.cur++; drawNums(); drawQ(); touch(); };
    $('[data-del]', f).onclick = async () => { if (P.questions.length === 1) return toast('Paket minimal berisi 1 soal.', true); if (!(await ask('Hapus soal no. ' + (st.cur + 1) + '?', '<p class="mu">Soal ini akan dihapus dari paket.</p>', 'Hapus', 'er'))) return; P.questions.splice(st.cur, 1); st.cur = Math.max(0, st.cur - 1); drawNums(); drawQ(); touch(); };
  };
  w.addEventListener('click', (e) => { const b = e.target.closest('[data-go]'); if (b && !b.disabled) { st.cur += +b.dataset.go; drawNums(); drawQ(); scrollTo(0, 0); } });
  drawNums(); drawQ();
  if (ro) return;
  $('#en', w).oninput = () => touch();
  $('#addq', w).onclick = () => { P.questions.push(blankQ(P.questions[st.cur]?.type || 'pg')); st.cur = P.questions.length - 1; drawNums(); drawQ(); touch(); };
  $('#eimp', w).onclick = () => {
    const d = dialog('Impor soal dari Excel', `<p class="mu">Unduh <b>FORMAT_CBT.xlsx</b>, isi sheet <b>Soal</b> mulai baris 5: satu baris satu soal. Jenis <b>PG</b> (Opsi A–E + Kunci huruf) atau <b>ESSAY</b> (Pedoman Penilaian). Skor kosong = PG 1, ESSAY 5. Soal ditambahkan di akhir paket; gambar ditambahkan per soal sesudahnya. Format lama Q/A masih bisa dibaca.</p>
      <form id="xf"><label class="dz"><b>Pilih file soal</b><div class="mu">.xlsx / .csv</div><input type="file" name="file" accept=".xlsx,.csv" required></label>
      <div class="row"><button class="btn nf">Impor</button><a class="btn o nf" href="/api/cbt/format">${ti('download')} Unduh FORMAT_CBT.xlsx</a></div></form>`);
    $('#xf', d).onsubmit = (e) => { e.preventDefault(); busy($('button', e.target), async () => { await save(); const r = await api(`cbt/packages/${id}/import`, { method: 'POST', form: new FormData(e.target) }); d.close(); toast(r.message); if (r.warnings?.length) toast(r.warnings[0], true); editor(el, id, q); }); };
  };
  $('#done', w)?.addEventListener('click', (e) => { if (st.dirty) { e.preventDefault(); save().then(() => { location.hash = back; }); } });
  if (uh) return;
  $('#send', w).onclick = (e) => busy(e.currentTarget, async () => {
    const bad = P.questions.map((x, i) => [i, qProblems(x)]).filter(([, p]) => p.length);
    if (bad.length) { st.cur = bad[0][0]; drawNums(); drawQ(); throw new Error('Soal no. ' + bad.map(([i]) => i + 1).join(', ') + ' belum lengkap.'); }
    if (!(await ask('Kirim paket ke admin?', `<p>${P.questions.length} soal akan dikirim. Selama menunggu diperiksa, paket tidak bisa diubah.</p>`, 'Kirim'))) return;
    await save();
    try { await api(`cbt/packages/${id}/submit`, { method: 'POST' }); }
    catch (x) { if (x.data?.problems) throw new Error(x.data.problems.slice(0, 3).join(' · ')); throw x; }
    toast('Paket dikirim. Admin mendapat notifikasi.'); go('cbt');
  });
}

async function gradingView(el, id) {
  const G = await api('cbt/grading/' + id);
  const st = { cur: G.students.findIndex((s) => s.pending) };
  if (st.cur < 0) st.cur = 0;
  const w = box(el, `<div>${hdr('Nilai essay', `${esc(G.exam)} · ${esc(G.class)}`, `<a class="btn o nf" href="#/cbt?t=nilai">${ti('arrow-left')} Kembali</a>`)}
    ${G.students.length ? '<div class="cb-ed"><div class="card cb-nums" id="sl"></div><div class="card" id="sf"></div></div>' : empty('Belum ada siswa yang selesai', '<p class="mu">Jawaban muncul di sini setelah siswa mengirim ujian.</p>')}</div>`);
  if (!G.students.length) return;
  const drawList = () => {
    const done = G.students.filter((s) => !s.pending).length;
    $('#sl', w).innerHTML = `<b>Siswa (${done}/${G.students.length} dinilai)</b><div class="cb-bar" style="margin:6px 0 8px"><i style="width:${done / G.students.length * 100}%"></i></div>
      <div class="list">${G.students.map((s, i) => `<button type="button" class="cb-sb ${i === st.cur ? 'on' : ''}" data-s="${i}"><span class="grow">${esc(s.name)}</span>${s.pending ? pl('belum', 'wr') : pl(num(s.final), 'ok')}</button>`).join('')}</div>`;
    $$('[data-s]', w).forEach((b) => (b.onclick = () => { st.cur = +b.dataset.s; drawList(); drawForm(); }));
  };
  const drawForm = () => {
    const s = G.students[st.cur];
    $('#sf', w).innerHTML = `<div class="cb-qh"><b>${esc(s.name)}</b><span class="mu">NIS ${esc(s.nis || '-')} · nilai PG ${num(s.pg)}</span>${s.final !== null ? pl('Nilai akhir ' + num(s.final), 'ok') : ''}</div>
      ${G.questions.map((q, qi) => { const a = s.answers.find((x) => x.question_id === q.id) || {}; return `<div class="cb-gr">${qView(q, qi)}
        <label>Jawaban siswa</label><div class="cb-ans">${a.text ? esc(a.text) : '<span class="mu">(tidak dijawab — otomatis 0)</span>'}</div>
        ${a.id && a.text ? `<form class="row" data-ans="${a.id}"><div style="max-width:140px"><label>Skor (maks. ${num(q.score)})</label><input name="score" type="number" min="0" max="${q.score}" step="0.5" value="${a.graded ? a.score : ''}" required></div>
          <div><label>Catatan untuk siswa (opsional)</label><input name="note" maxlength="300" value="${esc(a.note || '')}"></div><button class="btn nf">${a.graded ? 'Perbarui' : 'Simpan'}</button></form>` : ''}</div>`; }).join('')}
      <div class="row" style="justify-content:flex-end;margin-top:10px"><button type="button" class="btn o nf" id="nx">Siswa berikutnya ${ti('arrow-right')}</button></div>`;
    $$('[data-ans]', w).forEach((f) => (f.onsubmit = (e) => { e.preventDefault(); busy($('button', f), async () => {
      const v = Object.fromEntries(new FormData(f)); const r = await api('cbt/grading/answers/' + f.dataset.ans, { method: 'PUT', body: { score: +v.score, note: v.note } });
      const a = s.answers.find((x) => x.id === +f.dataset.ans); a.score = +v.score; a.note = v.note; a.graded = true; s.final = r.final; s.pending = r.pending;
      toast(r.pending ? 'Skor tersimpan.' : 'Skor tersimpan. Nilai akhir siswa tampil: ' + num(r.final));
      if (!r.pending) { const nx = G.students.findIndex((x) => x.pending); if (nx >= 0) st.cur = nx; }
      drawList(); drawForm();
    }); }));
    $('#nx', w).onclick = () => { st.cur = (st.cur + 1) % G.students.length; drawList(); drawForm(); scrollTo(0, 0); };
  };
  drawList(); drawForm();
}

// ── Admin ────────────────────────────────────────────────────────
async function adminPage(el, q) {
  const t = q.t || 'masuk';
  if (q.p) return reviewView(el, +q.p);
  if (q.e && t === 'ujian') return examWizard(el, q.e === 'baru' ? null : +q.e);
  const M = await api('cbt/meta');
  const w = box(el, `<div>${hdr('CBT', 'Terima soal PTS / PAS dari guru, susun ujian, pantau pelaksanaan, dan lihat nilai siswa.')}
    ${tabs([['masuk', 'Soal masuk', M.inbox], ['bank', 'Bank soal'], ['ujian', 'PTS / PAS'], ['pantau', 'Daftar pantau'], ['record', 'Record siswa']], t)}<div id="cbb"></div></div>`);
  $$('[data-tab]', w).forEach((b) => (b.onclick = () => go('cbt', { t: b.dataset.tab })));
  const b = $('#cbb', w);
  await ({ masuk: inbox, bank: bankTab, ujian: (x) => examsTab(x, q, M, 'pts'), pantau: monitorTab, record: recordTab })[t](b, q, M);
}

async function inbox(b) {
  const P = await api('cbt/packages?status=dikirim');
  b.innerHTML = P.length ? `<div class="list">${P.map((p) => `<div class="item"><div class="grow"><span class="t">${esc(p.name)}</span>
      <div class="mu">Dari ${esc(p.teacher)} · ${esc(p.subject)}${p.grade ? ' · Kelas ' + esc(p.grade) : ''} · ${p.questions} soal (${p.pg} PG, ${p.essay} essay)${p.images ? ' · ' + p.images + ' gambar' : ''} · dikirim ${fdt(p.submitted_at)}</div></div>
      <a class="btn sm nf" href="#/cbt?p=${p.id}">${ti('eye')} Periksa</a></div>`).join('')}</div>`
    : empty('Tidak ada paket yang menunggu diperiksa', '<p class="mu">Paket soal yang dikirim guru muncul di sini.</p>');
}

async function reviewView(el, id) {
  const P = await api('cbt/packages/' + id);
  const w = box(el, `<div>${hdr(esc(P.name), `${esc(P.teacher)} · ${esc(P.subject)}${P.grade ? ' · Kelas ' + esc(P.grade) : ''} · ${P.questions.length} soal`, `<a class="btn o nf" href="#/cbt?t=${P.status === 'diterima' ? 'bank' : 'masuk'}">${ti('arrow-left')} Kembali</a>`)}
    <div class="card">${P.questions.map((q, i) => qView(q, i)).join('')}</div>
    ${P.status === 'dikirim' ? `<div class="card"><label for="rn" style="margin-top:0">Catatan untuk guru (wajib bila dikembalikan)</label><textarea id="rn" rows="2" maxlength="1000" placeholder="Mis. Soal no. 2 kunci jawaban belum sesuai"></textarea>
      <div class="row" style="justify-content:flex-end;margin-top:10px"><button type="button" class="btn er nf" data-rv="kembalikan">${ti('x')} Kembalikan ke guru</button><button type="button" class="btn nf" data-rv="terima">${ti('check')} Terima ke bank soal</button></div></div>` : `<div class="alert ${PKG[P.status][1]}">Status: ${PKG[P.status][0]}</div>`}</div>`);
  $$('[data-rv]', w).forEach((btn) => (btn.onclick = () => busy(btn, async () => {
    const r = await api(`cbt/packages/${id}/review`, { method: 'POST', body: { action: btn.dataset.rv, note: $('#rn', w).value.trim() } });
    toast(r.message); go('cbt', { t: 'masuk' });
  })));
}

async function bankTab(b, q, M) {
  const f = { subject: q.s || '', teacher_id: q.g || '', type: q.k || '' };
  const qs = new URLSearchParams(Object.entries(f).filter(([, v]) => v)).toString();
  const B = await api('cbt/bank' + (qs ? '?' + qs : ''));
  b.innerHTML = `<div class="fg">${B.summary.map((s) => `<div class="card cb-tile"><span class="mu">${esc(s.subject)}${s.grade ? ' · Kelas ' + esc(s.grade) : ''}</span><b class="cb-big">${s.pg + s.essay} soal</b><span class="mu">${s.pg} PG · ${s.essay} essay · ${s.packages} paket</span></div>`).join('') || '<div class="card mu">Bank soal masih kosong. Terima paket dari guru di tab Soal masuk.</div>'}</div>
    <div class="card"><div class="row">
      <div><label for="fs">Mata pelajaran</label><select id="fs" data-f="s"><option value="">Semua</option>${M.subjects.map((s) => `<option ${f.subject === s ? 'selected' : ''}>${esc(s)}</option>`).join('')}</select></div>
      <div><label for="ft">Guru pembuat</label><select id="ft" data-f="g"><option value="">Semua</option>${M.teachers.map((t) => `<option value="${t.id}" ${String(f.teacher_id) === String(t.id) ? 'selected' : ''}>${esc(t.name)}</option>`).join('')}</select></div>
      <div><label for="fk">Jenis soal</label><select id="fk" data-f="k"><option value="">Semua</option><option value="pg" ${f.type === 'pg' ? 'selected' : ''}>Pilihan ganda</option><option value="essay" ${f.type === 'essay' ? 'selected' : ''}>Essay</option></select></div></div></div>
    <div class="sc"><table><tr><th>Soal</th><th>Jenis</th><th>Mapel</th><th>Guru</th><th>Paket</th><th>Bobot</th><th></th></tr>
    ${B.questions.map((x, i) => `<tr><td class="wrapcell">${x.image ? ti('eye') + ' ' : ''}${esc(x.body.slice(0, 110))}${x.body.length > 110 ? '…' : ''}</td><td>${pl(x.type === 'pg' ? 'PG' : 'Essay', x.type === 'pg' ? 'ok' : 'wr')}</td><td>${esc(x.subject)}</td><td>${esc(x.teacher || '-')}</td>
      <td><a href="#/cbt?p=${x.package_id}">${esc(x.package)}</a></td><td>${num(x.score)}</td><td><button type="button" class="btn o sm" data-pv="${i}">Pratinjau</button></td></tr>`).join('') || '<tr><td colspan="7" class="mu">Tidak ada soal untuk saringan ini.</td></tr>'}</table></div>`;
  $$('[data-f]', b).forEach((s) => (s.onchange = () => go('cbt', { t: 'bank', s: $('#fs', b).value, g: $('#ft', b).value, k: $('#fk', b).value })));
  $$('[data-pv]', b).forEach((x) => (x.onclick = () => { const d = dialog(B.questions[+x.dataset.pv].package, qView(B.questions[+x.dataset.pv], B.questions[+x.dataset.pv].number - 1)); d.addEventListener('click', (e) => { const z = e.target.closest('[data-zoom]'); if (z) zoom(z.src); }); }));
}

/** Daftar ujian. kind 'pts' = tab PTS / PAS (admin). kind 'uh' = Ulangan Harian (guru: miliknya, bisa disusun; admin: lihat & pantau). */
async function examsTab(b, q, M, kind = 'pts') {
  const uh = kind === 'uh'; const own = uh && !isAdmin(); const tab = uh ? 'uh' : 'ujian';
  const E = await api('cbt/exams?kind=' + kind);
  const intro = own ? `Ulangan Harian sepenuhnya milik Anda: tulis atau impor soal langsung di sini (tidak dikirim ke admin). Nilai akhir otomatis masuk ke <b>kolom S</b> Daftar Nilai yang Anda pilih dan tetap bisa diubah.`
    : uh ? 'Ulangan Harian disusun & dinilai guru masing-masing. Admin bisa memantau dan melihat nilainya.'
    : 'PTS / PAS: satu ujian = satu mata pelajaran, soal dari bank soal. Token dibuat otomatis <b>berbeda untuk tiap kelas</b> saat ujian diterbitkan.';
  const live = (e) => e.phase !== 'terbit' ? `<a class="btn o sm" href="#/cbt?t=${e.phase === 'berlangsung' ? 'pantau' : 'record'}&e=${e.id}">${e.phase === 'berlangsung' ? ti('eye') + ' Pantau' : ti('chart-bar') + ' Nilai'}</a>` : '';
  b.innerHTML = `<div class="card cb-head"><span class="mu grow">${intro}</span>${uh && !own ? '' : `<a class="btn nf" href="#/cbt?t=${tab}&e=baru">${ti('plus')} ${uh ? 'Buat ulangan harian' : 'Buat PTS / PAS'}</a>`}</div>
    <div class="sc"><table><tr><th>${uh ? 'Ulangan' : 'Ujian'}</th><th>Kode</th><th>Jadwal</th><th>Kelas</th><th>Status</th><th></th></tr>
    ${E.map((e) => `<tr><td class="wrapcell">${uh ? '' : kindPl(e.kind) + ' '}<b>${esc(e.title)}</b><div class="mu">${esc(e.subject)} · ${e.questions} soal · ${e.duration} menit${uh && !own ? ' · ' + esc(e.teacher || '-') : ''}${uh && e.grade_lm ? ' · nilai → S' + e.grade_lm : ''}</div></td><td><b>${esc(e.code)}</b></td>
      <td>${fdt(e.starts_at, { weekday: 'short', day: 'numeric', month: 'short' })}<div class="mu">${e.start}–${e.end}</div></td><td class="wrapcell">${esc(e.classes.join(', ') || '-')}</td>
      <td>${pl(...PHASE[e.phase])}${e.status === 'terbit' ? `<div class="mu">${e.done}/${e.started} selesai</div>` : ''}</td>
      <td class="cb-acts">${e.status === 'draf' ? (uh && !own ? '<span class="mu">Draf guru</span>' : `<a class="btn o sm" href="#/cbt?t=${tab}&e=${e.id}">${ti('edit')} Ubah</a><button type="button" class="btn o sm" data-del="${e.id}" aria-label="Hapus draf">${ti('trash')}</button>`)
        : `${uh && !own ? '' : `<button type="button" class="btn sm" data-tok="${e.id}">${ti('key')} Token & kartu</button>`}${live(e)}`}</td></tr>`).join('') || `<tr><td colspan="6" class="mu">${uh ? 'Belum ada ulangan harian.' : 'Belum ada PTS / PAS.'}</td></tr>`}</table></div>`;
  $$('[data-del]', b).forEach((x) => (x.onclick = async () => { if (!(await ask('Hapus draf?', '<p class="mu">Draf ini akan dihapus.</p>', 'Hapus', 'er'))) return; busy(x, async () => { await api('cbt/exams/' + x.dataset.del, { method: 'DELETE' }); toast('Draf dihapus.'); go('cbt', { t: tab }); }); }));
  $$('[data-tok]', b).forEach((x) => (x.onclick = () => busy(x, () => tokenDialog(+x.dataset.tok))));
}

/** Token per kelas + buat ulang + cetak kartu token. */
async function tokenDialog(id) {
  const E = await api('cbt/exams/' + id);
  const d = dialog('Token ujian · ' + E.code, `<p class="mu">${esc(E.title)} · ${fdt(E.starts_at, { weekday: 'long', day: 'numeric', month: 'long' })} · ${E.start}–${E.end}. Token berbeda tiap kelas, hanya berlaku di dalam jadwal, dan <b>tidak dikirim lewat notifikasi</b>.</p>
    <div class="cb-toks">${E.classes.map((c) => `<div class="cb-tk"><span class="mu">${esc(c.name)}</span><b class="cb-token">${esc(c.token || '—')}</b>
      <span class="cb-tkact"><a class="linkbtn" href="/cbt/kartu-token/${E.id}?kelas=${c.classroom_id}" target="_blank" rel="noopener">${ti('printer')} Kartu</a><button type="button" class="linkbtn" data-re="${c.classroom_id}">${ti('refresh')} Buat ulang</button></span>
      ${E.kind === 'uh' ? '' : `<label class="mu">Guru penilai essay<select data-gr="${c.id}"><option value="">—</option></select></label>`}</div>`).join('')}</div>
    <div class="row" style="margin-top:12px"><a class="btn nf" href="/cbt/kartu-token/${E.id}" target="_blank" rel="noopener">${ti('printer')} Cetak semua kartu token</a><button type="button" class="btn o nf" data-re="">${ti('refresh')} Buat ulang semua</button></div>
    <p class="mu" style="margin-top:8px">Bila token dibuat ulang setelah kartu dicetak, cetak ulang kartu kelas itu. Siswa yang sudah mulai tetap lanjut.</p>`);
  if (E.kind !== 'uh' && isAdmin()) api('cbt/meta').then((M) => $$('[data-gr]', d).forEach((s) => { const c = E.classes.find((x) => x.id === +s.dataset.gr); s.innerHTML = `<option value="">—</option>` + M.teachers.map((t) => `<option value="${t.id}" ${c.grader_id === t.id ? 'selected' : ''}>${esc(t.name)}</option>`).join(''); }));
  $$('[data-gr]', d).forEach((s) => (s.onchange = () => busy(s, async () => { const r = await api(`cbt/exams/${id}/classes/${s.dataset.gr}`, { method: 'PUT', body: { grader_id: s.value ? +s.value : null } }); toast(r.message); })));
  $$('[data-re]', d).forEach((x) => (x.onclick = async () => {
    const one = x.dataset.re; const name = one ? E.classes.find((c) => String(c.classroom_id) === one)?.name : 'semua kelas';
    if (!(await ask('Buat ulang token ' + name + '?', '<p>Token lama langsung tidak berlaku. Umumkan token baru ke pengawas dan cetak ulang kartunya.</p>', 'Buat ulang'))) return;
    busy(null, async () => { const r = await api(`cbt/exams/${id}/tokens`, { method: 'POST', body: one ? { classroom_id: +one } : {} }); toast(r.message); d.close(); tokenDialog(id); });
  }));
}

/** Wizard ujian: info → soal → jadwal → peserta & penilai → pengaturan. Draf disimpan di server. */
async function examWizard(el, id, uh = false, q = {}) {
  const [M, E] = await Promise.all([api('cbt/meta'), id ? api('cbt/exams/' + id) : null]);
  const tab = uh ? 'uh' : 'ujian'; const KW = uh ? 'ulangan' : 'ujian';
  const today = new Intl.DateTimeFormat('en-CA', { timeZone: TZ }).format(new Date());
  const st = { step: uh && q.s ? 1 : 0, id, bankId: E?.bank_id || null, bank: null, e: E ? { kind: E.kind || 'ujian', grade_lm: E.grade_lm || 1, title: E.title, subject: E.subject, grade: E.grade || '', code: E.code, date: E.date, start: E.start, end: E.end, duration: E.duration,
    shuffle_questions: E.shuffle_questions, shuffle_options: E.shuffle_options, max_exits: E.max_exits, exit_action: E.exit_action || 'ulang', exit_allow: E.exit_allow ?? 0, questions: E.questions.map(Number),
    classes: Object.fromEntries(E.classes.map((c) => [c.classroom_id, c.grader_id || ''])) }
    : { kind: 'pts', grade_lm: 1, title: '', subject: M.subjects[0] || '', grade: '', code: '', date: today, start: '07:30', end: '09:30', duration: 90, shuffle_questions: true, shuffle_options: true, max_exits: 3, exit_action: 'ulang', exit_allow: 0, questions: [], classes: {} } };
  const STEPS = uh ? ['Info ulangan', 'Ambil soal', 'Jadwal', 'Peserta & kolom nilai', 'Pengaturan'] : ['Info ujian', 'Ambil soal', 'Jadwal', 'Peserta & penilai', 'Pengaturan'];
  const w = box(el, `<div>${hdr(uh ? (id ? 'Ubah ulangan harian' : 'Buat ulangan harian') : (id ? 'Ubah PTS / PAS' : 'Buat PTS / PAS'), 'Draf bisa disimpan dan dilanjutkan nanti. Token dibuat saat diterbitkan.', `<a class="btn o nf" href="#/cbt?t=${tab}">${ti('arrow-left')} ${uh ? 'Daftar ulangan harian' : 'Daftar PTS / PAS'}</a>`)}
    <div class="cb-steps" id="steps"></div><div class="card" id="wb"></div></div>`);
  const loadBank = async () => { if (!st.e.subject) return (st.bank = []); st.bank = (await api('cbt/bank?subject=' + encodeURIComponent(st.e.subject))).questions; };
  await loadBank();
  /** Soal paket ulangan ini (baru ditulis / diimpor) otomatis ikut terpilih. */
  const pickPkg = (pid) => { if (!pid) return; const add = st.bank.filter((x) => x.package_id === +pid && !st.e.questions.includes(x.id)).map((x) => x.id); st.e.questions = [...st.e.questions, ...add]; };
  if (uh && q.pk) pickPkg(q.pk);
  const chosen = () => st.bank.filter((x) => st.e.questions.includes(x.id));
  const pengampu = (cid) => uh ? [] : (M.pengampu[st.e.subject.toLowerCase().trim()] || {})[cid] || [];
  /** Kelas yang bisa dipilih: UH = kelas yang diajar guru untuk mapel ini (Jadwal KBM); PTS/PAS = semua kelas. */
  const classList = () => (uh ? M.classrooms.filter((c) => (c.subjects || []).includes(st.e.subject)) : M.classrooms).filter((c) => !st.e.grade || !c.grade || c.grade === st.e.grade);
  const errs = () => [!st.e.title.trim() || !st.e.subject ? 'Isi judul dan mata pelajaran.' : !st.e.code.trim() ? 'Isi kode ujian.' : '', !chosen().length ? 'Pilih minimal 1 soal.' : '',
    !st.e.date || !st.e.start || !st.e.end || !(st.e.duration > 0) ? 'Isi tanggal, jam, dan durasi.' : st.e.end <= st.e.start ? 'Jam selesai harus setelah jam mulai.' : '',
    !Object.keys(st.e.classes).length ? 'Pilih minimal satu kelas.' : uh ? (!(st.e.grade_lm >= 1) ? 'Pilih kolom nilai S.' : '') : chosen().some((x) => x.type === 'essay') && Object.values(st.e.classes).some((v) => !v) ? 'Ada kelas tanpa guru penilai essay.' : '', ''];
  const payload = () => ({ ...st.e, kind: uh ? undefined : st.e.kind, grade_lm: uh ? +st.e.grade_lm : undefined, bank_id: uh ? st.bankId : undefined, duration: +st.e.duration, max_exits: +st.e.max_exits || 3, exit_allow: +st.e.exit_allow || 0, classes: Object.entries(st.e.classes).map(([cid, g]) => ({ classroom_id: +cid, grader_id: g ? +g : null })) });
  /** UH: pastikan paket soal milik ulangan ini ada (dibuat saat guru pertama kali menulis/mengimpor soal) dan draf tersimpan. */
  const ensureBank = async () => {
    const e0 = errs()[0]; if (e0) { st.step = 0; draw(); throw new Error(e0); }
    if (!st.bankId) st.bankId = (await api('cbt/packages', { method: 'POST', body: { name: 'Soal ' + st.e.title.trim(), subject: st.e.subject, grade: st.e.grade || null, kind: 'uh' } })).id;
    await saveDraft(); return st.bankId;
  };
  const saveDraft = async () => { const r = st.id ? await api('cbt/exams/' + st.id, { method: 'PUT', body: payload() }) : await api('cbt/exams', { method: 'POST', body: payload() }); st.id = r.id; return r; };
  const draw = () => {
    const E2 = errs();
    $('#steps', w).innerHTML = STEPS.map((s, i) => `<button type="button" class="${i === st.step ? 'on' : i < st.step ? 'done' : ''}" data-step="${i}"><i>${i < st.step ? '✓' : i + 1}</i>${s}</button>`).join('');
    const e = st.e; let h = '';
    if (st.step === 0) h = `${uh ? '' : `<div class="row"><div><label for="wk">Jenis ujian</label><select id="wk" data-k="kind">${KIND_OPT.map(([v, l]) => `<option value="${v}" ${e.kind === v ? 'selected' : ''}>${l}</option>`).join('')}</select></div><div style="flex:2"></div></div>`}
      <div class="row"><div style="flex:2"><label for="wt">Judul ${KW}</label><input id="wt" data-k="title" maxlength="150" value="${esc(e.title)}" placeholder="${uh ? 'Contoh: UH 1 Matematika · Bilangan Bulat' : 'Contoh: PTS Ganjil Matematika X'}"></div>
      <div><label for="wsb">Mata pelajaran</label><select id="wsb" data-k="subject" ${uh && st.bankId ? 'disabled title="Mapel terkunci karena soal ulangan sudah ditulis"' : ''}>${M.subjects.map((s) => `<option ${s === e.subject ? 'selected' : ''}>${esc(s)}</option>`).join('') || `<option value="">${uh ? 'Mapel belum ada di Jadwal KBM' : 'Bank soal masih kosong'}</option>`}</select></div></div>
      <div class="row"><div><label for="wg">Tingkat</label><select id="wg" data-k="grade"><option value="">—</option>${M.grades.map((g) => `<option ${g === e.grade ? 'selected' : ''}>${esc(g)}</option>`).join('')}</select></div>
      <div><label for="wc">Kode ${KW}</label><input id="wc" data-k="code" maxlength="30" value="${esc(e.code)}" placeholder="${uh ? 'UH1-MTK-7A' : 'MTK-X-01'}"></div></div>
      <p class="mu" style="margin-top:8px">${uh ? 'Mapel sesuai yang Anda ajar di Jadwal KBM; soal diambil dari paket soal Anda sendiri.' : 'Mapel diambil dari bank soal yang sudah diterima.'} Kode unik di sekolah, mis. ${uh ? 'UH1-MTK-7A' : 'MTK-X-01'}.</p>`;
    if (st.step === 1) { const c = chosen(); const mine = st.bank.filter((x) => x.package_id === st.bankId); h = `${uh ? `<div class="card cb-uhq"><div class="cb-head"><span class="grow"><b>${ti('edit')} Soal ulangan ini</b><span class="mu" style="display:block">${mine.length ? `${mine.length} soal (${mine.filter((x) => x.type === 'pg').length} PG, ${mine.filter((x) => x.type === 'essay').length} essay) · tersimpan otomatis, tidak dikirim ke admin` : 'Belum ada soal. Tulis soal PG/essay (bisa dengan gambar) atau impor dari Excel.'}</span></span>
        <button type="button" class="btn nf" id="wwr">${ti(mine.length ? 'edit' : 'plus')} ${mine.length ? 'Ubah soal' : 'Tulis soal'}</button><button type="button" class="btn o nf" id="wim">${ti('file-spreadsheet')} Impor Excel</button></div></div>
        <p class="mu" style="margin:12px 0 6px">Centang soal yang dipakai. Soal dari paket PTS/PAS Anda yang lain juga bisa diambil.</p>` : ''}<div class="cb-head"><span class="mu grow">${st.bank.length} soal di ${uh ? 'paket Anda' : 'bank'} ${esc(e.subject)} · dipilih <b>${c.length}</b> (${c.filter((x) => x.type === 'pg').length} PG, ${c.filter((x) => x.type === 'essay').length} essay) · total bobot <b>${num(c.reduce((a, x) => a + x.score, 0))}</b></span>
        <span class="row" style="flex:none;align-items:center"><label for="wn" style="margin:0">Acak</label><input id="wn" type="number" min="1" max="${st.bank.length}" value="${Math.min(20, st.bank.length || 1)}" style="width:76px"><button type="button" class="btn o sm" id="wr">${ti('refresh')} Ambil acak</button><button type="button" class="btn o sm" id="wa">Pilih semua</button><button type="button" class="btn o sm" id="wz">Kosongkan</button></span></div>
      <div class="list" style="margin-top:10px">${st.bank.map((x) => `<label class="item"><input type="checkbox" data-q="${x.id}" ${e.questions.includes(x.id) ? 'checked' : ''}><span class="grow">${esc(x.body.slice(0, 140) || '(soal bergambar)')}<span class="mu" style="display:block">${esc(x.package)}${uh ? '' : ' · ' + esc(x.teacher || '')}</span></span>${pl(x.type === 'pg' ? 'PG' : 'Essay', x.type === 'pg' ? 'ok' : 'wr')}</label>`).join('') || (uh ? empty('Belum ada soal ' + e.subject, '<p class="mu">Klik <b>Tulis soal</b> atau <b>Impor Excel</b> di atas.</p>') : empty('Bank soal mapel ini kosong'))}</div>`; }
    if (st.step === 2) h = `<div class="row"><div><label for="wd">Tanggal</label><input id="wd" type="date" data-k="date" value="${e.date}"></div><div><label for="ws">Mulai</label><input id="ws" type="time" data-k="start" value="${e.start}"></div>
      <div><label for="we">Selesai</label><input id="we" type="time" data-k="end" value="${e.end}"></div><div><label for="wdu">Durasi mengerjakan (menit)</label><input id="wdu" type="number" min="5" max="600" data-k="duration" value="${e.duration}"></div></div>
      <p class="mu" style="margin-top:8px">Siswa bisa mulai kapan saja di antara jam mulai–selesai; waktu mengerjakan ${e.duration} menit dihitung server sejak siswa mulai dan tidak melewati jam selesai.</p>`;
    if (st.step === 3 && uh) { const n = M.lm_max || 10; h = `<p class="mu">Centang kelas peserta (kelas yang Anda ajar ${esc(e.subject)} di Jadwal KBM). Essay dinilai oleh Anda sendiri di tab Nilai essay.</p>
      <div class="list">${classList().map((c) => `<label class="item"><input type="checkbox" data-c="${c.id}" ${c.id in e.classes ? 'checked' : ''}><span class="grow"><b>${esc(c.name)}</b></span><span class="mu">${c.students} siswa</span></label>`).join('') || empty('Tidak ada kelas', `<p class="mu">${esc(e.subject)} belum terjadwal untuk Anda${e.grade ? ' di tingkat ' + esc(e.grade) : ''}.</p>`)}</div>
      <div class="card" style="margin-top:12px"><label for="wlm" style="margin-top:0">${ti('file-spreadsheet')} Nilai masuk ke Daftar Nilai · kolom</label>
        <select id="wlm" data-k="grade_lm" style="max-width:220px">${Array.from({ length: n }, (_, i) => `<option value="${i + 1}" ${+e.grade_lm === i + 1 ? 'selected' : ''}>S${i + 1} (Lingkup Materi ${i + 1})</option>`).join('')}</select>
        <p class="mu" style="margin:6px 0 0">Nilai akhir tiap siswa otomatis tertulis di kolom S${+e.grade_lm || 1} Daftar Nilai ${esc(e.subject)} semester ini (PG langsung saat siswa kirim; bila ada essay, setelah selesai Anda nilai). Nilai di Daftar Nilai tetap bisa Anda ubah.</p></div>`; }
    if (st.step === 3 && !uh) h = `<p class="mu">Centang kelas peserta. <b>Guru penilai essay</b> terisi otomatis dari guru pengampu ${esc(e.subject)} di kelas itu (Jadwal KBM) dan bisa diganti.</p>
      <div class="sc"><table><tr><th>Kelas</th><th>Siswa</th><th>Guru penilai essay</th></tr>${classList().map((c) => { const on = c.id in e.classes; const p = pengampu(c.id);
        return `<tr><td><label class="pp"><input type="checkbox" data-c="${c.id}" ${on ? 'checked' : ''}> <b>${esc(c.name)}</b></label></td><td>${c.students}</td>
        <td>${on ? `<select data-g="${c.id}" aria-label="Guru penilai ${esc(c.name)}"><option value="">— Pilih guru —</option>${M.teachers.map((t) => `<option value="${t.id}" ${String(e.classes[c.id]) === String(t.id) ? 'selected' : ''}>${esc(t.name)}${p.includes(t.id) ? ' · pengampu' : ''}</option>`).join('')}</select>
          ${!e.classes[c.id] ? `<div class="mu" style="color:var(--er)">${p.length > 1 ? 'Ada beberapa pengampu di jadwal, pilih salah satu.' : 'Pengampu tidak ditemukan di jadwal, pilih manual.'}</div>` : ''}` : '<span class="mu">Tidak ikut</span>'}</td></tr>`; }).join('')}</table></div>
      ${e.grade ? `<p class="mu" style="margin-top:6px">Hanya kelas tingkat ${esc(e.grade)} yang ditampilkan.</p>` : ''}`;
    if (st.step === 3 && e.grade && uh) h += `<p class="mu" style="margin-top:6px">Hanya kelas tingkat ${esc(e.grade)} yang ditampilkan.</p>`;
    if (st.step === 4) h = `<div class="list"><label class="item"><input type="checkbox" data-b="shuffle_questions" ${e.shuffle_questions ? 'checked' : ''}><span class="grow"><b>Acak urutan soal</b><span class="mu" style="display:block">Siswa yang duduk bersebelahan mendapat urutan berbeda.</span></span></label>
      <label class="item"><input type="checkbox" data-b="shuffle_options" ${e.shuffle_options ? 'checked' : ''}><span class="grow"><b>Acak urutan pilihan A–E</b><span class="mu" style="display:block">Kunci tetap dinilai benar.</span></span></label>
      <div class="item"><span class="grow"><b>Nilai langsung tampil ke siswa</b><span class="mu" style="display:block">Nilai PG muncul setelah kirim; essay menyusul setelah dinilai. Kunci per soal tidak ditampilkan.</span></span>${pl('Aktif', 'ok')}</div></div>
      <div class="card cb-lockset"><b>${ti('lock')} Kunci ujian: bila siswa keluar / meminimalkan aplikasi</b>
        <p class="mu" style="margin:2px 0 8px">Web tidak bisa mencegah tombol Home, tetapi setiap keluar terdeteksi (pindah aplikasi, minimize, menutup aplikasi, pindah jendela di laptop) lalu dikenai sanksi di bawah. Waktu ujian tetap berjalan.</p>
        <div class="row"><div style="flex:2;min-width:240px"><label for="wea">Sanksi</label><select id="wea" data-k="exit_action">${Object.entries(EXIT).map(([v, l]) => `<option value="${v}" ${e.exit_action === v ? 'selected' : ''}>${l}</option>`).join('')}</select></div>
          <div style="flex:1;min-width:160px"><label for="wel">Toleransi (keluar yang dimaafkan)</label><input id="wel" type="number" min="0" max="10" data-k="exit_allow" value="${e.exit_allow ?? 0}"></div>
          <div style="flex:1;min-width:160px"><label for="wx">Tandai di pantau bila keluar ≥</label><input id="wx" type="number" min="1" max="50" data-k="max_exits" value="${e.max_exits}"></div></div></div>
      <div class="alert ok" style="margin-top:12px"><b>Ringkasan:</b> ${uh ? 'Ulangan Harian' : (KIND[e.kind] || KIND.ujian)[0]} · ${esc(e.title || '-')} · ${esc(e.subject)} · ${chosen().length} soal · ${fdt(e.date + 'T12:00:00', { weekday: 'long', day: 'numeric', month: 'long' })} ${e.start}–${e.end} · ${e.duration} menit · kode ${esc(e.code.toUpperCase())} · ${Object.keys(e.classes).length} kelas (token dibuat per kelas saat terbit)${uh ? ` · nilai → kolom S${+e.grade_lm || 1} Daftar Nilai` : ''}</div>`;
    $('#wb', w).innerHTML = h + `<div id="werr"></div><div class="row" style="justify-content:space-between;margin-top:14px">
      <button type="button" class="btn o nf" id="wp" ${st.step === 0 ? 'disabled' : ''}>${ti('arrow-left')} Kembali</button><span class="row" style="flex:none"><button type="button" class="btn o nf" id="wsv">Simpan draf</button>
      ${st.step < 4 ? `<button type="button" class="btn nf" id="wn2" ${E2[st.step] ? 'disabled' : ''}>Lanjut ${ti('arrow-right')}</button>` : `<button type="button" class="btn nf" id="wpub" ${E2.some(Boolean) ? 'disabled' : ''}>${ti('check')} Terbitkan ${KW}</button>`}</span></div>`;
    bind(); nav();
  };
  /** Perbarui pesan & tombol tanpa menggambar ulang (agar ketikan tidak hilang). */
  const nav = () => {
    const E2 = errs(); $('#werr', w).innerHTML = E2[st.step] ? `<div class="alert wr" style="margin:12px 0 0">${esc(E2[st.step])}</div>` : '';
    const n = $('#wn2', w); if (n) n.disabled = !!E2[st.step];
    const pb = $('#wpub', w); if (pb) pb.disabled = E2.some(Boolean);
  };
  const bind = () => {
    $$('[data-step]', w).forEach((b) => (b.onclick = () => { st.step = +b.dataset.step; draw(); }));
    $$('[data-k]', w).forEach((i) => {
      const set = () => { const k = i.dataset.k; st.e[k] = i.type === 'number' ? +i.value : k === 'code' ? i.value.toUpperCase() : i.value; };
      if (i.tagName === 'SELECT') i.onchange = async () => { set(); if (i.dataset.k === 'subject') { st.e.questions = []; const ok = classList().map((c) => String(c.id)); for (const cid in st.e.classes) { if (uh && !ok.includes(String(cid))) { delete st.e.classes[cid]; continue; } const p = pengampu(cid); st.e.classes[cid] = uh ? '' : p.length === 1 ? p[0] : ''; } await loadBank(); } draw(); };
      else i.oninput = () => { set(); nav(); };
    });
    $$('[data-q]', w).forEach((c) => (c.onchange = () => { const v = +c.dataset.q; st.e.questions = c.checked ? [...st.e.questions, v] : st.e.questions.filter((x) => x !== v); draw(); }));
    $('#wr', w)?.addEventListener('click', () => { const n = +$('#wn', w).value || 1; const ids = st.bank.map((x) => x.id).sort(() => Math.random() - 0.5).slice(0, n); st.e.questions = st.bank.map((x) => x.id).filter((x) => ids.includes(x)); draw(); });
    $('#wwr', w)?.addEventListener('click', (ev) => busy(ev.currentTarget, async () => { const b = await ensureBank(); go('cbt', { p: b, uh: st.id }); }));
    $('#wim', w)?.addEventListener('click', () => {
      const d = dialog('Impor soal ulangan dari Excel', `<p class="mu">Unduh <b>FORMAT_CBT.xlsx</b>, isi sheet <b>Soal</b> mulai baris 5: satu baris satu soal. Jenis <b>PG</b> (Opsi A–E + Kunci huruf) atau <b>ESSAY</b> (Pedoman Penilaian). Skor kosong = PG 1, ESSAY 5. Gambar bisa ditambahkan per soal lewat <b>Ubah soal</b>.</p>
        <form id="xf"><label class="dz"><b>Pilih file soal</b><div class="mu">.xlsx / .csv</div><input type="file" name="file" accept=".xlsx,.csv" required></label>
        <div class="row"><button class="btn nf">Impor</button><a class="btn o nf" href="/api/cbt/format">${ti('download')} Unduh FORMAT_CBT.xlsx</a></div></form>`);
      $('#xf', d).onsubmit = (ev) => { ev.preventDefault(); busy($('button', ev.target), async () => {
        const b = await ensureBank(); const r = await api(`cbt/packages/${b}/import`, { method: 'POST', form: new FormData(ev.target) });
        d.close(); toast(r.message); if (r.warnings?.length) toast(r.warnings[0], true);
        await loadBank(); pickPkg(b); await saveDraft(); draw();
      }); };
    });
    $('#wa', w)?.addEventListener('click', () => { st.e.questions = st.bank.map((x) => x.id); draw(); });
    $('#wz', w)?.addEventListener('click', () => { st.e.questions = []; draw(); });
    $$('[data-c]', w).forEach((c) => (c.onchange = () => { const cid = c.dataset.c; if (c.checked) { const p = pengampu(+cid); st.e.classes[cid] = p.length === 1 ? p[0] : ''; } else delete st.e.classes[cid]; draw(); }));
    $$('[data-g]', w).forEach((s) => (s.onchange = () => { st.e.classes[s.dataset.g] = s.value; draw(); }));
    $$('[data-b]', w).forEach((c) => (c.onchange = () => { st.e[c.dataset.b] = c.checked; }));
    $('#wp', w).onclick = () => { st.step--; draw(); };
    $('#wn2', w)?.addEventListener('click', () => { st.step++; draw(); scrollTo(0, 0); });
    $('#wsv', w).onclick = (e) => busy(e.currentTarget, async () => { await saveDraft(); toast('Draf disimpan.'); });
    $('#wpub', w)?.addEventListener('click', (e) => busy(e.currentTarget, async () => {
      if (!(await ask('Terbitkan ' + KW + '?', `<p>Soal dibekukan, token dibuat <b>berbeda untuk tiap kelas</b>, dan siswa peserta mendapat notifikasi jadwal (token tidak ikut dikirim). Setelah terbit, soal & jadwal tidak bisa diubah.</p>`, 'Terbitkan'))) return;
      await saveDraft(); const r = await api(`cbt/exams/${st.id}/publish`, { method: 'POST' }); toast(r.message); go('cbt', { t: tab }); tokenDialog(st.id);
    }));
  };
  draw();
}

async function pickExam(b, q, label) {
  const E = (await api('cbt/exams')).filter((e) => e.status === 'terbit');
  if (!E.length) { b.innerHTML = empty(isAdmin() ? 'Belum ada ujian terbit' : 'Belum ada ulangan harian terbit', `<p class="mu">${isAdmin() ? 'Susun ujian di tab PTS / PAS lalu terbitkan.' : 'Susun di tab Ulangan Harian lalu terbitkan.'}</p>`); return null; }
  const id = +q.e || (E.find((e) => e.phase === 'berlangsung') || E.find((e) => e.phase === 'terbit') || E[0]).id;
  b.innerHTML = `<div class="card cb-head"><label for="xe" style="margin:0">${label}</label><select id="xe" style="max-width:420px">${E.map((e) => `<option value="${e.id}" ${e.id === id ? 'selected' : ''}>${isAdmin() ? '[' + (KIND[e.kind] || KIND.ujian)[0] + '] ' : ''}${esc(e.title)} · ${esc(e.code)} · ${PHASE[e.phase][0]}</option>`).join('')}</select><span class="grow"></span><span id="xr"></span></div><div id="xb"></div>`;
  $('#xe', b).onchange = (e) => go('cbt', { t: q.t, e: e.target.value });
  return id;
}

async function monitorTab(b, q) {
  const id = await pickExam(b, q, 'Ujian');
  if (!id) return;
  const xb = $('#xb', b); const st = { cls: q.c || '' };
  const draw = async () => {
    if (!xb.isConnected) return false;
    if (xb.contains(document.activeElement) && document.activeElement.tagName === 'SELECT') return true; // jangan ganggu saat admin memilih aksi
    const D = await api(`cbt/exams/${id}/monitor` + (st.cls ? '?classroom_id=' + st.cls : ''));
    const sel = D.classes.find((c) => String(c.classroom_id) === String(st.cls));
    xb.innerHTML = `<div class="fg">
      <div class="card cb-tile"><span class="mu">${pl(...PHASE[D.phase])} ${D.start}–${D.end} · ${D.duration} menit · ${D.total} soal</span><b>${esc(D.title)}</b>
        <div class="cb-toks sm">${(sel ? [sel] : D.classes).map((c) => `<div class="cb-tk"><span class="mu">${esc(c.name)}</span><b class="cb-token">${esc(c.token)}</b></div>`).join('')}</div>
        <span><a class="linkbtn" href="/cbt/kartu-token/${id}" target="_blank" rel="noopener">${ti('printer')} Cetak kartu token</a></span></div>
      <div class="card cb-tile"><span class="mu">Mengerjakan / selesai</span><b class="cb-big">${D.count.mengerjakan} / ${D.count.selesai}</b><span class="mu">${D.count.belum} belum mulai</span></div>
      <div class="card cb-tile"><span class="mu">Perlu diperhatikan</span><b class="cb-big" style="color:var(--wr)">${D.count.warn}</b><span class="mu">keluar aplikasi ≥ ${D.max_exits}×${D.count.locked ? ` · <b style="color:var(--er)">${D.count.locked} terkunci</b>` : ''}</span></div></div>
    <div class="cb-head" style="margin-bottom:10px"><label for="mc" style="margin:0">Kelas</label><select id="mc" style="max-width:220px"><option value="">Semua kelas</option>${D.classes.map((c) => `<option value="${c.classroom_id}" ${String(st.cls) === String(c.classroom_id) ? 'selected' : ''}>${esc(c.name)}</option>`).join('')}</select>
      <span class="mu grow">${ti('refresh')} Diperbarui otomatis tiap 10 detik · ${fdt(D.now, { hour: '2-digit', minute: '2-digit', second: '2-digit' })}</span></div>
    <div class="sc"><table><tr><th>Siswa</th><th>Status</th><th>Terjawab</th><th>Sisa waktu</th><th>Keluar app</th><th>Terakhir aktif</th><th>Nilai</th><th>Aksi</th></tr>
    ${D.rows.map((r) => `<tr><td><b>${esc(r.name)}</b><div class="mu">${esc(r.class)} · ${esc(r.nis)}</div></td>
      <td>${r.status === 'belum' ? pl('Belum mulai') : r.status === 'mengerjakan' ? pl('Mengerjakan', 'wr') : pl('Selesai', 'ok')}</td>
      <td><span class="cb-bar" style="width:80px;display:inline-block;vertical-align:middle"><i style="width:${r.total ? r.answered / r.total * 100 : 0}%"></i></span> ${r.answered}/${r.total}</td>
      <td>${r.left !== null ? clock(r.left) : '—'}</td><td>${r.exits ? pl(r.exits + '×', r.exits >= D.max_exits ? 'er' : 'wr') : '0'}${r.locked ? ' ' + pl(ti('lock') + ' Terkunci', 'er') : ''}${r.restarts ? `<div class="mu" style="font-size:11px">ulang ${r.restarts}×</div>` : ''}</td>
      <td class="mu">${r.last_seen ? fdt(r.last_seen, { hour: '2-digit', minute: '2-digit', second: '2-digit' }) : '—'}</td>
      <td><b>${num(r.score)}</b>${r.essay_pending ? '<div class="mu">essay menunggu</div>' : ''}</td>
      <td>${r.attempt_id ? `<select data-act="${r.attempt_id}" data-name="${esc(r.name)}" aria-label="Aksi untuk ${esc(r.name)}" style="width:auto;padding:5px 8px"><option value="">Aksi…</option>${r.locked ? '<option value="unlock">Buka kunci</option>' : ''}<option value="reset">Reset sesi</option>${r.status === 'mengerjakan' ? '<option value="extend">Tambah 5 menit</option><option value="force">Paksa kirim</option>' : ''}</select>` : ''}</td></tr>`).join('') || '<tr><td colspan="8" class="mu">Belum ada peserta.</td></tr>'}</table></div>`;
    $('#mc', xb).onchange = (e) => { st.cls = e.target.value; draw(); };
    $$('[data-act]', xb).forEach((s) => (s.onchange = async () => {
      const v = s.value; s.value = ''; s.blur(); if (!v) return;
      const msg = { unlock: 'Ujian siswa ini dibuka lagi dan ia bisa melanjutkan dari jawaban terakhir. Waktu tetap berjalan.', reset: 'Semua jawaban siswa ini dihapus dan ia harus memasukkan token lagi. Pakai bila HP rusak/salah akun.', extend: 'Batas waktu siswa ini ditambah 5 menit.', force: 'Jawaban yang sudah tersimpan dikirim sekarang dan nilainya dihitung.' }[v];
      if (!(await ask({ unlock: 'Buka kunci', reset: 'Reset sesi', extend: 'Tambah waktu', force: 'Paksa kirim' }[v] + ' · ' + s.dataset.name + '?', `<p>${msg}</p>`, 'Lanjutkan', v === 'reset' ? 'er' : ''))) return;
      busy(null, async () => { const r = await api(`cbt/attempts/${s.dataset.act}/action`, { method: 'POST', body: { action: v, minutes: 5 } }); toast(r.message); draw(); });
    }));
    return true;
  };
  await draw();
  const iv = setInterval(async () => { try { if (!(await draw())) clearInterval(iv); } catch (_) { /* coba lagi */ } }, 10000);
}

async function recordTab(b, q) {
  const mode = isAdmin() ? q.m || 'ujian' : 'ujian';
  const head = !isAdmin() ? '<div id="rb"></div>' : `<div class="tabs" style="max-width:360px">${[['ujian', 'Per ujian'], ['siswa', 'Per siswa']].map(([v, l]) => `<button type="button" data-m="${v}" class="${mode === v ? 'on' : ''}">${l}</button>`).join('')}</div><div id="rb"></div>`;
  b.innerHTML = head;
  $$('[data-m]', b).forEach((x) => (x.onclick = () => go('cbt', { t: 'record', m: x.dataset.m })));
  const rb = $('#rb', b);
  if (mode === 'siswa') return studentRecord(rb, q);
  const id = await pickExam(rb, q, 'Ujian');
  if (!id) return;
  const R = await api(`cbt/exams/${id}/results`);
  $('#xr', rb).innerHTML = `<a class="btn o sm nf" href="/api/cbt/exams/${id}/export">${ti('download')} Ekspor Excel</a>`;
  $('#xb', rb).innerHTML = `${R.kind === 'uh' && R.grade_lm ? `<div class="alert in">${ti('file-spreadsheet')} Nilai akhir otomatis tertulis di <b>kolom S${R.grade_lm}</b> Daftar Nilai ${esc(R.subject)} semester ini${isAdmin() ? '' : ' — buka menu <a href="#/nilai">Daftar Nilai</a> untuk mengecek atau mengubahnya'}.</div>` : ''}<div class="fg">
      <div class="card cb-tile"><span class="mu">Selesai</span><b class="cb-big">${R.finished}/${R.participants}</b><span class="mu">siswa</span></div>
      <div class="card cb-tile"><span class="mu">Rata-rata</span><b class="cb-big">${num(R.avg)}</b><span class="mu">tertinggi ${num(R.max)} · terendah ${num(R.min)}</span></div>
      ${R.essay.map((x) => `<div class="card cb-tile"><span class="mu">Essay ${esc(x.class)} · ${esc(x.grader || 'belum ada penilai')}</span><b class="cb-big">${x.graded}/${x.finished}</b><span class="mu">sudah dinilai</span></div>`).join('')}</div>
    <div class="two"><div class="card"><b>Analisis soal</b><p class="mu">PG: persentase siswa yang menjawab benar. Merah (&lt; 50%) perlu ditinjau — soal terlalu sulit atau kunci keliru.</p>
      ${R.questions.map((x) => `<div class="cb-an"><span>No. ${x.number}</span>${x.type === 'pg' ? `<span class="cb-bar grow"><i style="width:${x.correct_pct}%;background:${x.correct_pct < 50 ? 'var(--er)' : x.correct_pct < 70 ? 'var(--wr)' : 'var(--ok)'}"></i></span><b>${x.correct_pct}%</b>` : `<span class="grow mu">Essay · rata-rata ${num(x.avg_score)} dari ${num(x.score)}</span>`}</div>`).join('')}</div>
    <div class="sc"><table><tr><th>Siswa</th><th>Kelas</th><th>B/S/K</th><th>PG</th><th>Essay</th><th>Akhir</th></tr>
      ${R.rows.map((r) => `<tr><td>${esc(r.name)}</td><td>${esc(r.class)}</td><td>${r.correct}/${r.wrong}/${r.blank}</td><td>${num(r.pg)}</td><td>${r.pending ? pl('menunggu', 'wr') : num(r.essay)}</td><td><b>${num(r.final)}</b></td></tr>`).join('') || '<tr><td colspan="6" class="mu">Belum ada yang selesai.</td></tr>'}</table></div></div>`;
}

async function studentRecord(rb, q) {
  rb.innerHTML = `<div class="card cb-head"><label for="rs" style="margin:0">Cari siswa</label><input id="rs" type="search" placeholder="Nama atau NIS" value="${esc(q.q || '')}" style="max-width:320px"><span class="grow"></span></div><div id="rl"></div>`;
  const rl = $('#rl', rb);
  const show = async (sid) => {
    const R = await api('cbt/students/' + sid);
    rl.innerHTML = `<div class="card"><b>${esc(R.student.name)}</b> <span class="mu">NIS ${esc(R.student.nis || '-')} · ${esc(R.student.class || '-')}</span></div>
      <div class="two"><div class="sc"><table><tr><th>Ujian</th><th>Tanggal</th><th>B/S/K</th><th>Nilai</th><th>Keluar</th></tr>${R.attempts.map((a) => `<tr><td>${esc(a.exam)}<div class="mu">${esc(a.code)}</div></td><td>${esc(a.date)}</td><td>${a.correct}/${a.wrong}/${a.blank}</td>
        <td><b>${num(a.final ?? a.pg)}</b> ${a.status !== 'selesai' ? pl('mengerjakan', 'wr') : a.pending ? pl('essay menunggu', 'wr') : pl('final', 'ok')}</td><td>${a.exits}×</td></tr>`).join('') || '<tr><td colspan="5" class="mu">Belum pernah ikut ujian.</td></tr>'}</table></div>
      <div class="card"><details class="logbox"><summary><span class="lb-t">Log ujian</span><span class="mu">${R.logs.length} catatan</span><span class="lb-b"><span class="lb-show">Tampilkan</span><span class="lb-hide">Sembunyikan</span></span></summary><div style="margin-top:10px">${R.logs.map((l) => `<div class="cb-log"><span class="mu">${esc(l.at.slice(5, 16))}</span><span class="grow">${esc(l.exam)} · ${esc(l.detail || l.event.replace('_', ' '))}</span></div>`).join('') || '<p class="mu">Belum ada log.</p>'}</div></details></div></div>`;
  };
  const search = async () => {
    const S2 = await api('cbt/students?q=' + encodeURIComponent($('#rs', rb).value.trim()));
    rl.innerHTML = `<div class="list">${S2.map((s) => `<button type="button" class="cb-sb" data-sid="${s.id}"><span class="grow"><b>${esc(s.name)}</b> <span class="mu">${esc(s.nis || '')} · ${esc(s.class || '')}</span></span>${ti('arrow-right')}</button>`).join('') || empty('Siswa tidak ditemukan')}</div>`;
    $$('[data-sid]', rl).forEach((x) => (x.onclick = () => busy(x, () => show(+x.dataset.sid))));
  };
  let t; $('#rs', rb).oninput = () => { clearTimeout(t); t = setTimeout(() => search().catch((x) => toast(x.message, true)), 350); };
  await search();
}

// ════════════════════════════ SISWA ════════════════════════════
export async function cbtStudent(el, q) {
  if (q.a) return examScreen(el, +q.a);
  const E = await api('cbt/my-exams');
  const w = box(el, `<div>${hdr('CBT', 'Ujian dikerjakan di HP ini, di ruang kelas. Token diumumkan pengawas saat ujian dimulai.')}
    ${E.length ? `<div class="list">${E.map((e) => { const a = e.attempt; const done = a?.status === 'selesai';
      return `<div class="card cb-ex"><div class="cb-qh">${done ? pl('Selesai', 'ok') : a ? pl('Sedang dikerjakan', 'wr') : pl(...PHASE[e.phase])}<span class="mu">${fdt(e.starts_at)}–${fdt(e.ends_at, { hour: '2-digit', minute: '2-digit' })}</span></div>
        <b class="cb-et">${esc(e.title)}</b><span class="mu">${esc(e.kind_label || 'Ujian')} · ${esc(e.subject)} · ${esc(e.code)} · ${e.questions} soal · ${e.duration} menit</span>
        ${done ? resultBlock(a, true) : a ? `<button type="button" class="btn w" data-go="${a.id}" style="margin-top:10px">${ti('arrow-right')} Lanjutkan mengerjakan</button>`
          : e.phase === 'berlangsung' ? `<button type="button" class="btn w" data-start="${e.id}" data-subj="${esc(e.subject)}" style="margin-top:10px">${ti('key')} Masukkan token & mulai</button>`
          : e.phase === 'terbit' ? '<p class="mu" style="margin-top:8px">Tombol mulai aktif saat jadwal tiba.</p>' : '<p class="mu" style="margin-top:8px">Jadwal sudah berakhir dan kamu tidak mengerjakan.</p>'}</div>`; }).join('')}</div>`
      : empty('Belum ada ujian', '<p class="mu">Ujian dari sekolah akan muncul di sini, lengkap dengan jadwalnya.</p>')}</div>`);
  $$('[data-go]', w).forEach((b) => (b.onclick = () => go('cbt', { a: b.dataset.go })));
  $$('[data-start]', w).forEach((b) => (b.onclick = () => {
    const ex = E.find((x) => String(x.id) === b.dataset.start);
    const d = dialog('Token ' + b.dataset.subj, `<form id="tf"><p class="mu">Masukkan token yang diumumkan pengawas di kelasmu. Token tiap kelas berbeda.</p>
      <div class="alert ${ex?.exit_action === 'catat' ? 'in' : 'er'}" style="margin:0 0 10px">${ti('lock')} <b>Ujian terkunci.</b> ${exitRule(ex?.exit_action || 'ulang', ex?.exit_allow || 0)} Matikan notifikasi dan jangan menerima telepon selama ujian.</div>
      <input id="tk" class="cb-tokin" maxlength="8" autocomplete="off" autocapitalize="characters" spellcheck="false" aria-label="Token" required>
      <div id="te"></div><button class="btn w">Mulai mengerjakan</button><p class="mu" style="margin-top:8px">${ti('clock')} Waktu dihitung server sejak kamu mulai. Bila sinyal putus, jawaban tetap tersimpan di HP dan kamu bisa lanjut tanpa token lagi.</p></form>`);
    const tk = $('#tk', d); tk.oninput = () => (tk.value = tk.value.toUpperCase().replace(/\s/g, '')); tk.focus();
    $('#tf', d).onsubmit = (e) => { e.preventDefault(); busy($('button', e.target), async () => {
      try { const r = await api(`cbt/my-exams/${b.dataset.start}/start`, { method: 'POST', body: { token: tk.value } }); d.close(); go('cbt', { a: r.attempt_id }); }
      catch (x) { $('#te', d).innerHTML = `<div class="alert er" style="margin:8px 0 0">${esc(x.message)}${x.data?.left !== undefined ? ` (sisa ${x.data.left} percobaan)` : ''}</div>`; tk.select(); }
    }); };
  }));
}
function resultBlock(r, compact = false) {
  const score = r.final_score ?? r.pg_percent;
  return `<div class="cb-res ${compact ? 'sm' : ''}"><div><span class="mu">${r.final_score !== null ? 'Nilai akhir' : r.has_essay ? 'Nilai sementara (PG)' : 'Nilai'}</span><b class="cb-score">${num(score)}</b></div>
    <div class="cb-bsk"><span><b style="color:var(--ok)">${r.correct}</b> benar</span><span><b style="color:var(--er)">${r.wrong}</b> salah</span><span><b>${r.blank}</b> kosong</span></div></div>
    ${r.essay_pending ? `<div class="alert wr" style="margin:8px 0 0">${ti('clock')} Soal essay menunggu dinilai guru. Nilai akhir muncul otomatis dan kamu mendapat notifikasi.</div>` : ''}`;
}

async function examScreen(el, id) {
  let A = await api('cbt/attempts/' + id);
  const key = 'mp_cbt_' + id;
  if (A.status === 'selesai') { try { localStorage.removeItem(key); } catch (_) {} return resultScreen(el, A); }
  if (A.locked) return lockScreen(el, A, id);
  // Penanda keluar aplikasi yang belum dilaporkan (mis. aplikasi ditutup paksa lalu dibuka lagi). Muat ulang halaman (≤ 5 dtk) tidak dihitung.
  const OUT = 'mp_cbt_out_' + id, RL = 'mp_cbt_rl_' + id;
  let leftAt = 0;
  try { leftAt = +localStorage.getItem(OUT) || 0; const rl = +localStorage.getItem(RL) || 0; if (rl && Date.now() - rl < 5000) leftAt = 0; localStorage.removeItem(OUT); localStorage.removeItem(RL); } catch (_) {}
  const off = Date.parse(A.now) - Date.now(); // selisih jam HP vs server
  const deadline = Date.parse(A.deadline);
  const st = { cur: 0, pending: {}, sending: false, finished: false, hiddenAt: 0, exits: A.exits };
  try { Object.assign(st.pending, JSON.parse(localStorage.getItem(key) || '{}')); } catch (_) {}
  const Qs = A.questions;
  for (const [qid, p] of Object.entries(st.pending)) { const q = Qs.find((x) => x.id === +qid); if (q && p.rev > q.rev) Object.assign(q, { choice: p.choice ?? null, text: p.text ?? null, flagged: !!p.flagged, rev: p.rev }); }
  const first = Qs.findIndex((x) => x.choice === null && !x.text); st.cur = first < 0 ? 0 : first;
  document.body.classList.add('cb-exam');
  const w = box(el, `<div class="cb-x"><div class="cb-top"><div class="grow"><b>${esc(A.exam.subject)}</b><div class="mu" id="sv">${ti('check')} Tersimpan</div></div>
      <span class="cb-timer" id="tm">--:--</span><button type="button" class="btn o sm nf" id="gr" aria-label="Daftar soal">${ti('dashboard')}</button></div>
    <div id="qx"></div>
    <div class="cb-foot"><button type="button" class="btn o nf" id="pv" aria-label="Soal sebelumnya">${ti('arrow-left')}</button><button type="button" class="btn nf grow" id="nx"></button></div></div>`);
  const persist = () => { try { localStorage.setItem(key, JSON.stringify(st.pending)); } catch (_) {} };
  const setSv = () => { const n = Object.keys(st.pending).length; const s = $('#sv', w); if (s) s.innerHTML = n ? (navigator.onLine ? 'Menyimpan…' : `${ti('alert-triangle')} Offline · ${n} jawaban disimpan di HP`) : `${ti('check')} Tersimpan`; };
  const flush = async () => {
    if (st.sending || st.finished) return; st.sending = true;
    try {
      for (const [qid, p] of Object.entries(st.pending)) {
        const r = await api(`cbt/attempts/${id}/answer`, { method: 'PUT', body: { question_id: +qid, ...p } });
        if (st.pending[qid]?.rev === p.rev) delete st.pending[qid];
        persist(); if (r.finished) break;
      }
    } catch (x) {
      if (x.status === 409) { st.finished = true; toast(x.message, true); return go('cbt', { a: id }); }
      if (x.status === 423) { st.finished = true; return examScreen(el, id); }
    } finally { st.sending = false; setSv(); }
  };
  const answer = (q, patch) => { Object.assign(q, patch); q.rev = (q.rev || 0) + 1; st.pending[q.id] = { choice: q.choice, text: q.text, flagged: q.flagged, rev: q.rev }; persist(); setSv(); flush(); };
  let essayT;
  const draw = () => {
    const q = Qs[st.cur];
    const act = A.exam.exit_action || 'ulang', allow = A.exam.exit_allow || 0;
    $('#qx', w).innerHTML = `<div class="alert ${act === 'catat' ? 'in' : st.exits ? 'er' : 'wr'} cb-rule" style="margin:0 0 10px">${ti('lock')} ${exitRule(act, allow)}${st.exits ? ` <b>Kamu sudah keluar ${st.exits}×${act !== 'catat' && allow ? ` (toleransi ${allow}×)` : ''}.</b>` : ''}${A.restarts ? ` Mengulang ${A.restarts}×.` : ''}</div>
      <div class="cb-qh"><b>Soal ${st.cur + 1} dari ${Qs.length}</b>${pl(q.type === 'pg' ? 'Pilihan ganda' : 'Essay', q.type === 'pg' ? 'ok' : 'wr')}</div>
      ${q.body ? `<div class="cb-body">${esc(q.body)}</div>` : ''}${q.image_url ? `<img class="cb-img" src="${esc(q.image_url)}" alt="Gambar soal ${st.cur + 1}, ketuk untuk memperbesar" data-zoom>` : ''}
      ${q.type === 'pg' ? `<div class="cb-sopts" role="radiogroup" aria-label="Pilihan jawaban">${q.options.map((o, pos) => `<button type="button" role="radio" aria-checked="${q.choice === o.i}" class="cb-sopt ${q.choice === o.i ? 'sel' : ''}" data-ch="${o.i}"><span class="cb-lt">${L[pos]}</span><span class="grow">${esc(o.text)}</span>${o.image_url ? `<img src="${esc(o.image_url)}" alt="Gambar pilihan ${L[pos]}">` : ''}</button>`).join('')}</div>`
        : `<textarea id="es" rows="8" maxlength="10000" placeholder="Tulis jawabanmu di sini. Tersimpan otomatis.">${esc(q.text || '')}</textarea>`}
      <label class="item cb-flag ${q.flagged ? 'on' : ''}"><input type="checkbox" id="fl" ${q.flagged ? 'checked' : ''}> Ragu-ragu</label>`;
    $$('[data-ch]', w).forEach((b) => (b.onclick = () => { answer(q, { choice: +b.dataset.ch }); draw(); }));
    const es = $('#es', w); if (es) es.oninput = () => { q.text = es.value; clearTimeout(essayT); essayT = setTimeout(() => answer(q, { text: es.value }), 800); };
    $('#fl', w).onchange = (e) => { answer(q, { flagged: e.target.checked }); draw(); };
    $('#pv', w).disabled = st.cur === 0;
    const last = st.cur === Qs.length - 1; const nx = $('#nx', w);
    nx.innerHTML = last ? `${ti('check')} Selesai & kirim` : `Berikutnya ${ti('arrow-right')}`; nx.classList.toggle('ok', last);
  };
  const go2 = (i) => { const es = $('#es', w); if (es) { clearTimeout(essayT); const q = Qs[st.cur]; if ((q.text || '') !== es.value || st.pending[q.id]) answer(q, { text: es.value }); } st.cur = i; draw(); scrollTo(0, 0); };
  const answered = (q) => q.type === 'pg' ? q.choice !== null && q.choice !== undefined : !!(q.text || '').trim();
  const finish = async (auto = false) => {
    if (st.finished) return;
    const es = $('#es', w); if (es) { const q = Qs[st.cur]; if ((q.text || '') !== es.value) answer(q, { text: es.value }); }
    if (!auto) {
      const empty2 = Qs.filter((q) => !answered(q)).length; const fl = Qs.filter((q) => q.flagged).length;
      if (!(await ask('Kirim jawaban sekarang?', `<p>${empty2 ? `<b style="color:var(--er)">${empty2} soal belum dijawab.</b> ` : ''}${fl ? fl + ' soal ditandai ragu-ragu. ' : ''}Setelah dikirim, jawaban tidak bisa diubah.</p>`, 'Kirim', 'ok'))) return;
    }
    for (let i = 0; i < 3 && Object.keys(st.pending).length; i++) { await flush(); if (Object.keys(st.pending).length) await new Promise((r) => setTimeout(r, 1500)); }
    if (Object.keys(st.pending).length && !auto) return toast('Belum tersambung. Jawaban aman di HP ini; coba kirim lagi setelah sinyal kembali.', true);
    try { const r = await api(`cbt/attempts/${id}/finish`, { method: 'POST' }); st.finished = true; try { localStorage.removeItem(key); } catch (_) {} if (auto) toast('Waktu habis. Jawaban terkirim otomatis.'); resultScreen(el, { ...A, result: r.result }); }
    catch (x) { if (x.status === 409 || x.status === 422) { st.finished = true; return go('cbt', { a: id }); } toast(x.message, true); }
  };
  $('#pv', w).onclick = () => go2(st.cur - 1);
  $('#nx', w).onclick = () => st.cur === Qs.length - 1 ? finish() : go2(st.cur + 1);
  $('#gr', w).onclick = () => {
    const d = dialog('Daftar soal', `<div class="cb-ngrid">${Qs.map((q, i) => `<button type="button" class="${q.flagged ? 'flag' : answered(q) ? 'ans' : ''} ${i === st.cur ? 'cur' : ''}" data-i="${i}">${i + 1}</button>`).join('')}</div>
      <div class="row" style="margin-top:10px">${pl('terjawab', 'ok')}${pl('ragu-ragu', 'wr')}${pl('kosong')}</div><button type="button" class="btn w ok" id="fin">${ti('check')} Selesai & kirim</button>`);
    $$('[data-i]', d).forEach((b) => (b.onclick = () => { d.close(); go2(+b.dataset.i); }));
    $('#fin', d).onclick = () => { d.close(); finish(); };
  };
  // Timer server + sinyal keluar aplikasi untuk pengawas.
  const tick = () => {
    if (!w.isConnected) { clearInterval(iv); document.body.classList.remove('cb-exam'); document.removeEventListener('visibilitychange', vis); removeEventListener('blur', onBlur); removeEventListener('focus', back); removeEventListener('beforeunload', onRl); if (document.fullscreenElement) document.exitFullscreen?.().catch(() => {}); return; }
    const left = (deadline - (Date.now() + off)) / 1000; const t = $('#tm', w);
    t.textContent = clock(left); t.classList.toggle('low', left < 300);
    if (left <= 0 && !st.finished) finish(true);
  };
  // ── Kunci ujian: keluar / minimize / pindah aplikasi / pindah jendela (laptop) → dilaporkan & dikenai sanksi server ──
  const mobile = /Android|iPhone|iPad|Mobile/i.test(navigator.userAgent);
  const report = async (secs) => {
    if (st.finished || st.reporting) return; st.reporting = true;
    try {
      const r = await api(`cbt/attempts/${id}/event`, { method: 'POST', body: { type: 'keluar', seconds: secs } });
      st.exits = r.exits;
      if (r.action === 'ulang') {
        st.finished = true; st.pending = {}; try { localStorage.removeItem(key); } catch (_) {}
        const d = dialog('Jawaban dihapus', `<p>Kamu keluar dari aplikasi saat ujian (${st.exits}×). Sesuai aturan, <b>semua jawabanmu dihapus</b> dan kamu mengulang dari soal 1. Waktu tetap berjalan.</p>
          <button type="button" class="btn w er" data-ok>Mulai dari soal 1</button>`);
        $('[data-ok]', d).onclick = () => d.close();
        d.addEventListener('close', () => examScreen(el, id), { once: true });
        return;
      }
      if (r.action === 'kunci' || r.locked) { st.finished = true; return examScreen(el, id); }
      draw();
    } catch (_) { /* gagal kirim: penanda tetap disimpan dan dilaporkan saat tersambung */ } finally { st.reporting = false; }
  };
  const away = () => { if (st.finished || st.awayAt) return; st.awayAt = Date.now(); try { localStorage.setItem(OUT, String(st.awayAt)); } catch (_) {} };
  const back = () => {
    if (st.finished || !w.isConnected || !st.awayAt || document.hidden || (!mobile && !document.hasFocus())) return;
    const gone = Date.now() - st.awayAt; st.awayAt = 0; try { localStorage.removeItem(OUT); } catch (_) {}
    if (gone > 1500) report(Math.round(gone / 1000));
    flush();
  };
  const vis = () => { if (st.finished || !w.isConnected) return; document.hidden ? away() : back(); };
  const onBlur = () => { if (!mobile) away(); };
  const onRl = () => { try { localStorage.setItem(RL, String(Date.now())); } catch (_) {} };
  document.addEventListener('visibilitychange', vis);
  addEventListener('blur', onBlur); addEventListener('focus', back); addEventListener('beforeunload', onRl);
  // Layar penuh pada sentuhan pertama (butuh gestur pengguna); bila keluar layar penuh, diminta masuk lagi.
  const fs = () => { const d = document.documentElement; if (!document.fullscreenElement && d.requestFullscreen) d.requestFullscreen({ navigationUI: 'hide' }).catch(() => {}); };
  w.addEventListener('click', fs);
  addEventListener('online', () => flush());
  const iv = setInterval(() => { tick(); if (Object.keys(st.pending).length && !st.sending && navigator.onLine) flush(); }, 1000);
  draw(); tick(); setSv(); if (Object.keys(st.pending).length) flush();
  if (leftAt && Date.now() - leftAt > 1500) report(Math.round((Date.now() - leftAt) / 1000));
}

/** Ujian terkunci karena keluar aplikasi: soal disembunyikan sampai pengawas membuka. */
function lockScreen(el, A, id) {
  document.body.classList.add('cb-exam');
  const w = box(el, `<div class="cb-x"><div class="card cb-locked"><span class="cb-lock-ic">${ti('lock')}</span><b>Ujian terkunci</b>
    <p>Kamu keluar dari aplikasi saat mengerjakan <b>${esc(A.exam.subject)}</b> (${A.exits}×). Jawaban yang sudah tersimpan aman.</p>
    <p class="mu">Angkat tangan dan minta <b>pengawas</b> membuka kunci dari menu Pantau. Waktu ujian tetap berjalan.</p>
    <button type="button" class="btn w" id="ck">${ti('refresh')} Cek lagi</button></div></div>`);
  $('#ck', w).onclick = () => examScreen(el, id);
  const iv = setInterval(async () => {
    if (!w.isConnected) return clearInterval(iv);
    try { const r = await api('cbt/attempts/' + id); if (!r.locked || r.status === 'selesai') { clearInterval(iv); examScreen(el, id); } } catch (_) {}
  }, 8000);
}

function resultScreen(el, A) {
  document.body.classList.remove('cb-exam');
  box(el, `<div>${hdr('Hasil ujian', `${esc(A.exam.title)} · ${esc(A.exam.code)}`)}
    <div class="card" style="max-width:520px;text-align:center">${pl('Jawaban terkirim', 'ok')}${resultBlock(A.result)}
    <p class="mu" style="margin-top:10px">Kunci jawaban per soal tidak ditampilkan selama ujian mapel ini masih berlangsung.</p>
    <a class="btn w" href="#/cbt">Kembali ke daftar ujian</a></div></div>`);
}
