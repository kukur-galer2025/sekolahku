/* MyPresence PWA — Biodata peserta didik (halaman identitas buku rapor).
 * Siswa mengisi di Profil; wali kelas & admin melengkapi dari Leger Rapor / Data Siswa; semua bisa mencetak.
 */
const { api, S, $, esc, toast } = window.MP;
const ti = globalThis.ti;

const RELIGIONS = ['Islam', 'Kristen', 'Katolik', 'Hindu', 'Buddha', 'Khonghucu', 'Kepercayaan terhadap Tuhan YME'];
const FAMILY = ['Anak kandung', 'Anak tiri', 'Anak angkat'];
const GROUPS = [
  ['Data diri', ['birth_place', 'religion', 'family_status', 'child_order', 'address', 'phone']],
  ['Diterima di sekolah ini', ['origin_school', 'accepted_class', 'accepted_date']],
  ['Orang tua', ['father_name', 'mother_name', 'father_job', 'mother_job', 'parents_address', 'parents_phone']],
  ['Wali peserta didik (bila ada)', ['guardian_name', 'guardian_job', 'guardian_address', 'guardian_phone']],
];
const LONG = ['address', 'parents_address', 'guardian_address'];

function input(k, f, v) {
  const id = 'bd_' + k;
  if (k === 'religion' || k === 'family_status') {
    const opts = k === 'religion' ? RELIGIONS : FAMILY;
    return `<div><label for="${id}">${esc(f.label)}</label><select id="${id}" name="${k}"><option value="">—</option>${[...new Set([...opts, ...(v && !opts.includes(v) ? [v] : [])])].map((o) => `<option ${o === v ? 'selected' : ''}>${esc(o)}</option>`).join('')}</select></div>`;
  }
  if (k === 'accepted_date') return `<div><label for="${id}">${esc(f.label)}</label><input id="${id}" type="date" name="${k}" value="${esc(v || '')}"></div>`;
  if (LONG.includes(k)) return `<div class="bd-wide"><label for="${id}">${esc(f.label)}</label><textarea id="${id}" name="${k}" rows="2" maxlength="${f.max}">${esc(v || '')}</textarea></div>`;
  const type = /phone/.test(k) ? 'tel' : k === 'child_order' ? 'text' : 'text';
  return `<div><label for="${id}">${esc(f.label)}</label><input id="${id}" type="${type}" name="${k}" maxlength="${f.max}" value="${esc(v || '')}" ${k === 'child_order' ? 'inputmode="numeric"' : ''}></div>`;
}

/** Pasang formulir biodata di `box`. opts.studentId = buka siswa lain (admin/wali); tanpa itu = biodata sendiri (siswa). */
export async function mountBiodata(box, opts = {}) {
  const url = opts.studentId ? `students/${opts.studentId}/biodata` : 'me/biodata';
  let D = await api(url);
  const draw = () => {
    const s = D.student; const p = D.profile; const staff = D.can_edit_identity;
    const filled = Object.values(p).filter(Boolean).length + (s.birth_date ? 1 : 0) + (s.gender ? 1 : 0);
    const total = Object.keys(p).length + 2;
    box.innerHTML = `<div class="bd-head"><div><b>${ti('id-badge-2')} Biodata peserta didik</b><p class="mu" style="margin:2px 0 0">Untuk halaman identitas buku rapor. ${filled}/${total} kolom terisi${D.updated_at ? ' · diperbarui ' + new Date(D.updated_at).toLocaleDateString('id-ID', { day: 'numeric', month: 'short', year: 'numeric' }) + (D.updated_by ? ' oleh ' + esc(D.updated_by) : '') : ''}.</p></div>
        <a class="btn o nf" href="${esc(D.print_url)}" target="_blank" rel="noopener">${ti('printer')} Cetak biodata</a></div>
      <form class="bd-form" id="bdf">
        <fieldset><legend>Identitas</legend><div class="bd-grid">
          <div><label for="bd_name">Nama lengkap</label><input id="bd_name" name="name" value="${esc(s.name)}" ${staff ? 'required maxlength="120"' : 'disabled'}></div>
          <div><label for="bd_nis">NIS</label><input id="bd_nis" value="${esc(s.nis || '')}" disabled></div>
          <div><label for="bd_nisn">NISN</label><input id="bd_nisn" name="nisn" value="${esc(s.nisn || '')}" maxlength="20" ${staff ? '' : 'disabled'}></div>
          <div><label for="bd_bd">Tanggal lahir</label><input id="bd_bd" type="date" name="birth_date" value="${esc(s.birth_date || '')}"></div>
          <div><label for="bd_g">Jenis kelamin</label><select id="bd_g" name="gender"><option value="">—</option><option value="L" ${s.gender === 'L' ? 'selected' : ''}>Laki-laki</option><option value="P" ${s.gender === 'P' ? 'selected' : ''}>Perempuan</option></select></div>
          ${staff ? '' : '<p class="mu bd-wide" style="margin:0;font-size:12px">Nama, NIS, dan NISN diubah oleh wali kelas atau admin bila ada yang salah.</p>'}
        </div></fieldset>
        ${GROUPS.map(([g, keys]) => `<fieldset><legend>${esc(g)}</legend><div class="bd-grid">${keys.map((k) => input(k, D.fields[k], p[k])).join('')}</div></fieldset>`).join('')}
        <div class="row" style="justify-content:flex-end"><button class="btn nf">${ti('check')} Simpan biodata</button></div>
      </form>`;
    $('#bdf', box).onsubmit = async (e) => {
      e.preventDefault();
      const btn = $('button.btn', e.target); btn.disabled = true;
      const body = Object.fromEntries([...new FormData(e.target)].map(([k, v]) => [k, typeof v === 'string' ? v.trim() : v]));
      if (!staff) { delete body.name; delete body.nisn; }
      try { D = await api(url, { method: 'PUT', body }); toast(D.message); draw(); }
      catch (x) { toast(x.message, true); btn.disabled = false; }
    };
  };
  draw();
}

/** Dialog biodata untuk admin/wali kelas. */
export function biodataDialog(studentId, name) {
  const { dialog } = window.MP;
  const d = dialog('Biodata · ' + (name || 'siswa'), '<div id="bdbox"><div class="empty"><span class="spin"></span></div></div>');
  d.classList.add('wide');
  mountBiodata($('#bdbox', d), { studentId }).catch((x) => { $('#bdbox', d).innerHTML = `<div class="alert er">${esc(x.message)}</div>`; });
  return d;
}

export const isStudent = () => S.user?.role === 'student';
