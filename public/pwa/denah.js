// Denah interaktif sekolah — diadaptasi dari "ParamaEdu Smart School Map Editor v1".
// Mode Lihat (semua peran, semua paket) dan Mode Editor (admin paket All Service / tim Parama Tech).
// Data: GET/PUT /api/school-map (per sekolah). Dipakai di Informasi → Sarpras dan halaman #/denah.
const { api, esc, nf, toast, S, BOOT } = window.MP;
const ti = (n, cls = '') => `<svg class="ti ${cls}" aria-hidden="true"><use href="/pwa/ti.svg?v=2#${n}"/></svg>`;
const NS = 'http://www.w3.org/2000/svg';
const MIN_W = 600, MIN_H = 320, GRID = 5;
const CAT_COLOR = { lab: '#d8d2f6', kantor: '#f8d9c4', fasilitas: '#e3e8ef' };
const GRADE_COLORS = ['#c6e7ef', '#fff0a6', '#d9e9c4', '#f6d4e4', '#d4e2fb', '#fde2b8'];

/** Pasang denah di elemen `el`. opts.compact = tampilan ringkas di dalam tab Sarpras. */
export async function mountMap(el, opts = {}) {
  el.innerHTML = '<div class="mu" style="padding:12px">Memuat denah…</div>';
  let data;
  try { data = await api('school-map'); } catch (e) { el.innerHTML = `<div class="mu">${esc(e.message)}</div>`; return; }

  const st = { rooms: data.rooms.map((r) => ({ ...r })), building: null, floor: 1, sel: null, edit: false, zoom: 1, dirty: false, drag: null, q: '' };
  const grades = data.grades || [];
  const colorOf = (r) => r.category === 'kelas' ? GRADE_COLORS[Math.max(0, grades.indexOf(r.grade)) % GRADE_COLORS.length] : (CAT_COLOR[r.category] || CAT_COLOR.fasilitas);
  const buildings = () => [...new Set(st.rooms.map((r) => r.building))].sort((a, b) => a.localeCompare(b, 'id', { numeric: true }));
  const floors = () => { const f = st.rooms.filter((r) => r.building === st.building).map((r) => r.floor); return [...new Set([1, ...f])].sort((a, b) => a - b); };
  const here = () => st.rooms.filter((r) => r.building === st.building && r.floor === st.floor);
  const byId = (id) => st.rooms.find((r) => String(r.id) === String(id));
  st.building = buildings()[0] || 'A';

  // Siswa: langsung tampilkan gedung & lantai kelasnya.
  const myClass = S.user?.student?.classroom?.id;
  const mine = myClass && st.rooms.find((r) => r.classroom_id === myClass);
  if (mine) { st.building = mine.building; st.floor = mine.floor; st.sel = mine.id; }

  if (!st.rooms.length && !data.can_edit) {
    el.innerHTML = `<div class="dn-empty">${ti('building')}<b>Denah interaktif belum dibuat</b>
      <span class="mu">${data.is_admin ? `Menyusun denah tersedia di paket ${esc(data.editor_plan)}, atau minta tim Parama Tech membuatkannya. Sementara itu, lampirkan denah JPG/PDF di informasi Sarpras.` : 'Sekolah belum memasang denah interaktif.'}</span>
      ${data.is_admin ? `<a class="btn o" href="${BOOT.portal.billing}">${ti('credit-card')} Lihat paket</a>` : ''}</div>`;
    return;
  }

  el.innerHTML = `<div class="dn ${opts.compact ? 'dn-compact' : ''}">
    <div class="dn-stats"></div>
    <div class="dn-bar">
      <div class="dn-pills" role="tablist" aria-label="Gedung"></div>
      <div class="dn-tools">
        <label class="dn-floor"><span class="mu">Lantai</span><select aria-label="Lantai"></select></label>
        <input class="dn-search" type="search" placeholder="Cari ruang / kelas…" aria-label="Cari ruang">
        <button type="button" class="btn o sm" data-z="-" title="Perkecil">−</button><span class="dn-zoom">100%</span><button type="button" class="btn o sm" data-z="+" title="Perbesar">+</button>
        <button type="button" class="btn o sm" data-act="print" title="Cetak denah lantai ini">${ti('printer')}</button>
        ${data.can_edit ? `<button type="button" class="btn sm" data-act="mode">${ti('settings')} Mode editor</button>`
          : data.is_admin ? `<a class="btn o sm dn-lock" href="${BOOT.portal.billing}" title="Editor denah tersedia di paket ${esc(data.editor_plan)}">${ti('lock')} Edit denah · ${esc(data.editor_plan)}</a>` : ''}
      </div>
    </div>
    <div class="dn-main">
      <div class="dn-canvas"><svg role="img" aria-label="Denah sekolah"></svg><div class="dn-hint"></div></div>
      <aside class="dn-side card"></aside>
    </div>
    <div class="dn-legend"></div>
    <div class="dn-save" hidden><span class="mu dn-status">Belum ada perubahan.</span>
      <button type="button" class="btn o sm" data-act="add">${ti('plus')} Tambah ruang</button>
      <button type="button" class="btn o sm" data-act="building">${ti('building')} Gedung baru</button>
      <button type="button" class="btn sm" data-act="save">Simpan denah</button></div>
  </div>`;
  const q = (s) => el.querySelector(s);
  const svg = q('.dn-canvas svg');

  function stats() {
    const kelas = st.rooms.filter((r) => r.category === 'kelas');
    const cap = st.rooms.reduce((a, r) => a + Number(r.capacity || 0), 0);
    q('.dn-stats').innerHTML = [['Ruang', st.rooms.length], ['Ruang kelas', kelas.length], ['Gedung', buildings().length || 1], ['Kapasitas', nf(cap)]]
      .map(([l, v]) => `<div><span class="mu">${l}</span><b>${v}</b></div>`).join('');
  }
  function bar() {
    q('.dn-pills').innerHTML = (buildings().length ? buildings() : [st.building]).map((b) => `<button type="button" role="tab" aria-selected="${b === st.building}" class="${b === st.building ? 'on' : ''}" data-b="${esc(b)}">Gedung ${esc(b)}</button>`).join('');
    const fl = floors(); if (st.edit) for (let i = Math.max(...fl) + 1; i <= Math.min(10, Math.max(...fl) + 1); i++) fl.push(i);
    q('.dn-floor select').innerHTML = fl.map((f) => `<option value="${f}" ${f === st.floor ? 'selected' : ''}>${f}</option>`).join('');
    q('.dn-zoom').textContent = Math.round(st.zoom * 100) + '%';
    q('.dn-hint').textContent = st.edit ? 'Mode editor: geser ruang untuk memindahkan, tarik titik di sudut kanan bawah untuk mengubah ukuran.' : (here().length ? 'Ketuk ruang untuk melihat detail.' : 'Belum ada ruang di lantai ini.');
    const mode = q('[data-act="mode"]'); if (mode) mode.innerHTML = st.edit ? `${ti('eye')} Mode lihat` : `${ti('settings')} Mode editor`;
    q('.dn-save').hidden = !st.edit;
    el.querySelector('.dn').classList.toggle('editing', st.edit);
    const keys = new Set(st.rooms.map((r) => r.category === 'kelas' ? 'g:' + (r.grade || '') : r.category));
    const used = [...grades.map((g) => 'g:' + g), 'g:', ...Object.keys(data.categories).filter((c) => c !== 'kelas')].filter((k) => keys.has(k));
    q('.dn-legend').innerHTML = used.map((k) => {
      const [label, color] = k.startsWith('g:') ? [k.slice(2) ? 'Kelas ' + k.slice(2) : 'Ruang kelas', colorOf({ category: 'kelas', grade: k.slice(2) })] : [data.categories[k] || k, CAT_COLOR[k] || CAT_COLOR.fasilitas];
      return `<span><i style="background:${color}"></i>${esc(label)}</span>`;
    }).join('');
  }
  function draw() {
    const rs = here();
    const W = Math.max(MIN_W, ...rs.map((r) => r.x + r.width + 40)), H = Math.max(MIN_H, ...rs.map((r) => r.y + r.height + 40));
    svg.setAttribute('viewBox', `0 0 ${W} ${H}`);
    svg.style.width = (st.zoom * 100) + '%';
    const term = st.q.trim().toLowerCase();
    svg.innerHTML = `<defs><pattern id="dn-grid" width="25" height="25" patternUnits="userSpaceOnUse"><path d="M25 0H0V25" fill="none" class="dn-gridline"/></pattern></defs><rect width="${W}" height="${H}" fill="url(#dn-grid)"/>`;
    for (const r of rs) {
      const g = document.createElementNS(NS, 'g');
      const hit = term && (r.name.toLowerCase().includes(term) || (r.classroom || '').toLowerCase().includes(term));
      g.setAttribute('class', 'dn-room' + (String(r.id) === String(st.sel) ? ' sel' : '') + (hit ? ' hit' : '') + (r.classroom_id && r.classroom_id === myClass ? ' mine' : ''));
      g.dataset.id = r.id;
      const cx = r.x + r.width / 2, cy = r.y + r.height / 2, fs = Math.max(11, Math.min(16, r.width / 9));
      const sub = r.students != null ? `${r.students} siswa` : (r.capacity ? `${r.capacity} kursi` : '');
      g.innerHTML = `<rect x="${r.x}" y="${r.y}" width="${r.width}" height="${r.height}" rx="8" fill="${colorOf(r)}"/>
        <text x="${cx}" y="${cy - (sub ? 5 : -4)}" font-size="${fs}" class="dn-t">${esc(r.name)}</text>${sub ? `<text x="${cx}" y="${cy + 14}" class="dn-s">${esc(sub)}</text>` : ''}
        ${st.edit && String(r.id) === String(st.sel) ? `<rect class="dn-handle" data-resize="1" x="${r.x + r.width - 8}" y="${r.y + r.height - 8}" width="16" height="16" rx="3"/>` : ''}`;
      svg.append(g);
    }
  }
  function side() {
    const r = byId(st.sel), box = q('.dn-side');
    if (!r) { box.innerHTML = `<div class="dn-none">${ti('building')}<b>Pilih ruang</b><span class="mu">${st.edit ? 'Pilih ruang untuk mengubah, atau tambah ruang baru.' : 'Ketuk salah satu ruang pada denah untuk melihat detailnya.'}</span></div>`; return; }
    const catLabel = r.category === 'kelas' && r.grade ? 'Kelas ' + r.grade : (data.categories[r.category] || r.category);
    if (!st.edit) {
      box.innerHTML = `<span class="mu" style="font-size:12px">${esc(catLabel)}</span><h3 style="margin:2px 0 10px">${esc(r.name)}</h3>
        <div class="kv"><span class="mu">Lokasi</span><span>Gedung ${esc(r.building)} · Lantai ${r.floor}</span>
        ${r.classroom ? `<span class="mu">Kelas</span><span>${esc(r.classroom)}</span>` : ''}
        ${r.students != null ? `<span class="mu">Siswa</span><span>${r.students} siswa</span>` : ''}
        ${r.capacity ? `<span class="mu">Kapasitas</span><span>${r.capacity}</span>` : ''}</div>`;
      return;
    }
    const opts = (list, cur) => list.map(([v, l]) => `<option value="${esc(v)}" ${String(v) === String(cur ?? '') ? 'selected' : ''}>${esc(l)}</option>`).join('');
    box.innerHTML = `<form class="dn-form"><b>Ubah ruang</b>
      <label>Nama ruang</label><input name="name" maxlength="100" required value="${esc(r.name)}">
      <div class="dn-2"><div><label>Gedung</label><input name="building" maxlength="20" required value="${esc(r.building)}" list="dn-blist"></div>
        <div><label>Lantai</label><select name="floor">${opts([...Array(10)].map((_, i) => [i + 1, 'Lantai ' + (i + 1)]), r.floor)}</select></div></div>
      <datalist id="dn-blist">${buildings().map((b) => `<option value="${esc(b)}">`).join('')}</datalist>
      <div class="dn-2"><div><label>Jenis</label><select name="category">${opts(Object.entries(data.categories), r.category)}</select></div>
        <div class="dn-grade" ${r.category === 'kelas' ? '' : 'hidden'}><label>Tingkat</label><select name="grade"><option value="">-</option>${opts(grades.map((g) => [g, g]), r.grade)}</select></div></div>
      <div class="dn-cls" ${r.category === 'kelas' ? '' : 'hidden'}><label>Tautkan ke kelas</label><select name="classroom_id"><option value="">Tidak ditautkan</option>${opts(data.classrooms.map((c) => [c.id, `${c.name} (${c.students} siswa)`]), r.classroom_id)}</select></div>
      <label>Kapasitas</label><input name="capacity" type="number" min="0" max="2000" value="${r.capacity || 0}">
      <div class="dn-2"><div><label>Lebar</label><input name="width" type="number" min="30" value="${Math.round(r.width)}"></div><div><label>Tinggi</label><input name="height" type="number" min="30" value="${Math.round(r.height)}"></div></div>
      <div class="dn-2"><div><label>Posisi X</label><input name="x" type="number" min="0" value="${Math.round(r.x)}"></div><div><label>Posisi Y</label><input name="y" type="number" min="0" value="${Math.round(r.y)}"></div></div>
      <div class="dn-acts"><button type="button" class="btn er sm" data-act="del">${ti('trash')} Hapus</button><button type="button" class="btn o sm" data-act="dup">Duplikat</button><button class="btn sm">Terapkan</button></div></form>`;
    const f = box.querySelector('form');
    f.category.onchange = () => { const k = f.category.value === 'kelas'; f.querySelector('.dn-grade').hidden = !k; f.querySelector('.dn-cls').hidden = !k; };
    f.classroom_id.onchange = () => {
      const c = data.classrooms.find((x) => String(x.id) === f.classroom_id.value);
      if (c) { if (!f.name.value || f.name.value === 'Ruang Baru') f.name.value = c.name; if (c.grade) f.grade.value = c.grade; }
    };
    f.onsubmit = (e) => {
      e.preventDefault();
      const fd = Object.fromEntries(new FormData(f));
      Object.assign(r, { name: fd.name.trim(), building: fd.building.trim() || r.building, floor: +fd.floor, category: fd.category,
        grade: fd.category === 'kelas' ? (fd.grade || null) : null, classroom_id: fd.category === 'kelas' && fd.classroom_id ? +fd.classroom_id : null,
        capacity: +fd.capacity || 0, width: Math.max(30, +fd.width), height: Math.max(30, +fd.height), x: Math.max(0, +fd.x), y: Math.max(0, +fd.y) });
      const c = data.classrooms.find((x) => x.id === r.classroom_id); r.classroom = c?.name || null; r.students = c ? c.students : null;
      st.building = r.building; st.floor = r.floor; dirty(); all(); toast('Ruang diperbarui.');
    };
    box.querySelector('[data-act="del"]').onclick = () => { if (!confirm(`Hapus "${r.name}" dari denah?`)) return; st.rooms = st.rooms.filter((x) => x !== r); st.sel = null; dirty(); all(); };
    box.querySelector('[data-act="dup"]').onclick = () => { const c = { ...r, id: 'n' + Date.now(), name: r.name + ' (salinan)', x: r.x + 20, y: r.y + 20, classroom_id: null, classroom: null, students: null }; st.rooms.push(c); st.sel = c.id; dirty(); all(); };
  }
  function dirty() { st.dirty = true; q('.dn-status').textContent = 'Ada perubahan yang belum disimpan.'; q('.dn-status').classList.add('warn'); }
  function all() { stats(); bar(); draw(); side(); }

  // Interaksi kanvas: pilih, geser, ubah ukuran (pointer events → HP & mouse).
  const pt = (e) => { const p = svg.createSVGPoint(); p.x = e.clientX; p.y = e.clientY; return p.matrixTransform(svg.getScreenCTM().inverse()); };
  svg.addEventListener('pointerdown', (e) => {
    const g = e.target.closest('.dn-room'); if (!g) return;
    const r = byId(g.dataset.id); st.sel = r.id;
    if (st.edit) { e.preventDefault(); svg.setPointerCapture(e.pointerId); st.drag = { r, p: pt(e), o: { x: r.x, y: r.y, w: r.width, h: r.height }, resize: !!e.target.dataset.resize, moved: false }; }
    draw(); side();
  });
  svg.addEventListener('pointermove', (e) => {
    const d = st.drag; if (!d) return;
    const p = pt(e), dx = p.x - d.p.x, dy = p.y - d.p.y, snap = (v) => Math.round(v / GRID) * GRID;
    if (Math.abs(dx) + Math.abs(dy) > 2) d.moved = true;
    if (d.resize) { d.r.width = Math.max(30, snap(d.o.w + dx)); d.r.height = Math.max(30, snap(d.o.h + dy)); } else { d.r.x = Math.max(0, snap(d.o.x + dx)); d.r.y = Math.max(0, snap(d.o.y + dy)); }
    draw();
  });
  const end = () => { if (st.drag?.moved) { dirty(); side(); } st.drag = null; };
  svg.addEventListener('pointerup', end); svg.addEventListener('pointercancel', end);

  el.addEventListener('click', async (e) => {
    const b = e.target.closest('[data-b]'); if (b) { st.building = b.dataset.b; st.floor = floors()[0]; st.sel = null; all(); return; }
    const z = e.target.closest('[data-z]'); if (z) { st.zoom = Math.min(2, Math.max(0.5, st.zoom + (z.dataset.z === '+' ? 0.1 : -0.1))); bar(); draw(); return; }
    const a = e.target.closest('[data-act]')?.dataset.act; if (!a) return;
    if (a === 'mode') { if (st.edit && st.dirty && !confirm('Ada perubahan yang belum disimpan. Tetap keluar dari mode editor? Perubahan tetap ada sampai halaman ditutup.')) return; st.edit = !st.edit; all(); }
    if (a === 'print') printFloor();
    if (a === 'add') { const r = { id: 'n' + Date.now(), classroom_id: null, classroom: null, students: null, building: st.building, floor: st.floor, name: 'Ruang Baru', category: 'kelas', grade: grades[0] || null, capacity: 0, x: 40, y: 40, width: 160, height: 100 }; st.rooms.push(r); st.sel = r.id; dirty(); all(); }
    if (a === 'building') { const n = (prompt('Nama gedung baru (mis. E atau Gedung Praktik):') || '').trim().slice(0, 20); if (!n) return; st.building = n; st.floor = 1; const r = { id: 'n' + Date.now(), classroom_id: null, classroom: null, students: null, building: n, floor: 1, name: 'Ruang Baru', category: 'fasilitas', grade: null, capacity: 0, x: 40, y: 40, width: 160, height: 100 }; st.rooms.push(r); st.sel = r.id; dirty(); all(); }
    if (a === 'save') {
      if (!st.dirty) return toast('Belum ada perubahan.');
      if (!confirm(`Simpan denah (${st.rooms.length} ruang)? Ruang yang dihapus akan hilang permanen.`)) return;
      const btn = e.target.closest('button'); btn.disabled = true;
      try {
        const body = { rooms: st.rooms.map(({ id, classroom_id, building, floor, name, category, grade, capacity, x, y, width, height }) => ({ ...(Number.isInteger(id) ? { id } : {}), classroom_id, building, floor, name, category, grade, capacity, x, y, width, height })) };
        const r = await api('school-map', { method: 'PUT', body });
        st.rooms = r.rooms; data.classrooms = r.classrooms; st.dirty = false; st.sel = null;
        q('.dn-status').textContent = 'Tersimpan.'; q('.dn-status').classList.remove('warn'); toast(r.message); all();
      } catch (x) { toast(x.message, true); } finally { btn.disabled = false; }
    }
  });
  q('.dn-floor select').onchange = (e) => { st.floor = +e.target.value; st.sel = null; all(); };
  q('.dn-search').oninput = (e) => {
    st.q = e.target.value; const t = st.q.trim().toLowerCase();
    const m = t && st.rooms.find((r) => r.name.toLowerCase().includes(t) || (r.classroom || '').toLowerCase().includes(t));
    if (m) { st.building = m.building; st.floor = m.floor; st.sel = m.id; bar(); side(); }
    draw();
  };
  window.addEventListener('beforeunload', (e) => { if (st.dirty && document.body.contains(el)) { e.preventDefault(); e.returnValue = ''; } });

  function printFloor() {
    const w = window.open('', '_blank'); if (!w) return toast('Izinkan pop-up untuk mencetak.', true);
    const clone = svg.cloneNode(true); clone.style.width = '100%';
    w.document.write(`<!doctype html><title>Denah ${esc(BOOT.school.name)}</title><style>body{font-family:system-ui,sans-serif;margin:24px}h1{font-size:18px;margin:0 0 4px}p{margin:0 0 12px;color:#555}.dn-t{font-weight:700;fill:#1f2937;text-anchor:middle}.dn-s{font-size:11px;fill:#475569;text-anchor:middle}.dn-gridline{stroke:#eef2f6}.dn-room rect{stroke:#94a3b8;stroke-width:1.5}.dn-handle{display:none}</style>
      <h1>${esc(BOOT.school.name)}</h1><p>Denah Gedung ${esc(st.building)} · Lantai ${st.floor}</p>${clone.outerHTML}`);
    w.document.close();
    // Tanpa <script> inline (diblokir CSP di jendela turunan): cetak dipicu dari jendela ini.
    setTimeout(() => { w.focus(); w.print(); }, 150);
  }
  all();
}
