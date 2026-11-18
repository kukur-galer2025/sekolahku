/* MyPresence PWA — Daftar Nilai Sumatif (portal guru).
 * Format mengikuti "DAFTAR NILAI SUMATIF" sekolah:
 *   Sumatif Lingkup Materi (LM 1–n, n diatur guru 1–10) → NA Sumatif (S)
 *   STS (Non Tes, Tes) → NA STS · SAS (Non Tes, Tes) → NA SAS
 *   Nilai Rapor = rerata (S, STS, SAS), dibulatkan.
 * Rumus sama persis dengan App\Services\Grades\SummativeMath (server).
 * Nilai tersimpan otomatis; bisa tempel (paste) blok nilai langsung dari Excel.
 */
const { api, $, $$, esc, hdr, empty, toast, go } = window.MP;
const ti = (n) => window.ti(n);
const FIELDS = ['sts_nontes', 'sts_tes', 'sas_nontes', 'sas_tes'];

const avg = (a) => { const v = a.filter((x) => x !== null && x !== undefined && x !== ''); return v.length ? v.reduce((s, x) => s + +x, 0) / v.length : null; };
const r2 = (x) => (x === null ? null : Math.round(x * 100) / 100);
export function compute(e, n) {
  const lm = Array.from({ length: n }, (_, i) => e.lm[i] ?? null);
  const s = avg(lm), sts = avg([e.sts_nontes, e.sts_tes]), sas = avg([e.sas_nontes, e.sas_tes]);
  const parts = [s, sts, sas].filter((x) => x !== null);
  const filled = lm.filter((x) => x !== null).length + FIELDS.filter((f) => e[f] !== null && e[f] !== undefined).length;
  return { na_s: r2(s), na_sts: r2(sts), na_sas: r2(sas), rapor: parts.length ? Math.round(parts.reduce((a, b) => a + b, 0) / parts.length) : null, complete: filled === n + 4 };
}
const fmt = (v) => (v === null || v === undefined || v === '' ? '' : String(+(+v).toFixed(2)).replace('.', ','));
/** "85,5" / "85.5" → 85.5; kosong → null; tidak sah → NaN. */
const parse = (t) => { t = String(t ?? '').trim().replace(',', '.'); if (t === '') return null; const v = Number(t); return Number.isFinite(v) && v >= 0 && v <= 100 && /^\d{1,3}(\.\d{1,2})?$/.test(t) ? v : NaN; };

export async function daftarNilai(el, q) {
  if (q.b) return book(el, +q.b);
  const L = await api('grade-books' + (q.term ? '?term=' + encodeURIComponent(q.term) : ''));
  el.innerHTML = hdr('Daftar Nilai', 'Nilai sumatif per kelas dan mata pelajaran yang kamu ampu')
    + `<div class="card row" style="align-items:end"><div style="flex:0 1 240px"><label for="dnT">Semester</label><select id="dnT">${L.terms.map((t) => `<option ${t === L.term ? 'selected' : ''}>${esc(t)}</option>`).join('')}</select></div>
      <p class="mu" style="flex:1;margin:0 0 6px">Kelas & mapel diambil dari jadwal mengajarmu. Pilih salah satu untuk mengisi nilai.</p></div>`
    + (L.books.length ? `<div class="fg">${L.books.map((b, i) => `<button type="button" class="card dn-card" data-i="${i}">
        <span class="dn-ic">${ti('file-spreadsheet')}</span><b>${esc(b.classroom.name)}</b><span>${esc(b.subject)}</span>
        ${b.teacher ? `<span class="mu">${esc(b.teacher)}</span>` : ''}
        <span class="mu" style="font-size:12px">${b.book_id ? `${b.filled}/${b.students} siswa terisi` : `${b.students} siswa · belum diisi`}</span>
        <span class="dn-bar"><i style="width:${b.students ? Math.round((b.filled / b.students) * 100) : 0}%"></i></span></button>`).join('')}</div>`
      : `<div class="card">${empty('Belum ada kelas yang diampu', 'Daftar kelas muncul dari jadwal mengajar. Minta admin mengimpor jadwal terlebih dahulu.')}</div>`);
  $('#dnT', el).onchange = (e) => go('nilai', { term: e.target.value });
  $$('[data-i]', el).forEach((c) => (c.onclick = async () => {
    const b = L.books[+c.dataset.i];
    if (b.book_id) return go('nilai', { b: b.book_id });
    c.disabled = true;
    try { const r = await api('grade-books', { method: 'POST', body: { classroom_id: b.classroom.id, subject: b.subject, term: L.term } }); go('nilai', { b: r.id }); }
    catch (x) { toast(x.message, true); c.disabled = false; }
  }));
}

async function book(el, id) {
  let B = await api('grade-books/' + id);
  const dirty = new Set();
  let timer = null, saving = false;

  const draw = () => {
    const n = B.lm_count, ro = !B.can_edit;
    const inp = (r, f, v) => `<input class="gb-in" data-r="${r}" data-f="${f}" value="${fmt(v)}" inputmode="decimal" autocomplete="off" ${ro ? 'disabled' : ''} aria-label="${f}">`;
    const lmName = (i) => B.lm_labels[i] || 'Sumatif ' + (i + 1);
    el.innerHTML = `<p style="margin-bottom:10px"><a class="btn o sm" href="#/nilai?term=${encodeURIComponent(B.term)}">← Daftar Nilai</a></p>`
      + hdr(`Nilai Sumatif · ${esc(B.subject)}`, `Kelas ${esc(B.classroom.name)} · Semester ${esc(B.term)} · Fase ${esc(B.phase || '-')}${ro ? ' · ' + esc(B.teacher?.name || '') : ''}`,
        `<span class="gb-st mu" id="gbS">${ro ? 'Hanya lihat' : 'Tersimpan otomatis'}</span><a class="btn o nf" href="/api/grade-books/${B.id}/export">${ti('download')} Ekspor Excel</a>`)
      + (ro ? '' : `<details class="card gb-set"><summary><b>${ti('settings')} Pengaturan</b> <span class="mu">Fase, jumlah & nama lingkup materi</span></summary>
        <form id="gbF"><div class="row"><div style="flex:0 1 110px"><label for="gbP">Fase</label><input id="gbP" name="phase" maxlength="8" value="${esc(B.phase || '')}" placeholder="E"></div>
          <div style="flex:0 1 200px"><label for="gbN">Jumlah lingkup materi</label><select id="gbN" name="lm_count">${Array.from({ length: 10 }, (_, i) => `<option ${i + 1 === n ? 'selected' : ''}>${i + 1}</option>`).join('')}</select></div></div>
          <div class="gb-lms" id="gbL">${Array.from({ length: n }, (_, i) => `<div><label>LM ${i + 1}</label><input name="lm" maxlength="80" value="${esc(B.lm_labels[i] || '')}" placeholder="Sumatif ${i + 1}"></div>`).join('')}</div>
          <button class="btn" style="margin-top:10px">Simpan pengaturan</button></form></details>`)
      + `<div class="card"><p class="mu" style="margin:0 0 8px;font-size:12px">${ti('info-circle')} NA Sumatif = rerata LM · NA STS / NA SAS = rerata Non Tes & Tes · <b>Nilai Rapor = rerata (S, STS, SAS)</b>, dibulatkan. Nilai 0–100, koma boleh (85,5).${ro ? '' : ' Bisa tempel blok nilai dari Excel. Enter = turun.'}</p>
        <div class="gb-wrap"><table class="gb"><thead>
          <tr><th rowspan="3">No</th><th rowspan="3">NIS</th><th rowspan="3" class="gb-nm">Nama Peserta Didik</th><th colspan="${n + 1}">Sumatif Lingkup Materi</th><th colspan="3">Sumatif Tengah Semester (STS)</th><th colspan="3">Sumatif Akhir Semester (SAS)</th><th rowspan="3" class="gb-rp">Nilai Rapor<br><span class="mu">Rerata S + STS + SAS</span></th></tr>
          <tr>${Array.from({ length: n }, (_, i) => `<th>LM ${i + 1}</th>`).join('')}<th rowspan="2" class="gb-na">NA Sumatif (S)</th><th rowspan="2">Non Tes</th><th rowspan="2">Tes</th><th rowspan="2" class="gb-na">NA STS</th><th rowspan="2">Non Tes</th><th rowspan="2">Tes</th><th rowspan="2" class="gb-na">NA SAS</th></tr>
          <tr>${Array.from({ length: n }, (_, i) => `<th class="gb-lm" title="${esc(lmName(i))}">${esc(lmName(i))}</th>`).join('')}</tr></thead><tbody>
          ${B.students.map((s, r) => { const c = s.calc; return `<tr data-row="${r}"><td>${r + 1}</td><td>${esc(s.nis)}</td><td class="gb-nm">${esc(s.name)}</td>
            ${Array.from({ length: n }, (_, i) => `<td>${inp(r, 'lm' + i, s.entry.lm[i])}</td>`).join('')}<td class="gb-na" data-c="na_s">${fmt(c.na_s)}</td>
            <td>${inp(r, 'sts_nontes', s.entry.sts_nontes)}</td><td>${inp(r, 'sts_tes', s.entry.sts_tes)}</td><td class="gb-na" data-c="na_sts">${fmt(c.na_sts)}</td>
            <td>${inp(r, 'sas_nontes', s.entry.sas_nontes)}</td><td>${inp(r, 'sas_tes', s.entry.sas_tes)}</td><td class="gb-na" data-c="na_sas">${fmt(c.na_sas)}</td>
            <td class="gb-rp" data-c="rapor"><b>${fmt(c.rapor)}</b></td></tr>`; }).join('')}
          </tbody><tfoot><tr><td colspan="3"><b>Rata-rata kelas</b></td>${Array.from({ length: n }, (_, i) => `<td data-avg="lm${i}"></td>`).join('')}<td data-avg="na_s"></td>
            <td data-avg="sts_nontes"></td><td data-avg="sts_tes"></td><td data-avg="na_sts"></td><td data-avg="sas_nontes"></td><td data-avg="sas_tes"></td><td data-avg="na_sas"></td><td data-avg="rapor"></td></tr></tfoot></table></div>
        <p class="mu" id="gbC" style="margin:8px 0 0;font-size:12px"></p></div>`;
    if (!B.students.length) $('.gb-wrap', el).insertAdjacentHTML('afterend', empty('Belum ada siswa aktif di kelas ini.'));
    totals();
    bind();
  };

  const totals = () => {
    const n = B.lm_count, S = B.students;
    const col = (get) => fmt(r2(avg(S.map(get))));
    for (let i = 0; i < n; i++) $(`[data-avg="lm${i}"]`, el).textContent = col((s) => s.entry.lm[i]);
    FIELDS.forEach((f) => ($(`[data-avg="${f}"]`, el).textContent = col((s) => s.entry[f])));
    ['na_s', 'na_sts', 'na_sas', 'rapor'].forEach((k) => ($(`[data-avg="${k}"]`, el).textContent = col((s) => s.calc[k])));
    const done = S.filter((s) => s.calc.complete).length;
    $('#gbC', el).textContent = `${done} dari ${S.length} siswa nilainya lengkap.`;
  };
  const status = (t, cls = 'mu') => { const x = $('#gbS', el); if (x) { x.textContent = t; x.className = 'gb-st ' + cls; } };

  const save = async () => {
    clearTimeout(timer);
    if (saving || !dirty.size) return;
    const bad = $$('.gb-in.bad', el).map((i) => +i.dataset.r);
    const rows = [...dirty].filter((r) => !bad.includes(r));
    if (!rows.length) { status('Perbaiki nilai yang merah (0–100)', 'er'); return; }
    saving = true; status('Menyimpan…');
    rows.forEach((r) => dirty.delete(r));
    try {
      await api('grade-books/' + B.id + '/entries', { method: 'PUT', body: { rows: rows.map((r) => { const s = B.students[r]; return { student_id: s.id, lm: s.entry.lm.slice(0, 10), ...Object.fromEntries(FIELDS.map((f) => [f, s.entry[f]])) }; }) } });
      status(bad.length ? 'Tersimpan · ada nilai merah belum disimpan' : 'Tersimpan ✓', bad.length ? 'er' : 'ok');
    } catch (x) {
      rows.forEach((r) => dirty.add(r)); status('Gagal menyimpan · coba lagi', 'er'); toast(x.message, true);
    } finally { saving = false; if (dirty.size) timer = setTimeout(save, 2500); }
  };
  const later = () => { clearTimeout(timer); status('Belum tersimpan…'); timer = setTimeout(save, 1200); };

  const setCell = (input, text) => {
    const r = +input.dataset.r, f = input.dataset.f, s = B.students[r];
    const v = parse(text);
    input.classList.toggle('bad', Number.isNaN(v));
    if (Number.isNaN(v)) return;
    if (f.startsWith('lm')) s.entry.lm[+f.slice(2)] = v; else s.entry[f] = v;
    s.calc = compute(s.entry, B.lm_count);
    const tr = input.closest('tr');
    ['na_s', 'na_sts', 'na_sas'].forEach((k) => ($(`[data-c="${k}"]`, tr).textContent = fmt(s.calc[k])));
    $('[data-c="rapor"]', tr).innerHTML = `<b>${fmt(s.calc.rapor)}</b>`;
    dirty.add(r);
  };

  function bind() {
    if (B.can_edit) {
      const f = $('#gbF', el);
      $('#gbN', el).onchange = (e) => {
        const n = +e.target.value, old = $$('input[name="lm"]', f).map((x) => x.value);
        $('#gbL', el).innerHTML = Array.from({ length: n }, (_, i) => `<div><label>LM ${i + 1}</label><input name="lm" maxlength="80" value="${esc(old[i] || B.lm_labels[i] || '')}" placeholder="Sumatif ${i + 1}"></div>`).join('');
      };
      f.onsubmit = async (e) => {
        e.preventDefault();
        await save();
        const n = +f.lm_count.value;
        const lost = B.students.some((s) => s.entry.lm.slice(n).some((x) => x !== null));
        if (lost && !confirm(`Kolom LM ${n + 1} ke atas sudah berisi nilai. Nilai itu tidak ikut dihitung (tetap tersimpan bila jumlah LM dinaikkan lagi). Lanjutkan?`)) return;
        try {
          const r = await api('grade-books/' + B.id, { method: 'PUT', body: { phase: f.phase.value.trim() || null, lm_count: n, lm_labels: $$('input[name="lm"]', f).map((x) => x.value) } });
          toast(r.message); B = r; draw();
        } catch (x) { toast(x.message, true); }
      };
    }
    $$('.gb-in', el).forEach((input) => {
      input.oninput = () => { setCell(input, input.value); totals(); later(); };
      input.onblur = () => { if (!input.classList.contains('bad')) input.value = fmt(parse(input.value)); };
      input.onkeydown = (e) => {
        const r = +input.dataset.r, f = input.dataset.f;
        const go2 = (dr) => { const t = $(`.gb-in[data-r="${r + dr}"][data-f="${f}"]`, el); if (t) { e.preventDefault(); t.focus(); t.select(); } };
        if (e.key === 'Enter' || e.key === 'ArrowDown') go2(1);
        if (e.key === 'ArrowUp') go2(-1);
      };
      // Tempel blok dari Excel: baris = siswa, kolom = urutan kolom input mulai dari sel ini.
      input.onpaste = (e) => {
        const text = e.clipboardData?.getData('text/plain') || '';
        if (!/[\t\n]/.test(text.trim())) return;
        e.preventDefault();
        const order = [...Array.from({ length: B.lm_count }, (_, i) => 'lm' + i), ...FIELDS];
        const r0 = +input.dataset.r, c0 = order.indexOf(input.dataset.f);
        text.replace(/\r/g, '').replace(/\n$/, '').split('\n').forEach((line, dr) => line.split('\t').forEach((cell, dc) => {
          const t = $(`.gb-in[data-r="${r0 + dr}"][data-f="${order[c0 + dc]}"]`, el);
          if (t) { t.value = cell.trim(); setCell(t, t.value); }
        }));
        totals(); later();
      };
    });
  }

  draw();
  // Simpan sisa perubahan saat pindah halaman / menutup aplikasi.
  const flush = () => { if (dirty.size) save(); };
  addEventListener('hashchange', flush, { once: true });
  addEventListener('pagehide', flush, { once: true });
}
