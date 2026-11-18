/* MyPresence PWA — inti: API, router, tata letak, login, profil.
 * ES module tanpa build step. Halaman staf di pages.js, halaman siswa di student.js.
 */
export const BOOT = JSON.parse(document.getElementById('boot').textContent);
/** Ikon Tabler (MIT) dari sprite /pwa/ti.svg. Global agar dipakai juga oleh pages/features/student.js. */
export const ti = (n, cls = '') => `<svg class="ti ${cls}" aria-hidden="true"><use href="/pwa/ti.svg?v=2#${n}"/></svg>`;
globalThis.ti = ti;
const V = new URL(import.meta.url).search; // ?v=… diteruskan ke modul lain agar versi selalu cocok

export const S = { user: null, route: 'dash', query: {}, offline: !navigator.onLine, installEvt: null };
export const $ = (s, r = document) => r.querySelector(s);
export const $$ = (s, r = document) => [...r.querySelectorAll(s)];
/** Inisial nama (tanpa gelar di belakang koma). */
export const initials = (n) => String(n || '?').replace(/,.*$/, '').split(/\s+/).filter(Boolean).slice(0, 2).map((w) => w[0]).join('').toUpperCase();
/** Foto profil kecil (bisa dilihat sesama pengguna sekolah); tanpa foto → inisial. */
export const avatar = (name, url, cls = 'pa') => url
  ? `<img class="${cls}" src="${esc(url)}" alt="" loading="lazy" decoding="async">`
  : `<span class="${cls}" aria-hidden="true">${esc(initials(name))}</span>`;
/** Nama dengan foto profil di depannya. */
export const withAvatar = (name, url, inner) => `<span class="pn">${avatar(name, url)}<span>${inner ?? `<b>${esc(name)}</b>`}</span></span>`;
export const esc = (v) => String(v ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
/**
 * Teks berformat ala WhatsApp (aman: di-escape dulu): *tebal* atau **tebal**, _miring_, ~coret~.
 * Dipakai untuk isi Informasi & Agenda.
 */
const B = String.raw`(^|[\s(>"'])`, E = String.raw`(?=$|[\s.,!?;:)<"'])`;
export const rich = (v) => esc(v)
  .replace(/\*\*(?=\S)([^*\n]+?)\*\*/g, '<b>$1</b>')
  .replace(new RegExp(B + String.raw`\*(?=\S)([^*\n]*?\S)\*` + E, 'gm'), '$1<b>$2</b>')
  .replace(new RegExp(B + String.raw`_(?=\S)([^_\n]*?\S)_` + E, 'gm'), '$1<i>$2</i>')
  .replace(new RegExp(B + String.raw`~(?=\S)([^~\n]*?\S)~` + E, 'gm'), '$1<s>$2</s>');
export const nf = (n) => Number(n || 0).toLocaleString('id-ID');
const BULAN = ['Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des'];
export const fd = (d) => { if (!d) return '-'; const [y, m, x] = String(d).slice(0, 10).split('-'); return `${+x} ${BULAN[+m - 1]} ${y}`; };
const HARI = ['Minggu', 'Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu'];
/** Tenggat tugas: "Senin, 16 Nov 2026 · 14.00". Tugas lama tanpa jam = 23.59. */
export const dueText = (d, t) => { if (!d) return '-'; const [y, m, x] = String(d).slice(0, 10).split('-'); return `${HARI[new Date(+y, +m - 1, +x).getDay()]}, ${fd(d)} · ${(t || '23:59').slice(0, 5).replace(':', '.')}`; };
const nowHm = () => new Intl.DateTimeFormat('en-GB', { timeZone: BOOT.school.timezone || 'Asia/Jakarta', hour: '2-digit', minute: '2-digit', hour12: false }).format(new Date());
/** Sudah lewat tenggat (tanggal + jam, zona waktu sekolah)? */
export const pastDue = (d, t) => `${d} ${(t || '23:59').slice(0, 5)}` < `${todayStr()} ${nowHm()}`;
export const todayStr = () => new Intl.DateTimeFormat('en-CA', { timeZone: BOOT.school.timezone || 'Asia/Jakarta' }).format(new Date());

// ── Toast & dialog ────────────────────────────────────────────────
let toastT;
export function toast(msg, err = false) {
  const t = $('#toast'); t.textContent = msg; t.className = 'on' + (err ? ' er' : '');
  clearTimeout(toastT); toastT = setTimeout(() => (t.className = ''), 3200);
}
export function dialog(title, html, onMount) {
  const d = document.createElement('dialog');
  d.innerHTML = `<div class="dh"><b>${esc(title)}</b><button class="x" aria-label="Tutup">×</button></div><div class="db">${html}</div>`;
  document.body.append(d);
  d.querySelector('.x').onclick = () => d.close();
  d.addEventListener('close', () => d.remove());
  d.showModal();
  onMount?.(d);
  return d;
}

// ── API ───────────────────────────────────────────────────────────
export class ApiError extends Error { constructor(msg, status, data) { super(msg); this.status = status; this.data = data; } }
const xsrf = () => decodeURIComponent((document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/) || [])[1] || '');

export async function api(path, { method = 'GET', body, form, retry = true } = {}) {
  const headers = { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' };
  if (method !== 'GET') headers['X-XSRF-TOKEN'] = xsrf();
  if (body !== undefined) headers['Content-Type'] = 'application/json';
  let res;
  try {
    res = await fetch('/api/' + path, { method, headers, credentials: 'same-origin', body: form ?? (body !== undefined ? JSON.stringify(body) : undefined) });
  } catch (e) {
    setOffline(true);
    throw new ApiError(method === 'GET' ? 'Tidak ada koneksi dan data belum tersimpan di HP ini.' : 'Tidak ada koneksi internet. Coba lagi setelah tersambung.', 0);
  }
  if (method === 'GET') setOffline(res.headers.get('X-MP-Offline') === '1');
  if (res.status === 419 && retry) { await fetch('/api/school', { credentials: 'same-origin' }); return api(path, { method, body, form, retry: false }); }
  if (res.status === 204) return null;
  const data = await res.json().catch(() => ({}));
  if (res.status === 401) { S.user = null; showLogin(); throw new ApiError('Sesi berakhir. Silakan masuk lagi.', 401); }
  if (res.status === 402) { showLocked(data.message); throw new ApiError(data.message, 402); }
  if (!res.ok) {
    const first = data.errors ? Object.values(data.errors)[0]?.[0] : null;
    throw new ApiError(first || data.message || 'Terjadi kesalahan (' + res.status + ').', res.status, data);
  }
  return data;
}

function setOffline(on) {
  on = on || !navigator.onLine;
  if (S.offline === on) return;
  S.offline = on;
  const b = $('#offbar'); if (b) b.hidden = !on;
}
addEventListener('online', () => setOffline(false));
addEventListener('offline', () => setOffline(true));

// ── Navigasi per peran ────────────────────────────────────────────
const NAV = {
  admin: [['dash', 'Dashboard', 'home'], ['qr', 'Absensi Masuk Kelas', 'camera'], ['guru', 'Data Guru', 'chalkboard-teacher'], ['kartuguru', 'Kartu Login Guru', 'id-badge-2'], ['siswa', 'Data Siswa', 'users'], ['jurnal', 'Jurnal', 'notebook'],
    ['jadwal', 'Jadwal KBM', 'calendar-week'], ['cbt', 'CBT', 'device-desktop'], ['leger', 'Leger Rapor', 'chart-bar'], ['pembiasaan', 'Pembiasaan', 'books'],
    ['library', 'MP-Library', 'book'], ['wali', 'Wali Kelas', 'presentation'], ['info', 'Agenda', 'calendar-event'], ['pengaturan', 'Pengaturan Presensi', 'settings'], ['setelan', 'Pengaturan Sekolah', 'settings'], ['denah', 'Denah Sekolah', 'building'], ['profil', 'Profil', 'user']],
  teacher: [['dash', 'Dashboard', 'home'], ['qr', 'Absensi Masuk Kelas', 'camera'], ['jurnal', 'Jurnal', 'notebook'], ['tugas', 'Penugasan', 'clipboard-text'], ['nilai', 'Daftar Nilai', 'file-spreadsheet'], ['jadwal', 'Jadwal Mengajar', 'calendar-week'],
    ['info', 'Agenda', 'calendar-event'], ['cbt', 'CBT', 'device-desktop'], ['wali', 'Wali Kelas', 'presentation'], ['leger', 'Leger Rapor', 'chart-bar'], ['pembiasaan', 'Pembiasaan', 'books'],
    ['library', 'MP-Library', 'book'], ['denah', 'Denah Sekolah', 'building'], ['profil', 'Profil', 'user']],
  student: [['dash', 'Dashboard', 'home'], ['scan', 'Absensi Masuk Kelas', 'camera'], ['rekap', 'Rekap Absensi', 'chart-bar'], ['jadwal', 'Jadwal Pelajaran', 'calendar-week'], ['denah', 'Denah Sekolah', 'building'], ['jkelas', 'Jurnal Kelas', 'notebook'],
    ['tugas', 'Tugas-Ku', 'clipboard-text'], ['cbt', 'CBT', 'device-desktop'], ['info', 'Informasi', 'speakerphone'], ['pembiasaan', 'Pembiasaan', 'books'], ['struktur', 'Struktur Kelas', 'hierarchy-2'], ['kas', 'Kas Kelas', 'receipt'], ['piket', 'Jadwal Piket', 'spray'],
    ['library', 'MP-Library', 'book'], ['profil', 'Profil', 'user']],
};
/**
 * Menu & istilah per sekolah (diatur super-admin di panel → detail sekolah): ganti nama per portal & sembunyikan menu fitur.
 * NAV_DEFAULT menyimpan nama bawaan untuk mengganti judul halaman yang masih memakai nama lama.
 */
const NAV_DEFAULT = Object.fromEntries(Object.entries(NAV).map(([r, items]) => [r, Object.fromEntries(items.map(([k, l]) => [k, l]))]));
(() => {
  const M = BOOT.school.menu || {}, names = M.names || {}, hide = new Set(M.hidden || []);
  for (const role of Object.keys(NAV)) NAV[role] = NAV[role].filter(([k]) => !hide.has(k)).map(([k, l, i]) => [k, names[k]?.[role] || l, i]);
})();
/** Guru non-wali tidak melihat Wali Kelas & Leger Rapor (alur prototipe). */
/** Alumni (siswa lulus): hanya rekap kehadiran & profil, sampai akun nonaktif otomatis. */
const isAlumni = (u) => u.role === 'student' && u.student?.status === 'lulus';
/** Fitur di luar paket langganan sekolah: guru & siswa tidak melihat menunya; admin melihatnya bergembok + ajakan upgrade. */
const LOCKED = new Set(BOOT.school.locked || []);
export const isLocked = (r) => LOCKED.has(r);
const navOf = (u) => isAlumni(u) ? NAV.student.filter(([r]) => r === 'rekap' || r === 'profil')
  : NAV[u.role].filter(([r]) => !(u.role === 'teacher' && (r === 'wali' || r === 'leger') && !u.teacher?.is_homeroom))
    .filter(([r]) => u.role === 'admin' || !isLocked(r))
    .filter(([r]) => r !== 'denah' || u.role === 'admin' || BOOT.school.has_map);
function lockedPage(el, label) {
  el.innerHTML = hdr(label) + `<div class="card" style="max-width:560px"><b>${ti('lock')} Fitur ini belum termasuk paket ${esc(BOOT.school.plan_label || '')}</b>
    <p class="mu" style="margin:8px 0 14px">${esc(label)} tersedia di paket yang lebih tinggi. Upgrade paket kapan saja; data sekolah tetap aman.</p>
    <a class="btn" href="${BOOT.portal.billing}">${ti('credit-card')} Lihat paket langganan</a></div>`;
}
const TABS = { admin: ['dash', 'qr', 'siswa', 'jadwal'], teacher: ['dash', 'qr', 'jurnal', 'jadwal'], student: ['dash', 'scan', 'jadwal', 'tugas'], alumni: ['rekap', 'profil'] };
/**
 * Menu admin dikelompokkan (sama dengan sidebar portal Blade di resources/views/portal/layout.blade.php).
 * Item berawalan 'portal:' membuka halaman portal (BOOT.portal[...]); sisanya rute PWA.
 * Menu yang jarang dipakai ada di halaman "Pengaturan Sekolah"; fitur di luar paket diringkas jadi satu baris.
 */
const ADMIN_MENU = [
  [null, ['dash', 'qr', 'jadwal', 'info']],
  ['Data sekolah', ['guru', 'kartuguru', 'siswa', ['portal:classrooms', 'Kelas & Rombel', 'building'], ['portal:imports', 'Impor Data', 'file-import']]],
  ['Akademik', ['jurnal', 'wali', 'leger', 'cbt', 'pembiasaan', 'library']], // Agenda & Informasi digabung di menu "Agenda"
  ['Pengaturan', ['setelan', ['portal:billing', 'Langganan', 'credit-card']]],
];
/** Isi halaman Pengaturan Sekolah: [tujuan, judul, keterangan, ikon]. */
const SETTINGS = [
  ['#/pengaturan', 'Pengaturan Presensi', 'Hari sekolah, batas jam masuk, mode QR, WiFi sekolah.', 'settings'],
  ['portal:print_qr', 'Cetak QR Kelas', 'QR yang ditempel di tiap kelas untuk absensi siswa.', 'qrcode'],
  ['portal:school', 'Profil Sekolah', 'Nama, logo, NPSN, alamat, dan kontak sekolah.', 'id-badge-2'],
  ['portal:share', 'Bagikan Aplikasi', 'Link, kode sekolah, dan poster untuk guru & siswa.', 'share'],
  ['portal:year', 'Tahun Ajaran & Kelulusan', 'Tutup tahun ajaran, naik kelas, kelulusan, mutasi.', 'school'],
  ['portal:privacy', 'Data & Privasi', 'Persetujuan orang tua, ekspor & penghapusan data (UU PDP).', 'shield-lock'],
  ['portal:audit', 'Log Akses', 'Riwayat masuk dan tindakan penting di akun sekolah.', 'history'],
  ['#/profil', 'Profil Saya', 'Nama, foto, password, dan verifikasi 2 langkah akun Anda.', 'user'],
];
const hrefOf = (t) => t.startsWith('portal:') ? BOOT.portal[t.slice(7)] : t;
function settingsPage(el) {
  el.innerHTML = hdr('Pengaturan Sekolah', 'Pengaturan yang jarang diubah, dikumpulkan di satu tempat.')
    + `<div class="set-grid">${SETTINGS.map(([t, l, d, i]) => `<a class="card set-card" href="${hrefOf(t)}"><span class="set-ic">${ti(i)}</span><span><b>${esc(l)}</b><span class="mu">${esc(d)}</span></span></a>`).join('')}</div>`;
}

let PAGES = {};
async function loadPages() {
  if (Object.keys(PAGES).length) return;
  window.MP = { api, S, $, $$, esc, avatar, withAvatar, initials, rich, nf, fd, dueText, pastDue, hdr, empty, err, toast, dialog, go, render, BOOT, todayStr, pwFields, bindPasswordForm, installHint };
  window.MP.camera = await import('./camera.js' + V);
  const staff = await import('./pages.js' + V);
  window.MP.timetableHtml = staff.timetableHtml;
  // Menu prototipe yang dilengkapi: absensi guru, CBT, leger, pembiasaan, MP-Library, struktur, piket, Tugas-Ku per folder.
  const extra = await import('./features.js' + V);
  if (S.user.role === 'student') {
    const student = await import('./student.js' + V);
    PAGES = { ...COMMON, agenda: staff.PAGES.agenda, info: staff.PAGES.info, ...student.PAGES, ...extra.studentPages(student.PAGES) };
  } else {
    PAGES = { ...COMMON, ...staff.PAGES, ...extra.staffPages(staff.PAGES), setelan: settingsPage };
  }
  // Kas Kelas (siswa): modul terpisah, dimuat saat dibuka.
  if (S.user.role === 'student') PAGES.kas = async (el) => (await import('./kas.js' + V)).kasPage(el);
  PAGES.denah = async (el) => {
    el.innerHTML = hdr('Denah Sekolah', 'Letak ruang kelas, laboratorium, dan fasilitas sekolah') + '<div class="card" id="dnBox"><div class="mu">Memuat denah…</div></div>';
    await staff.denahCard($('#dnBox', el));
  };
}

export function go(route, query = {}) {
  const qs = new URLSearchParams(query).toString();
  location.hash = '#/' + route + (qs ? '?' + qs : '');
}
function parseHash() {
  const [r, q] = location.hash.replace(/^#\/?/, '').split('?');
  S.route = r || 'dash';
  S.query = Object.fromEntries(new URLSearchParams(q || ''));
}

export function hdr(title, sub = '', right = '') {
  return `<div class="hdr"><div><h2>${esc(title)}</h2>${sub ? `<p class="sub">${sub}</p>` : ''}</div>${right ? `<div class="row" style="flex:none">${right}</div>` : ''}</div>`;
}
export const empty = (t, s = '') => `<div class="empty"><b>${esc(t)}</b>${s}</div>`;
export const err = (e) => `<div class="alert er">${esc(e.message || e)}</div>`;

/** Sidebar admin berkelompok. Menu terkunci paket diringkas jadi satu baris ke halaman Langganan. */
function adminSide(nav) {
  const byKey = Object.fromEntries(nav.map((n) => [n[0], n]));
  const link = (x) => {
    if (Array.isArray(x)) return `<a class="nv" href="${hrefOf(x[0])}">${ti(x[2], 'ic')}${esc(x[1])}</a>`;
    const n = byKey[x];
    if (!n || isLocked(x)) return '';
    const on = S.route === x || (x === 'setelan' && S.route === 'pengaturan');
    return `<a class="nv ${on ? 'on' : ''}" href="#/${x}">${ti(n[2], 'ic')}${esc(n[1])}</a>`;
  };
  const groups = ADMIN_MENU.map(([title, items]) => {
    const html = items.map(link).join('');
    return html ? `${title ? `<div class="grp">${esc(title)}</div>` : ''}${html}` : '';
  }).join('');
  const lockedN = [...LOCKED].filter((k) => byKey[k]).length;
  return groups + (lockedN ? `<a class="nv nv-locked" href="${BOOT.portal.billing}">${ti('lock', 'ic')}<span>Fitur Pro & All Service<small>${lockedN} fitur · lihat paket</small></span></a>` : '');
}

/** Menu avatar (kanan atas): profil, tema, keluar. */
function userMenu(u, ini) {
  const av = u.photo_url ? `<img class="av" src="${esc(u.photo_url)}" alt="" style="object-fit:cover">` : `<span class="av">${esc(ini)}</span>`;
  return `<details class="um"><summary aria-label="Menu akun">${av}</summary><div class="um-pop" role="menu">
    <div class="um-head"><b>${esc(u.name)}</b><span class="mu">${esc(u.role_label)}</span></div>
    <a href="#/profil" role="menuitem">${ti('user', 'ic')}Profil saya</a>
    <button type="button" data-act="theme" role="menuitem">${ti('contrast-2', 'ic')}Ganti tema</button>
    <button type="button" data-act="logout" role="menuitem">${ti('logout', 'ic')}Keluar</button></div></details>`;
}
document.addEventListener('click', (e) => {
  // Tutup menu avatar saat klik di luar atau setelah memilih item.
  $$('details.um[open]').forEach((d) => { if (!d.contains(e.target) || e.target.closest('.um-pop a,.um-pop button')) d.open = false; });
});

function layout() {
  const u = S.user, role = u.role, nav = navOf(u), school = BOOT.school;
  const ini = u.name.split(' ').filter(Boolean).slice(0, 2).map((w) => w[0]).join('').toUpperCase();
  const item = ([r, l, i]) => `<a class="nv ${S.route === r ? 'on' : ''}" href="#/${r}">${ti(i, 'ic')}${esc(l)}${isLocked(r) ? `<span style="margin-left:auto;opacity:.6" title="Tidak termasuk paket">${ti('lock')}</span>` : ''}</a>`;
  const tab = (r) => { const n = nav.find((x) => x[0] === r); return `<a class="${S.route === r ? 'on' : ''}" href="#/${r}">${ti(n[2], 'ic')}${esc(n[1].split(' ')[0])}</a>`; };
  return `<div class="offline" id="offbar" ${S.offline ? '' : 'hidden'}>Mode offline · menampilkan data terakhir yang tersimpan. Presensi & simpan data butuh internet.</div>
  <input type="checkbox" id="nav" hidden>
  <div class="app">
    <label for="nav" class="scrim" aria-hidden="true"></label>
    <nav class="side" aria-label="Menu utama">
      <div class="brand"><span class="brand-logo"><img src="/img/logo-vertical.svg" alt="ParamaEdu.Tech" width="584" height="308"></span><div class="brand-copy"><strong>My<span>Presence</span></strong><small>${esc(school.name)}</small></div></div>
      ${role === 'admin' ? adminSide(nav) : nav.map(item).join('')}
      ${role === 'admin' ? '' : `<div class="side-bottom">
        <button class="nv" data-act="theme" type="button">${ti('contrast-2', 'ic')}Tema</button>
        <button class="nv" data-act="logout" type="button">${ti('logout', 'ic')}Keluar</button>
      </div>`}
    </nav>
    <div class="main">
      <header class="top">
        <div class="who"><label for="nav" class="menu" aria-label="Buka menu">${ti('menu-2')}</label>
          ${school.logo ? `<img src="${esc(school.logo)}" alt="" style="width:32px;height:32px;object-fit:contain;border-radius:8px;background:#fff;flex:none">` : ''}
          <span class="portal-heading"><b>${esc(school.name)}</b><small>${esc(u.role_label)}${u.student?.classroom ? ' · ' + esc(u.student.classroom.name) : ''}</small></span></div>
        <div class="who"><span class="chip hm">${esc(u.role_label)}</span>
          <a href="#/notif" aria-label="Pemberitahuan" style="position:relative;font-size:20px;text-decoration:none;line-height:1;display:inline-grid;place-items:center">${ti('bell')}<span id="nbadge" hidden style="position:absolute;top:-6px;right:-8px;min-width:18px;height:18px;padding:0 5px;border-radius:9px;background:#dc2626;color:#fff;font-size:11px;font-weight:700;line-height:18px;text-align:center"></span></a>
          ${userMenu(u, ini)}</div>
      </header>
      <main class="ct" id="page" tabindex="-1"></main>
    </div>
  </div>
  <nav class="tabbar" aria-label="Menu cepat">${TABS[isAlumni(u) ? 'alumni' : role].filter((r) => nav.some((n) => n[0] === r) && !isLocked(r)).map(tab).join('')}<label for="nav">${ti('menu-2', 'ic')}Menu</label></nav>`;
}

export async function render() {
  if (!S.user) return showLogin();
  if (BOOT.app && (APP_OF[S.user.role] || 'admin') !== BOOT.app) return showWrongApp(APP_OF[S.user.role] || 'admin');
  if (S.user.must_change_password) return showForcePassword();
  if (S.user.two_factor_setup_required) return showTwoFactorSetup();
  parseHash();
  // Agenda sekolah & briefing dihapus dari semua portal: tautan lama #/agenda (notifikasi, dashboard) membuka Informasi.
  if (S.route === 'agenda') { go('info'); return; }
  const nav = navOf(S.user);
  if (!nav.some((n) => n[0] === S.route) && S.route !== 'notif') S.route = nav[0][0];
  await loadPages();
  document.documentElement.dataset.portal = APP_OF[S.user.role] || 'admin';
  const roleKey = S.user.role + (isAlumni(S.user) ? ':alumni' : '');
  if (!$('.app') || $('.app').dataset.role !== roleKey) {
    $('#root').innerHTML = layout();
    $('.app').dataset.role = roleKey;
  } else {
    // perbarui penanda menu aktif tanpa membangun ulang tata letak
    const cur = S.route === 'pengaturan' && $('.side a[href="#/setelan"]') ? 'setelan' : S.route;
    $$('.side a.nv, .tabbar a').forEach((a) => a.classList.toggle('on', a.getAttribute('href') === '#/' + cur));
  }
  const nb = $('#nav'); if (nb) nb.checked = false;
  const page = $('#page');
  page.innerHTML = '<div class="empty"><span class="spin"></span></div>';
  document.title = (nav.find((n) => n[0] === S.route)?.[1] || 'MyPresence') + ' · ' + BOOT.school.name;
  try {
    if (isLocked(S.route)) lockedPage(page, nav.find((n) => n[0] === S.route)?.[1] || 'Fitur');
    else await PAGES[S.route](page, S.query);
    // Judul halaman ikut nama menu sekolah ini (hanya bila judulnya masih nama bawaan).
    const def = NAV_DEFAULT[S.user.role]?.[S.route], cur = nav.find((n) => n[0] === S.route)?.[1], h = page.querySelector('.hdr h2');
    if (def && cur && def !== cur && h && h.textContent.trim() === def) h.textContent = cur;
    if (isAlumni(S.user)) {
      const until = S.user.student.alumni_until ? fd(S.user.student.alumni_until) : '';
      page.insertAdjacentHTML('afterbegin', `<div class="alert wr">🎓 Selamat atas kelulusanmu! Akun alumni hanya untuk melihat rekap kehadiran${until ? ' sampai ' + esc(until) : ''}. Minta Surat Keterangan Kehadiran ke admin sekolah bila perlu.</div>`);
    }
  } catch (e) {
    if (e.status !== 401 && e.status !== 402) page.innerHTML = err(e);
  }
  page.focus({ preventScroll: true });
  refreshBadge();
  if (S.route === 'dash') pushNudge(page).then(() => passkeyNudge(page));
  scrollTo(0, 0);
}

/**
 * Dashboard: ajak mengaktifkan notifikasi di HP ini (dulu hanya ada di halaman lonceng, jarang ditemukan).
 * Bila sudah aktif: daftarkan ulang langganan sekali per sesi (server menghapus langganan yang mati).
 */
let pushSynced = false;
async function pushNudge(page) {
  const st = await pushState().catch(() => 'unsupported');
  if (st === 'on') {
    if (!pushSynced) { pushSynced = true; const reg = await navigator.serviceWorker.getRegistration(); const sub = await reg?.pushManager.getSubscription(); if (sub) api('push/subscriptions', { method: 'POST', body: { endpoint: sub.endpoint } }).catch(() => {}); }
    return;
  }
  let later = 0; try { later = +localStorage.getItem('mp_push_later') || 0; } catch (_) {}
  if (st === 'unsupported' && !/iphone|ipad/i.test(navigator.userAgent)) return;
  if (Date.now() - later < 3 * 864e5 || !page.isConnected) return;
  const ios = /iphone|ipad/i.test(navigator.userAgent) && !matchMedia('(display-mode: standalone)').matches;
  const msg = st === 'denied' ? 'Notifikasi diblokir di HP ini. Buka pengaturan situs/aplikasi → Notifikasi → Izinkan, supaya pemberitahuan muncul walau aplikasi tertutup.'
    : ios ? 'Di iPhone, pasang dulu ke Layar Utama (Bagikan → Tambahkan ke Layar Utama), buka dari ikonnya, lalu aktifkan notifikasi.'
    : 'Aktifkan notifikasi agar informasi, tugas, dan presensi langsung muncul di HP walau aplikasi tertutup.';
  page.insertAdjacentHTML('afterbegin', `<div class="card push-nudge" id="pn"><span class="pn-ic">${ti('bell')}</span><span class="grow"><b>Notifikasi di HP ini belum aktif</b><br><span class="mu">${msg}</span></span>
    ${st === 'off' ? `<button class="btn nf" type="button" id="pn-on">${ti('bell')} Aktifkan</button>` : ''}<button class="linkbtn" type="button" id="pn-later">Nanti</button></div>`);
  $('#pn-later').onclick = () => { try { localStorage.setItem('mp_push_later', String(Date.now())); } catch (_) {} $('#pn')?.remove(); };
  const on = $('#pn-on');
  if (on) on.onclick = async () => {
    on.disabled = true;
    try { const N = await api('notifications'); if (!N.push.available) throw new Error('Notifikasi push belum diaktifkan di server sekolah. Hubungi Parama Tech.'); await enablePush(N.push.public_key); toast('Notifikasi aktif di HP ini.'); $('#pn')?.remove(); }
    catch (x) { toast(x.message, true); on.disabled = false; }
  };
}

document.addEventListener('click', async (e) => {
  const a = e.target.closest('[data-act]');
  if (!a) return;
  if (a.dataset.act === 'theme') themePicker();
  if (a.dataset.act === 'logout') logout();
  if (a.dataset.act === 'install') install();
});

/** Pilihan tema: Vista Aero (bawaan), Klasik terang, Gelap. Disimpan per perangkat. */
function themePicker() {
  const cur = document.documentElement.dataset.theme || 'vista';
  const T = [['vista', 'Vista Aero', 'Kaca biru mengilap ala Windows Vista (bawaan)'], ['light', 'Klasik terang', 'Datar & bersih, sidebar gelap'], ['dark', 'Gelap', 'Nyaman di malam hari, hemat baterai layar OLED']];
  const d = dialog('Tema tampilan', `<div class="theme-pick">${T.map(([v, l, x]) => `<label class="opt"><input type="radio" name="th" value="${v}" ${v === cur ? 'checked' : ''}><span class="th-sw th-${v}" aria-hidden="true"></span><span><b>${l}</b><br><span class="mu">${x}</span></span></label>`).join('')}</div>`);
  d.querySelectorAll('input[name="th"]').forEach((r) => (r.onchange = () => {
    document.documentElement.dataset.theme = r.value;
    try { localStorage.setItem('mp_theme', r.value); } catch (_) {}
  }));
}

async function logout() {
  // HP bisa dipakai bergantian: matikan push akun ini di perangkat ini sebelum keluar.
  try {
    const reg = await navigator.serviceWorker?.getRegistration();
    const sub = await reg?.pushManager?.getSubscription();
    if (sub) { await api('push/subscriptions', { method: 'DELETE', body: { endpoint: sub.endpoint } }).catch(() => {}); await sub.unsubscribe(); }
  } catch (_) {}
  try { await api('auth/logout', { method: 'POST' }); } catch (_) {}
  S.user = null;
  clearData();
  try { localStorage.removeItem('mp_uid'); } catch (_) {}
  showLogin();
}
function clearData() { PAGES = {}; navigator.serviceWorker?.controller?.postMessage('clear-data'); }

// ── Login, ganti password wajib, layanan diblokir ─────────────────
function authCard(inner) {
  const s = BOOT.school;
  return `<div class="login"><div class="lc">
    <div class="brand"><span class="brand-logo"><img src="/img/logo-vertical.svg" alt="ParamaEdu.Tech" width="584" height="308"></span><div class="brand-copy"><strong>My<span>Presence</span></strong></div></div>
    ${s.logo ? `<img src="${esc(s.logo)}" alt="Logo ${esc(s.name)}" style="display:block;width:60px;height:60px;object-fit:contain;margin:14px auto 0">` : ''}
    <p class="school-name">${esc(s.name)}</p>${BOOT.app_label ? `<p class="tag" style="margin:2px 0 0;font-weight:700">Aplikasi ${esc(BOOT.app_label)}</p>` : ''}${s.verified ? '<p class="tag" style="margin:2px 0 0;color:#16a34a;font-weight:600">✓ Sekolah terverifikasi ParamaEdu.Tech</p>' : '<p class="tag" style="margin:2px 0 0;color:#b45309">Sekolah belum terverifikasi · hanya admin yang bisa masuk</p>'}<p class="tag">Solusi Digital untuk Sekolah Modern</p>${inner}
    <p class="foot">Dikembangkan oleh ParamaEdu.Tech</p></div></div>`;
}

// ── Login sidik jari / Face ID (passkey WebAuthn) ─────────────────
const b2u = (buf) => btoa(String.fromCharCode(...new Uint8Array(buf))).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
const u2b = (s) => Uint8Array.from(atob(s.replace(/-/g, '+').replace(/_/g, '/') + '='.repeat((4 - (s.length % 4)) % 4)), (c) => c.charCodeAt(0));
const PK_KEY = () => 'mp_pk:' + (BOOT.app || 'app');
/** Passkey yang didaftarkan DI HP INI (disimpan lokal agar tombol sidik jari muncul di layar login). */
function localPasskeys() { try { return JSON.parse(localStorage.getItem(PK_KEY()) || '[]'); } catch (_) { return []; } }
function saveLocalPasskeys(list) { try { localStorage.setItem(PK_KEY(), JSON.stringify(list.slice(0, 5))); } catch (_) {} }
async function passkeyAvailable() {
  try { return !!window.PublicKeyCredential && await PublicKeyCredential.isUserVerifyingPlatformAuthenticatorAvailable(); } catch (_) { return false; }
}
function deviceName() {
  const ua = navigator.userAgent;
  const os = /iphone/i.test(ua) ? 'iPhone' : /ipad/i.test(ua) ? 'iPad' : /android/i.test(ua) ? 'HP Android' : /windows/i.test(ua) ? 'Windows' : /mac/i.test(ua) ? 'Mac' : 'Perangkat';
  const br = /edg\//i.test(ua) ? 'Edge' : /samsungbrowser/i.test(ua) ? 'Samsung Internet' : /crios|chrome/i.test(ua) ? 'Chrome' : /safari/i.test(ua) ? 'Safari' : /firefox/i.test(ua) ? 'Firefox' : '';
  return br ? `${os} (${br})` : os;
}
async function passkeyLogin() {
  const ids = localPasskeys().map((p) => p.id);
  const { publicKey: o } = await api('auth/passkey/options', { method: 'POST', body: { ids } });
  const cred = await navigator.credentials.get({ publicKey: { ...o, challenge: u2b(o.challenge), allowCredentials: o.allowCredentials.map((c) => ({ ...c, id: u2b(c.id) })) } })
    .catch((e) => { throw new Error(e?.name === 'NotAllowedError' ? 'Dibatalkan atau waktu habis. Coba lagi.' : 'Sidik jari tidak bisa dipakai di HP ini.'); });
  const r = cred.response;
  return api('auth/passkey', { method: 'POST', body: { id: b2u(cred.rawId), clientDataJSON: b2u(r.clientDataJSON), authenticatorData: b2u(r.authenticatorData),
    signature: b2u(r.signature), userHandle: r.userHandle ? b2u(r.userHandle) : null, ...(BOOT.app ? { app: BOOT.app } : {}) } });
}
async function passkeyRegister() {
  const { publicKey: o } = await api('passkeys/options', { method: 'POST', body: {} });
  const cred = await navigator.credentials.create({ publicKey: { ...o, challenge: u2b(o.challenge), user: { ...o.user, id: u2b(o.user.id) },
    excludeCredentials: o.excludeCredentials.map((c) => ({ ...c, id: u2b(c.id) })) } })
    .catch((e) => { throw new Error(e?.name === 'InvalidStateError' ? 'Sidik jari HP ini sudah terdaftar.' : e?.name === 'NotAllowedError' ? 'Dibatalkan atau waktu habis.' : 'HP ini belum bisa memakai login sidik jari. Pastikan kunci layar (sidik jari/PIN) aktif.'); });
  const name = deviceName();
  const r = await api('passkeys', { method: 'POST', body: { clientDataJSON: b2u(cred.response.clientDataJSON), attestationObject: b2u(cred.response.attestationObject), name } });
  saveLocalPasskeys([{ id: r.credential_id, user: S.user.name, name }, ...localPasskeys().filter((p) => p.id !== r.credential_id)]);
  return r;
}

export function showLogin() {
  $('#root').innerHTML = authCard(`<form id="lf" novalidate>
    <label for="u">Username / NIK / NIS</label>
    <input id="u" name="username" autocomplete="username" autocapitalize="none" required placeholder="${esc(BOOT.login_hint || 'Admin: username · Guru: NIK · Siswa: NIS')}">
    <label for="p">Password</label>
    <input id="p" name="password" type="password" autocomplete="current-password" required>
    <button class="btn w" type="submit">Masuk</button>
    <div id="pkl"></div>
    <div class="err" id="le" role="alert"></div>
    <p class="hint"><a href="${BOOT.portal.forgot}">Lupa password?</a> · Akun baru: pakai password awal dari wali kelas/admin, lalu wajib diganti.</p>
    <p class="hint">Bukan sekolahmu? <a href="${location.protocol}//${BOOT.central}/${BOOT.app && BOOT.app !== 'admin' ? BOOT.app : 'app'}">Ganti sekolah dengan kode sekolah</a></p>
    ${installHint()}
  </form>`);
  $('#lf').onsubmit = async (e) => {
    e.preventDefault();
    const b = e.target.querySelector('button'); b.disabled = true; $('#le').textContent = '';
    try {
      const r = await api('auth/login', { method: 'POST', body: { username: $('#u').value.trim(), password: $('#p').value, ...(BOOT.app ? { app: BOOT.app } : {}) } });
      if (r.two_factor) return showTwoFactorChallenge();
      await signedIn(r.user);
    } catch (x) { $('#le').textContent = x.message; b.disabled = false; }
  };
  // Tombol sidik jari: hanya bila HP ini pernah didaftarkan dan mendukung.
  const known = localPasskeys();
  if (known.length) passkeyAvailable().then((ok) => {
    if (!ok || !$('#pkl')) return;
    $('#pkl').innerHTML = `<div class="or"><span>atau</span></div><button class="btn o w pk-btn" type="button" id="pkb"><span>${ti('fingerprint')} Masuk dengan sidik jari</span>${known.length === 1 && known[0].user ? `<small>${esc(known[0].user)}</small>` : ''}</button>`;
    $('#pkb').onclick = async (e) => {
      const b = e.currentTarget; b.disabled = true; $('#le').textContent = '';
      try { const r = await passkeyLogin(); await signedIn(r.user); } catch (x) { $('#le').textContent = x.message; b.disabled = false; }
    };
  });
}

// Tiga aplikasi terpisah: siswa → /siswa, guru → /guru, admin → /admin. /app (ikon lama) mengarahkan otomatis.
const APP_OF = { student: 'siswa', teacher: 'guru', admin: 'admin' };
const APP_LABEL = { siswa: 'Siswa', guru: 'Guru', admin: 'Admin' };

function showWrongApp(target) {
  $('#root').innerHTML = authCard(`<div class="alert wr" style="margin-top:12px">Kamu masuk sebagai <b>${esc(S.user.role_label)}</b>. Aplikasi ini khusus <b>${esc(BOOT.app_label)}</b>.</div>
    <a class="btn w" href="/${target}">Buka aplikasi ${APP_LABEL[target]}</a>
    <p class="hint"><button class="linkbtn" data-act="logout" type="button">Keluar dan masuk dengan akun lain</button></p>`);
}

async function signedIn(user) {
  const target = APP_OF[user.role] || 'admin';
  if (!BOOT.app) { location.replace('/' + target + (location.hash || '')); return; }
  if (BOOT.app !== target) { S.user = user; return showWrongApp(target); }
  let prev = null;
  try { prev = localStorage.getItem('mp_uid'); localStorage.setItem('mp_uid', String(user.id)); } catch (_) {}
  if (prev && prev !== String(user.id)) clearData(); // data offline akun lain tidak boleh terlihat
  S.user = user;
  if (!location.hash) location.hash = '#/dash';
  render();
}

function showForcePassword() {
  $('#root').innerHTML = authCard(`<div class="alert wr" style="margin-top:12px">Demi keamanan, ganti password awal sebelum memakai MyPresence.</div>
    <form id="pf">${pwFields()}<button class="btn w" type="submit">Simpan password baru</button><div class="err" id="pe" role="alert"></div></form>
    <p class="hint"><button class="linkbtn" data-act="logout" type="button">Keluar</button></p>`);
  bindPasswordForm($('#pf'), () => { S.user.must_change_password = false; toast('Password diperbarui.'); render(); }, $('#pe'));
}
// ── 2FA admin sekolah ─────────────────────────────────────────────
const codeInput = (id) => `<input id="${id}" inputmode="numeric" autocomplete="one-time-code" required maxlength="20" style="font-size:22px;letter-spacing:6px;text-align:center">`;

function showTwoFactorChallenge() {
  $('#root').innerHTML = authCard(`<form id="tf" novalidate>
    <p class="tag" style="margin-top:12px">Masukkan kode 6 digit dari aplikasi authenticator, atau salah satu kode cadangan.</p>
    <label for="tc">Kode verifikasi</label>${codeInput('tc')}
    <button class="btn w" type="submit">Verifikasi</button>
    <div class="err" id="te" role="alert"></div>
    <p class="hint"><button class="linkbtn" id="tback" type="button">Kembali ke halaman masuk</button></p></form>`);
  $('#tc').focus();
  $('#tback').onclick = () => showLogin();
  $('#tf').onsubmit = async (e) => {
    e.preventDefault();
    const b = e.target.querySelector('button[type=submit]'); b.disabled = true; $('#te').textContent = '';
    try { const r = await api('auth/2fa', { method: 'POST', body: { code: $('#tc').value } }); await signedIn(r.user); }
    catch (x) { $('#te').textContent = x.message; b.disabled = false; if (/berakhir|Masuk ulang/i.test(x.message)) setTimeout(showLogin, 1800); }
  };
}

async function showTwoFactorSetup() {
  $('#root').innerHTML = authCard('<div class="empty"><span class="spin"></span></div>');
  let s;
  try { s = await api('me/2fa'); } catch (x) { $('#root').innerHTML = authCard(err(x) + '<button class="btn w o" data-act="logout" type="button">Keluar</button>'); return; }
  $('#root').innerHTML = authCard(`<div class="alert wr" style="margin-top:12px">Admin sekolah wajib mengaktifkan verifikasi 2 langkah (2FA) untuk melindungi data siswa.</div>
    <ol class="hint" style="text-align:left;padding-left:18px;margin:10px 0">
      <li>Pasang <b>Google Authenticator</b> atau <b>Authy</b> di HP.</li>
      <li>Pindai QR di bawah, atau ketik kode <b class="secret" style="user-select:all">${esc(s.secret)}</b>.</li>
      <li>Masukkan 6 digit yang muncul di aplikasi.</li></ol>
    <div style="background:#fff;padding:10px;border-radius:12px;width:200px;margin:0 auto">${s.qr_svg}</div>
    <form id="sf" novalidate><label for="sc">Kode 6 digit</label>${codeInput('sc')}
      <button class="btn w" type="submit">Aktifkan 2FA</button><div class="err" id="se" role="alert"></div></form>
    <p class="hint"><button class="linkbtn" data-act="logout" type="button">Keluar</button></p>`);
  $('#sf').onsubmit = async (e) => {
    e.preventDefault();
    const b = e.target.querySelector('button[type=submit]'); b.disabled = true; $('#se').textContent = '';
    try {
      const r = await api('me/2fa', { method: 'POST', body: { code: $('#sc').value } });
      $('#root').innerHTML = authCard(`<div class="alert ok" style="margin-top:12px"><b>2FA aktif.</b> Simpan kode cadangan ini di tempat aman (bukan di HP yang sama). Setiap kode hanya bisa dipakai sekali bila HP hilang.</div>
        <div class="secret" style="display:grid;grid-template-columns:1fr 1fr;gap:6px;text-align:center;font-family:monospace;font-size:15px;margin:10px 0;user-select:all">${r.recovery_codes.map((c) => `<span>${esc(c)}</span>`).join('')}</div>
        <button class="btn w" id="done" type="button">Saya sudah menyimpan kode cadangan</button>`);
      $('#done').onclick = () => { S.user = r.user; render(); };
    } catch (x) { $('#se').textContent = x.message; b.disabled = false; }
  };
}

export const pwFields = () => `<label for="cp">Password sekarang</label><input id="cp" type="password" autocomplete="current-password" required>
  <label for="np">Password baru (min. 8 karakter)</label><input id="np" type="password" autocomplete="new-password" minlength="8" required>
  <label for="np2">Ulangi password baru</label><input id="np2" type="password" autocomplete="new-password" minlength="8" required>`;
export function bindPasswordForm(f, ok, errEl) {
  f.onsubmit = async (e) => {
    e.preventDefault();
    const b = f.querySelector('button[type=submit]'); b.disabled = true;
    try {
      await api('me/password', { method: 'PUT', body: { current_password: $('#cp', f).value, password: $('#np', f).value, password_confirmation: $('#np2', f).value } });
      f.reset(); ok();
    } catch (x) { (errEl ? (errEl.textContent = x.message) : toast(x.message, true)); }
    b.disabled = false;
  };
}

export function showLocked(message) {
  const admin = S.user?.role === 'admin';
  $('#root').innerHTML = authCard(`<div class="alert wr" style="margin-top:14px"><b>Layanan diblokir sementara.</b><br>${esc(message || 'Masa langganan sekolah berakhir.')}</div>
    <p class="hint">Data sekolah tetap aman. Layanan aktif kembali otomatis setelah pembayaran diterima.</p>
    ${admin ? `<a class="btn w" href="${BOOT.portal.billing}" style="justify-content:center">Buka Langganan</a>` : ''}
    <button class="btn w o" data-act="logout" type="button">Keluar</button>`);
}

// ── Instal aplikasi ───────────────────────────────────────────────
// Aplikasi Android MyPresence (WebView) dianggap sudah terpasang: tidak menampilkan ajakan pasang PWA.
const standalone = () => matchMedia('(display-mode: standalone)').matches || navigator.standalone === true || !!window.MPNative;
const isIOS = () => /iphone|ipad|ipod/i.test(navigator.userAgent) && !window.MSStream;
addEventListener('beforeinstallprompt', (e) => { e.preventDefault(); S.installEvt = e; $$('.install-slot').forEach((x) => (x.innerHTML = installHint(true))); });
addEventListener('appinstalled', () => { S.installEvt = null; toast('MyPresence terpasang di layar utama.'); });
export function installHint(inner = false) {
  if (standalone()) return inner ? '' : '<div class="install-slot"></div>';
  const body = S.installEvt
    ? `<div class="install card" style="margin:14px 0 0;padding:12px"><span style="font-size:13px"><b>Pasang aplikasi</b><br><span class="mu">Buka MyPresence langsung dari layar utama HP.</span></span><button class="btn" data-act="install" type="button">Pasang</button></div>`
    : isIOS() ? `<p class="hint">📲 iPhone: ketuk <b>Bagikan</b> lalu <b>Tambah ke Layar Utama</b> untuk memasang aplikasi.</p>` : '';
  return inner ? body : `<div class="install-slot">${body}</div>`;
}
async function install() {
  if (!S.installEvt) return;
  S.installEvt.prompt();
  await S.installEvt.userChoice;
  S.installEvt = null;
  $$('.install-slot').forEach((x) => (x.innerHTML = ''));
}

// ── Halaman bersama: profil ───────────────────────────────────────
// ── Notifikasi: lonceng + push ────────────────────────────────────
let badgeT;
/** Angka di lonceng + tanda di ikon aplikasi (Badging API: iPhone & desktop; Android memakai titik notifikasi). */
function showUnread(n) {
  const b = $('#nbadge'); if (b) { b.hidden = !n; b.textContent = n > 99 ? '99+' : String(n); }
  try { n ? navigator.setAppBadge?.(n) : navigator.clearAppBadge?.(); } catch (_) {}
}
async function refreshBadge() {
  clearTimeout(badgeT);
  if (!S.user) return;
  try { showUnread((await api('notifications/latest')).unread || 0); } catch (_) {}
  // Selalu jadwalkan ulang (dulu berhenti selamanya setelah aplikasi sempat disembunyikan).
  badgeT = setTimeout(refreshBadge, document.visibilityState === 'visible' ? 30000 : 120000);
}
// Kembali ke aplikasi / dorongan masuk saat aplikasi terbuka → perbarui lonceng saat itu juga.
document.addEventListener('visibilitychange', () => { if (document.visibilityState === 'visible' && S.user) refreshBadge(); });
navigator.serviceWorker?.addEventListener('message', (e) => { if (e.data?.type === 'push' && S.user) { refreshBadge(); if (S.route === 'notif') render(); } });
const b64key = (s) => { const p = '='.repeat((4 - (s.length % 4)) % 4); const raw = atob((s + p).replace(/-/g, '+').replace(/_/g, '/')); return Uint8Array.from(raw, (c) => c.charCodeAt(0)); };
async function pushState() {
  if (!('serviceWorker' in navigator) || !('PushManager' in window) || !('Notification' in window)) return 'unsupported';
  if (Notification.permission === 'denied') return 'denied';
  const reg = await navigator.serviceWorker.getRegistration();
  return (await reg?.pushManager.getSubscription()) ? 'on' : 'off';
}
async function enablePush(key) {
  if (await Notification.requestPermission() !== 'granted') throw new Error('Izin notifikasi ditolak. Aktifkan lewat pengaturan situs di browser.');
  const reg = await navigator.serviceWorker.ready;
  const sub = (await reg.pushManager.getSubscription()) || await reg.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: b64key(key) });
  await api('push/subscriptions', { method: 'POST', body: { endpoint: sub.endpoint } });
}
async function disablePush() {
  const reg = await navigator.serviceWorker.getRegistration();
  const sub = await reg?.pushManager.getSubscription();
  if (sub) { await api('push/subscriptions', { method: 'DELETE', body: { endpoint: sub.endpoint } }).catch(() => {}); await sub.unsubscribe(); }
}
const ago = (iso) => { const m = Math.round((Date.now() - new Date(iso)) / 60000); return m < 1 ? 'baru saja' : m < 60 ? m + ' menit lalu' : m < 1440 ? Math.round(m / 60) + ' jam lalu' : fd(iso); };

/** Profil → Login sidik jari: daftar HP + aktifkan di HP ini. */
async function passkeyCard(box) {
  if (!box) return;
  const ok = await passkeyAvailable();
  let L = [];
  try { L = await api('passkeys'); } catch (_) {}
  const mine = new Set(localPasskeys().map((p) => p.id));
  const here = L.some((c) => mine.has(c.credential_id));
  box.innerHTML = `<b>${ti('fingerprint')} Login sidik jari / Face ID</b>
    <p class="mu" style="margin:4px 0 10px">Masuk tanpa mengetik password, memakai sidik jari atau kunci layar HP. Sidik jari tetap tersimpan di HP, tidak dikirim ke server.</p>
    ${L.length ? `<div class="list">${L.map((c) => `<div class="item"><span class="grow"><b>${esc(c.name || 'HP')}</b>${mine.has(c.credential_id) ? ' <span class="chip ok">HP ini</span>' : ''}<br><span class="mu" style="font-size:12px">Didaftarkan ${fd(c.created_at)}${c.last_used_at ? ' · terakhir dipakai ' + ago(c.last_used_at) : ''}</span></span>
      <button class="linkbtn" type="button" data-pk-del="${c.id}" data-pk-cid="${esc(c.credential_id)}">Hapus</button></div>`).join('')}</div>` : ''}
    ${!ok ? '<p class="mu" style="margin:8px 0 0">HP/browser ini belum mendukung login sidik jari, atau kunci layar belum diaktifkan.</p>'
      : here ? '<p class="mu" style="margin:8px 0 0">✅ Aktif di HP ini. Di layar masuk, ketuk <b>Masuk dengan sidik jari</b>.</p>'
      : `<button class="btn" type="button" id="pk-on" style="margin-top:10px">${ti('fingerprint')} Aktifkan di HP ini</button>`}`;
  const on = $('#pk-on', box);
  if (on) on.onclick = async () => { on.disabled = true; try { const r = await passkeyRegister(); toast(r.message); passkeyCard(box); } catch (x) { toast(x.message, true); on.disabled = false; } };
  $$('[data-pk-del]', box).forEach((b) => (b.onclick = async () => {
    if (!confirm('Hapus login sidik jari untuk perangkat ini? Perangkat itu harus masuk dengan password lagi.')) return;
    try { await api('passkeys/' + b.dataset.pkDel, { method: 'DELETE' }); saveLocalPasskeys(localPasskeys().filter((p) => p.id !== b.dataset.pkCid)); toast('Dihapus.'); passkeyCard(box); }
    catch (x) { toast(x.message, true); }
  }));
}

/** Dashboard: tawarkan sekali login sidik jari (bila HP mendukung & belum aktif di HP ini). */
async function passkeyNudge(page) {
  let later = 0; try { later = +localStorage.getItem('mp_pk_later') || 0; } catch (_) {}
  if (localPasskeys().length || Date.now() - later < 14 * 864e5 || !(await passkeyAvailable()) || !page.isConnected || $('#pkn')) return;
  page.insertAdjacentHTML('afterbegin', `<div class="card push-nudge" id="pkn"><span class="pn-ic">${ti('fingerprint')}</span><span class="grow"><b>Masuk lebih cepat dengan sidik jari</b><br><span class="mu">Aktifkan sekali, lalu masuk tanpa mengetik password. Sidik jari tetap tersimpan di HP.</span></span>
    <button class="btn nf" type="button" id="pkn-on">${ti('fingerprint')} Aktifkan</button><button class="linkbtn" type="button" id="pkn-later">Nanti</button></div>`);
  $('#pkn-later').onclick = () => { try { localStorage.setItem('mp_pk_later', String(Date.now())); } catch (_) {} $('#pkn')?.remove(); };
  $('#pkn-on').onclick = async (e) => { const b = e.currentTarget; b.disabled = true; try { const r = await passkeyRegister(); toast(r.message); $('#pkn')?.remove(); } catch (x) { toast(x.message, true); b.disabled = false; } };
}

/** Jenis notifikasi → ikon & label (selaras dengan Notifier di server). */
const NTYPE = {
  assignment: ['clipboard-text', 'Penugasan'], submission: ['clipboard-text', 'Tugas dikumpulkan'], announcement: ['speakerphone', 'Informasi'], agenda: ['calendar-event', 'Agenda'], cbt: ['device-desktop', 'CBT'],
  class_cash: ['receipt', 'Kas Kelas'], class_role: ['hierarchy-2', 'Struktur Kelas'], coordinator: ['id-badge-2', 'Koordinator'], habit: ['heartbeat', 'Pembiasaan'],
  library: ['book', 'MP-Library'], import: ['file-import', 'Impor Data'], absent_recap: ['chart-bar', 'Rekap Presensi'], student_exit: ['users', 'Data Siswa'],
  homeroom_vacant: ['presentation', 'Wali Kelas'], year_reminder: ['calendar', 'Tahun Ajaran'], year_closed: ['calendar', 'Tahun Ajaran'], retention_warning: ['shield-lock', 'Data'],
  graduated: ['certificate', 'Kelulusan'], alumni_ending: ['user', 'Akun'],
};

const COMMON = {
  async notif(el) {
    const N = await api('notifications');
    const st = N.push.available ? await pushState() : 'off-server';
    const ios = /iphone|ipad/i.test(navigator.userAgent) && !matchMedia('(display-mode: standalone)').matches;
    const pushCard = st === 'off-server' ? '' : `<div class="card row" style="align-items:center">
      <span class="grow"><b>Notifikasi di HP ini</b><br><span class="mu">${st === 'on' ? '✅ Aktif. Pemberitahuan muncul walau aplikasi tertutup.' : st === 'denied' ? '⛔ Diblokir di browser. Buka pengaturan situs → Notifikasi → Izinkan.' : st === 'unsupported' ? (ios ? 'Di iPhone: pasang aplikasi ke Layar Utama dulu (Bagikan → Tambahkan ke Layar Utama), lalu buka dari ikonnya.' : 'Browser ini belum mendukung notifikasi push.') : 'Belum aktif. Aktifkan agar tidak ketinggalan tugas & informasi.'}</span></span>
      ${st === 'on' ? '<button class="btn o nf" id="poff" type="button">Matikan</button>' : st === 'off' ? `<button class="btn nf" id="pon" type="button">${ti('bell')} Aktifkan notifikasi</button>` : ''}</div>`;
    el.innerHTML = hdr('Pemberitahuan', N.unread ? `${N.unread} belum dibaca` : 'Semua sudah dibaca', N.unread ? '<button class="btn o nf" id="ra" type="button">Tandai semua dibaca</button>' : '') + pushCard
      + `<div class="card">${N.items.length ? N.items.map((n) => { const [ic, lb] = NTYPE[n.type] || ['bell', 'Pemberitahuan']; return `<a class="nitem${n.read_at ? ' read' : ''}" href="${esc(n.url || '#/notif')}" data-id="${n.id}">
          <span class="ni-ic">${ti(ic)}</span><span class="grow"><span class="ni-k">${esc(lb)}${n.read_at ? '' : ' <i class="ni-dot" aria-label="belum dibaca"></i>'}</span><b>${esc(n.title)}</b>${n.body ? `<span class="mu">${esc(n.body)}</span>` : ''}<small class="mu">${ago(n.created_at)}</small></span></a>`; }).join('') : empty('Belum ada pemberitahuan.')}</div>`;
    const on = $('#pon', el); if (on) on.onclick = async () => { on.disabled = true; try { await enablePush(N.push.public_key); toast('Notifikasi aktif di HP ini.'); render(); } catch (x) { toast(x.message, true); on.disabled = false; } };
    const off = $('#poff', el); if (off) off.onclick = async () => { await disablePush(); toast('Notifikasi dimatikan di HP ini.'); render(); };
    const ra = $('#ra', el); if (ra) ra.onclick = async () => { await api('notifications/read', { method: 'POST', body: {} }); showUnread(0); render(); };
    $$('.nitem', el).forEach((a) => (a.onclick = () => { api('notifications/read', { method: 'POST', body: { id: +a.dataset.id } }).then(refreshBadge).catch(() => {}); }));
  },

  async profil(el) {
    const u = (await api('me')).user; S.user = u;
    const ini = u.name.split(' ').filter(Boolean).slice(0, 2).map((w) => w[0]).join('').toUpperCase();
    const rows = [['Nama', u.name], ['Peran', u.role_label], [u.role === 'student' ? 'NIS' : u.role === 'teacher' ? 'NIK' : 'Username', u.student?.nis || u.teacher?.nik || u.username]];
    if (u.student?.classroom) rows.push(['Kelas', u.student.classroom.name]);
    if (u.teacher?.subject) rows.push(['Mata pelajaran', u.teacher.subject]);
    if (u.email) rows.push(['Email', u.email]);
    el.innerHTML = hdr('Profil', 'Akun MyPresence kamu') + `
      <div class="two"><div class="card">
        <div style="display:flex;gap:16px;align-items:center;flex-wrap:wrap">
          ${u.photo_url ? `<img class="avatar" src="${esc(u.photo_url)}" alt="Foto profil">` : `<div class="avatar">${esc(ini)}</div>`}
          <div><b style="font-size:16px">${esc(u.name)}</b><div class="mu">${esc(u.role_label)}</div>
            <label class="btn o nf" style="margin-top:10px;display:inline-flex;font-size:13px;color:var(--tx)">${ti('camera')} Ganti foto<input type="file" id="ph" accept="image/jpeg,image/png,image/webp" hidden></label>
            ${u.photo_url ? '<button class="linkbtn" id="phd" type="button" style="margin-left:8px">Hapus foto</button>' : ''}</div>
        </div>
        <div class="kv" style="margin-top:16px">${rows.map(([k, v]) => `<span class="mu">${esc(k)}</span><span>${esc(v)}</span>`).join('')}</div>
        ${u.role === 'admin' ? `<form id="nf" class="name-edit"><label for="nm">Nama lengkap admin / operator</label>
          <div class="row"><input id="nm" name="name" value="${esc(u.name)}" required minlength="3" maxlength="120" autocomplete="name"><button class="btn o nf" type="submit">Simpan nama</button></div>
          <span class="mu" style="font-size:12px">Nama orang yang mengelola MyPresence. Alamat sekolah diubah di <a href="${BOOT.portal.school}">Profil Sekolah</a>.</span></form>` : ''}
        <div class="install-slot">${installHint(true)}</div>
      </div>
      <form class="card" id="pf"><b>Ganti password</b>${pwFields()}<button class="btn" type="submit" style="margin-top:14px">Simpan password</button></form></div>
      ${u.role === 'student' && !isAlumni(u) ? '<div class="card" id="bio"><div class="empty"><span class="spin"></span></div></div>' : ''}
      <div class="card" id="pkc"></div>
      <div class="card"><div class="row" style="align-items:center"><span class="mu">Tema tampilan, keluar dari akun di HP ini.</span>
        <button class="btn o nf" data-act="theme" type="button">${ti('contrast-2')} Tema</button><button class="btn er nf" data-act="logout" type="button">Keluar</button></div></div>
      <p class="mu" style="text-align:center">MyPresence · versi ${esc(BOOT.version)}</p>`;
    bindPasswordForm($('#pf', el), () => toast('Password diperbarui.'));
    passkeyCard($('#pkc', el));
    const bio = $('#bio', el);
    if (bio) import('./biodata.js' + new URL(import.meta.url).search).then((m) => m.mountBiodata(bio)).catch((x) => { bio.innerHTML = `<div class="alert er">${esc(x.message)}</div>`; });
    const nf = $('#nf', el);
    if (nf) nf.onsubmit = async (e) => {
      e.preventDefault();
      try { const r = await api('me/name', { method: 'PUT', body: { name: nf.name.value } }); S.user = r.user; toast(r.message); $('.app')?.removeAttribute('data-role'); render(); }
      catch (x) { toast(x.message, true); }
    };
    $('#ph', el).onchange = async (e) => {
      const f = e.target.files[0]; if (!f) return;
      const fd = new FormData(); fd.append('photo', f);
      try { await api('me/photo', { method: 'POST', form: fd }); toast('Foto profil diperbarui.'); render(); } catch (x) { toast(x.message, true); }
    };
    const d = $('#phd', el); if (d) d.onclick = async () => { await api('me/photo', { method: 'DELETE' }); toast('Foto dihapus.'); render(); };
  },
};

// ── Mulai ─────────────────────────────────────────────────────────
addEventListener('hashchange', () => S.user && render());

async function boot() {
  if ('serviceWorker' in navigator) {
    navigator.serviceWorker.register('/sw.js').then((reg) => {
      reg.addEventListener('updatefound', () => {
        const w = reg.installing;
        w?.addEventListener('statechange', () => {
          if (w.state === 'installed' && navigator.serviceWorker.controller) { w.postMessage('skip-waiting'); toast('Versi baru MyPresence siap — memuat ulang…'); setTimeout(() => location.reload(), 1200); }
        });
      });
    }).catch(() => {});
  }
  try {
    const r = await api('me');
    await signedIn(r.user);
  } catch (e) {
    if (e.status === 0) {
      $('#root').innerHTML = authCard(`<div class="alert wr" style="margin-top:14px">Tidak ada koneksi. Sambungkan ke internet sekali untuk masuk; setelah itu jadwal & agenda bisa dibuka offline.</div><button class="btn w" type="button" data-action="reload">Coba lagi</button>`);
    } else if (e.status !== 401 && e.status !== 402) showLogin();
  }
}
boot();
