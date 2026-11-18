/* MyPresence PWA — Kas Kelas.
 * Bendahara kelas (bendahara 1/2, dipilih wali kelas di Struktur Kelas) mencatat pemasukan, pengeluaran, dan iuran.
 * Siswa sekelas melihat saldo, riwayat, dan status iuran. Wali kelas & admin hanya memantau (dibuka dari menu Wali Kelas).
 */
const { api, $, $$, esc, fd, hdr, empty, toast, dialog } = window.MP;
const busy = async (btn, fn) => { if (btn) btn.disabled = true; try { await fn(); } catch (x) { toast(x.message, true); } finally { if (btn) btn.disabled = false; } };
const ti = (n) => window.ti(n);
export const rp = (n) => 'Rp' + Number(n || 0).toLocaleString('id-ID');
const pill = (t, c) => `<span class="pl ${c}">${t}</span>`;
const STATUS = { lunas: ['Lunas', 'ok'], kurang: ['Kurang', 'wr'], belum: ['Belum bayar', 'er'] };

/** Foto nota diperkecil di perangkat (batas unggah server 2 MB). PDF dikirim apa adanya. */
async function shrink(file) {
  if (!file || !/^image\//.test(file.type) || file.size < 900 * 1024) return file;
  const im = await createImageBitmap(file);
  const k = Math.min(1, 1600 / Math.max(im.width, im.height));
  const c = document.createElement('canvas'); c.width = Math.round(im.width * k); c.height = Math.round(im.height * k);
  const g = c.getContext('2d'); g.fillStyle = '#fff'; g.fillRect(0, 0, c.width, c.height); g.drawImage(im, 0, 0, c.width, c.height);
  const b = await new Promise((r) => c.toBlob(r, 'image/jpeg', 0.82));
  return new File([b], file.name.replace(/\.\w+$/, '') + '.jpg', { type: 'image/jpeg' });
}

/** Halaman Kas Kelas (portal siswa). */
export async function kasPage(el) {
  const K = await api('class-cash');
  el.innerHTML = hdr('Kas Kelas', `Kelas ${esc(K.classroom.name)} · Bendahara: ${K.treasurers.length ? K.treasurers.map(esc).join(', ') : '<i>belum dipilih wali kelas</i>'}`,
    K.can_edit ? `<a class="btn o nf" href="/api/class-cash/export">${ti('download')} Ekspor Excel</a>` : '') + '<div id="kasBody"></div>';
  draw($('#kasBody', el), K, () => kasPage(el));
}

/** Mode pantau untuk wali kelas / admin (di dalam dialog). */
export async function kasMonitor(classroomId, name) {
  const d = dialog('Kas Kelas ' + name, '<div id="kasMon"><p class="mu">Memuat…</p></div>');
  d.classList.add('wide');
  const box = $('#kasMon', d);
  try {
    draw(box, await api('class-cash?classroom_id=' + classroomId), null);
    box.insertAdjacentHTML('afterbegin', `<p style="margin:0 0 10px;text-align:right"><a class="btn o sm nf" href="/api/class-cash/export?classroom_id=${classroomId}">${ti('download')} Ekspor Excel</a></p>`);
  }
  catch (x) { box.innerHTML = `<div class="alert er">${esc(x.message)}</div>`; }
}

/** Ringkasan singkat untuk kartu di menu Wali Kelas. */
export async function kasSummary(box, classroomId, name) {
  try {
    const K = await api('class-cash?classroom_id=' + classroomId);
    const n = K.members.length, lunas = K.members.filter((m) => m.status === 'lunas').length;
    box.innerHTML = `<div class="row" style="justify-content:space-between;align-items:center"><b style="font-size:16px">${ti('receipt')} Kas Kelas ${esc(name)}</b>
        <button class="btn o sm nf" type="button" data-kas style="flex:none;min-width:0">${ti('eye')} Pantau kas</button></div>
      <div class="gr kas-mini"><div><span class="mu">Saldo</span><b>${rp(K.balance)}</b></div><div><span class="mu">Masuk ${esc(K.month.label)}</span><b>${rp(K.month.in)}</b></div>
        <div><span class="mu">Keluar ${esc(K.month.label)}</span><b>${rp(K.month.out)}</b></div>
        <div><span class="mu">Iuran ${K.dues.amount ? esc(K.dues.label) : ''}</span><b>${K.dues.amount ? `${lunas}/${n} lunas` : 'Belum diatur'}</b></div></div>
      <p class="mu" style="margin:6px 0 0;font-size:12px">Bendahara: ${K.treasurers.length ? K.treasurers.map(esc).join(', ') : 'belum dipilih — tetapkan Bendahara 1/2 di Struktur Kelas di atas'}. Wali kelas hanya memantau.</p>`;
    $('[data-kas]', box).onclick = () => kasMonitor(classroomId, name);
  } catch (x) {
    box.innerHTML = `<b>${ti('receipt')} Kas Kelas</b><p class="mu">${esc(x.message)}</p>`;
  }
}

function draw(box, K, reload) {
  const n = K.members.length;
  const lunas = K.members.filter((m) => m.status === 'lunas').length;
  const mine = K.members.find((m) => m.id === K.me);
  box.innerHTML = `
    <div class="gr">
      <div class="card kas-saldo"><div class="mu">Saldo kas</div><div class="big">${rp(K.balance)}</div></div>
      <div class="card"><div class="mu">Masuk · ${esc(K.month.label)}</div><div class="big" style="color:var(--ok)">${rp(K.month.in)}</div></div>
      <div class="card"><div class="mu">Keluar · ${esc(K.month.label)}</div><div class="big" style="color:var(--er)">${rp(K.month.out)}</div></div>
      <div class="card"><div class="mu">Iuran ${K.dues.amount ? rp(K.dues.amount) + ' / ' + K.dues.period : ''}</div><div class="big">${K.dues.amount ? `${lunas}/${n}` : '–'}</div><div class="mu" style="font-size:12px">${K.dues.amount ? 'lunas · ' + esc(K.dues.label) : 'Iuran wajib belum diatur'}</div></div>
    </div>
    ${!K.is_student ? `<div class="alert in">Mode pantau: wali kelas & admin melihat kas kelas. Pencatatan oleh bendahara kelas (${K.treasurers.length ? K.treasurers.map(esc).join(', ') : 'belum dipilih'}).</div>`
      : !K.can_edit ? `<div class="alert in">${mine && K.dues.amount ? `Iuranmu ${esc(K.dues.label)}: <b>${STATUS[mine.status][0]}</b> (${rp(mine.paid)} dari ${rp(K.dues.amount)}). ` : ''}Kas dicatat oleh bendahara kelas. Bayar iuran langsung ke bendahara.</div>` : ''}
    ${K.can_edit ? form(K) : ''}
    <div class="card"><div class="row" style="justify-content:space-between;align-items:center"><b>Status iuran${K.dues.amount ? ' · ' + esc(K.dues.label) : ''}</b>
      ${K.can_edit ? `<details class="kas-set"><summary class="linkbtn">${ti('settings')} Atur iuran</summary><form id="kasS" class="row" style="align-items:end;margin-top:8px">
        <div style="flex:0 1 150px"><label for="kdA">Nominal per siswa</label><input id="kdA" name="dues_amount" inputmode="numeric" value="${K.dues.amount ? K.dues.amount.toLocaleString('id-ID') : ''}" placeholder="5.000" data-rp></div>
        <div style="flex:0 1 130px"><label for="kdP">Periode</label><select id="kdP" name="dues_period"><option value="minggu" ${K.dues.period === 'minggu' ? 'selected' : ''}>per minggu</option><option value="bulan" ${K.dues.period === 'bulan' ? 'selected' : ''}>per bulan</option></select></div>
        <button class="btn o nf">Simpan</button></form></details>` : ''}</div>
      <div class="sc" style="margin-top:8px"><table><thead><tr><th>Nama</th><th>Bayar periode ini</th><th>Status</th><th>Total iuran</th></tr></thead><tbody>
      ${n ? K.members.map((m) => `<tr${m.id === K.me ? ' class="kas-me"' : ''}><td>${esc(m.name)}${m.id === K.me ? ' <span class="chip">Kamu</span>' : ''}</td><td>${rp(m.paid)}</td>
        <td>${m.status ? pill(...STATUS[m.status]) : '<span class="mu">–</span>'}</td><td>${rp(m.total)}</td></tr>`).join('') : `<tr><td colspan="4">${empty('Belum ada siswa.')}</td></tr>`}
      </tbody></table></div></div>
    <div class="card"><b>Riwayat kas</b><div class="sc" style="margin-top:8px"><table><thead><tr><th>Tanggal</th><th>Keterangan</th><th style="text-align:right">Masuk</th><th style="text-align:right">Keluar</th><th>Bukti</th><th>Dicatat</th>${K.can_edit ? '<th></th>' : ''}</tr></thead><tbody>
      ${K.entries.length ? K.entries.map((e) => `<tr><td style="white-space:nowrap">${fd(e.date)}</td>
        <td><b>${esc(e.category_label)}</b>${e.student ? ' · ' + esc(e.student) : ''}${e.description ? `<div class="mu" style="font-size:12px">${esc(e.description)}</div>` : ''}</td>
        <td style="text-align:right;color:var(--ok)">${e.type === 'masuk' ? rp(e.amount) : ''}</td><td style="text-align:right;color:var(--er)">${e.type === 'keluar' ? rp(e.amount) : ''}</td>
        <td>${e.proof_url ? `<a href="${esc(e.proof_url)}" target="_blank" rel="noopener">${ti('eye')} Lihat</a>` : '<span class="mu">–</span>'}</td>
        <td class="mu" style="font-size:12px">${esc(e.by)}</td>${K.can_edit ? `<td>${e.can_delete ? `<button class="linkbtn" type="button" data-del="${e.id}">Hapus</button>` : ''}</td>` : ''}</tr>`).join('')
        : `<tr><td colspan="7">${empty('Belum ada transaksi kas.', K.can_edit ? 'Catat iuran atau pemasukan pertama di atas.' : 'Transaksi dicatat oleh bendahara kelas.')}</td></tr>`}
      </tbody></table></div></div>`;
  if (K.can_edit) bind(box, K, reload);
}

function form(K) {
  return `<form class="card" id="kasF"><b>${ti('receipt')} Catat transaksi kas</b>
    <div class="tabs" role="tablist" style="margin:10px 0"><button type="button" role="tab" data-ty="masuk" class="on" aria-selected="true">${ti('plus')} Pemasukan</button><button type="button" role="tab" data-ty="keluar" aria-selected="false">${ti('arrow-right')} Pengeluaran</button></div>
    <input type="hidden" name="type" value="masuk">
    <div class="row">
      <div style="flex:0 1 170px"><label for="kfD">Tanggal</label><input id="kfD" name="date" type="date" value="${K.today}" max="${K.today}" required></div>
      <div style="flex:0 1 200px"><label for="kfC">Kategori</label><select id="kfC" name="category"></select></div>
      <div style="flex:0 1 180px"><label for="kfA" id="kfAl">Nominal per siswa</label><input id="kfA" name="amount" inputmode="numeric" required placeholder="5.000" data-rp value="${K.dues.amount ? K.dues.amount.toLocaleString('id-ID') : ''}"></div></div>
    <div id="kfS"><label>Siswa yang membayar <span class="mu" id="kfN"></span></label>
      <div class="row" style="gap:12px;margin:2px 0 6px"><button type="button" class="linkbtn" data-pick="belum">Pilih yang belum lunas</button><button type="button" class="linkbtn" data-pick="all">Semua</button><button type="button" class="linkbtn" data-pick="none">Kosongkan</button></div>
      <div class="kas-pick">${K.members.map((m) => `<label class="opt"><input type="checkbox" name="student_ids[]" value="${m.id}" data-st="${m.status || ''}"> ${esc(m.name)} ${m.status ? pill(...STATUS[m.status]) : ''}</label>`).join('')}</div></div>
    <div class="row"><div style="flex:3;min-width:200px"><label for="kfK" id="kfKl">Keterangan</label><input id="kfK" name="description" maxlength="190" placeholder="Opsional"></div>
      <div style="flex:2;min-width:180px"><label for="kfB">Foto nota / bukti (opsional)</label><input id="kfB" name="proof" type="file" accept="image/*,.pdf,application/pdf"></div></div>
    <p class="mu" id="kfT" style="margin:6px 0 0;font-size:13px"></p>
    <button class="btn" style="margin-top:10px">Simpan transaksi</button></form>`;
}

function bind(box, K, reload) {
  const f = $('#kasF', box);
  const digits = (v) => Number(String(v).replace(/\D/g, '')) || 0;
  $$('[data-rp]', box).forEach((i) => i.addEventListener('input', () => { const d = String(i.value).replace(/\D/g, ''); i.value = d ? Number(d).toLocaleString('id-ID') : ''; sum(); }));
  const ty = () => f.type.value, iuran = () => ty() === 'masuk' && f.category.value === 'iuran';
  const picked = () => $$('input[name="student_ids[]"]:checked', f).length;
  const sum = () => {
    $('#kfN', f).textContent = iuran() ? `· ${picked()} dipilih` : '';
    const a = digits(f.amount.value);
    $('#kfT', f).innerHTML = !a ? '' : iuran() ? `Total masuk: <b>${rp(a * picked())}</b> (${picked()} × ${rp(a)})` : ty() === 'keluar' ? `Saldo setelah pengeluaran: <b>${rp(K.balance - a)}</b>${a > K.balance ? ' <span style="color:var(--er)">— saldo tidak cukup</span>' : ''}` : `Saldo menjadi <b>${rp(K.balance + a)}</b>`;
  };
  const cats = () => {
    const C = K.categories[ty()];
    f.category.innerHTML = Object.entries(C).map(([v, l]) => `<option value="${v}">${esc(l)}</option>`).join('');
    sync();
  };
  const sync = () => {
    $('#kfS', f).hidden = !iuran();
    $('#kfAl', f).textContent = iuran() ? 'Nominal per siswa' : 'Nominal';
    $('#kfKl', f).textContent = ty() === 'keluar' ? 'Keperluan (wajib)' : 'Keterangan';
    f.description.required = ty() === 'keluar';
    f.description.placeholder = ty() === 'keluar' ? 'Mis. beli spidol & penghapus papan' : 'Opsional';
    sum();
  };
  $$('[data-ty]', f).forEach((b) => (b.onclick = () => {
    $$('[data-ty]', f).forEach((x) => { x.classList.toggle('on', x === b); x.setAttribute('aria-selected', x === b); });
    f.type.value = b.dataset.ty; cats();
  }));
  f.category.onchange = sync;
  $$('input[name="student_ids[]"]', f).forEach((c) => (c.onchange = sum));
  $$('[data-pick]', f).forEach((b) => (b.onclick = () => {
    $$('input[name="student_ids[]"]', f).forEach((c) => (c.checked = b.dataset.pick === 'all' || (b.dataset.pick === 'belum' && c.dataset.st !== 'lunas')));
    sum();
  }));
  cats();
  f.onsubmit = (e) => {
    e.preventDefault();
    busy($('button.btn', f), async () => {
      const fd2 = new FormData(f);
      fd2.set('amount', digits(f.amount.value));
      if (!iuran()) fd2.delete('student_ids[]');
      const file = f.proof.files[0];
      if (file) fd2.set('proof', await shrink(file)); else fd2.delete('proof');
      const r = await api('class-cash', { method: 'POST', form: fd2 });
      toast(r.message); reload();
    });
  };
  const s = $('#kasS', box);
  if (s) s.onsubmit = (e) => { e.preventDefault(); busy($('button', s), async () => { const r = await api('class-cash/settings', { method: 'PUT', body: { dues_amount: digits(s.dues_amount.value), dues_period: s.dues_period.value } }); toast(r.message); reload(); }); };
  $$('[data-del]', box).forEach((b) => (b.onclick = () => { if (confirm('Hapus catatan kas ini?')) busy(b, async () => { const r = await api('class-cash/' + b.dataset.del, { method: 'DELETE' }); toast(r.message); reload(); }); }));
}
