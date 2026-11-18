{{--
  Editor menu & istilah (dipakai di detail sekolah dan halaman template).
  Variabel: $catalog (SchoolMenu::catalogForUi), $value (['names','hidden']), $locked (rute terkunci paket; [] untuk template),
            $templates (koleksi MenuTemplate untuk "isi cepat"; boleh kosong), $schoolName (judul pratinjau).
--}}
@php
  $roles = \App\Support\SchoolMenu::ROLES;
  $max = \App\Support\SchoolMenu::MAX;
  $tplData = collect($templates ?? [])->map(fn ($t) => ['id' => $t->id, 'name' => $t->name, 'menu' => $t->menu()])->values();
  $grp = null;
@endphp
<div class="me-tabs" role="tablist" aria-label="Menu & istilah">
  <button type="button" role="tab" class="on" aria-selected="true" data-tab="edit">Atur nama menu</button>
  <button type="button" role="tab" aria-selected="false" data-tab="prev">Pratinjau portal</button>
</div>
<div class="me" data-me data-catalog='@json($catalog)' data-locked='@json(array_values($locked ?? []))' data-templates='@json($tplData)' data-max="{{ $max }}" data-school="{{ $schoolName ?? 'Nama Sekolah' }}">
  <div class="me-main" data-pane="edit">
    @if ($tplData->isNotEmpty())
      <div class="row" style="gap:8px;align-items:center;margin-bottom:8px">
        <select data-tpl style="width:auto;min-width:220px;margin:0" aria-label="Isi dari template">
          <option value="">Isi cepat dari template…</option>
          @foreach ($tplData as $t) <option value="{{ $t['id'] }}">{{ $t['name'] }}</option> @endforeach
        </select>
        <span class="mu" style="font-size:12px">Mengisi tabel di bawah; periksa lalu simpan.</span>
      </div>
    @endif
    <div class="me-legend"><span class="pl ok">Tampil</span><span class="pl wr">Disembunyikan</span>@if (! empty($locked))<span class="pl mu">🔒 Tidak termasuk paket</span>@endif<span class="pl in">Menu inti</span></div>
    <div class="sc me-scroll"><table class="me-tbl">
      <thead><tr><th>Menu</th>@foreach ($roles as $r => $rl)<th>Nama di portal {{ $rl }}</th>@endforeach<th>Tampil</th></tr></thead>
      <tbody>
      @foreach ($catalog as $m)
        @if ($m['group'] !== $grp) @php $grp = $m['group']; @endphp <tr class="me-grp"><td colspan="5">{{ $grp }}</td></tr> @endif
        @php
          $lk = in_array($m['key'], $locked ?? [], true);
          $hid = in_array($m['key'], $value['hidden'] ?? [], true);
          $def = $m['admin'] ?? $m['teacher'] ?? $m['student'];
        @endphp
        <tr data-k="{{ $m['key'] }}" class="{{ $lk ? 'me-lk' : '' }} {{ $hid ? 'me-off' : '' }}">
          <td class="me-nm"><b>{{ $def }}</b><small>{{ $m['core'] ? 'menu inti' : 'fitur' }}</small></td>
          @foreach ($roles as $r => $rl)
            @if ($m[$r] === null)
              <td class="me-na">—</td>
            @else
              @php $v = $value['names'][$m['key']][$r] ?? ''; @endphp
              <td class="me-cell"><input type="text" name="names[{{ $m['key'] }}][{{ $r }}]" data-role="{{ $r }}" value="{{ $v }}" placeholder="{{ $m[$r] }}" maxlength="{{ $max }}" class="{{ $v !== '' ? 'chg' : '' }}" @readonly($lk) aria-label="Nama {{ $m[$r] }} di portal {{ $rl }}"><span class="me-cnt">{{ $v !== '' ? mb_strlen($v).'/'.$max : '' }}</span></td>
            @endif
          @endforeach
          <td class="me-st">
            @if ($lk) <span class="pl mu">🔒 Di luar paket</span> @if (! $hid && ! $m['core']) <input type="hidden" name="show[]" value="{{ $m['key'] }}"> @endif
            @elseif ($m['core']) <span class="pl in">Menu inti</span>
            @else <label class="me-sw"><input type="checkbox" name="show[]" value="{{ $m['key'] }}" @checked(! $hid)><span class="t"></span><span class="l">{{ $hid ? 'Disembunyikan' : 'Tampil' }}</span></label>
            @endif
          </td>
        </tr>
      @endforeach
      </tbody>
    </table></div>
    <p class="mu" style="font-size:12px;margin:8px 0 0">Kosongkan nama untuk memakai nama bawaan. Maks. {{ $max }} karakter. Yang berubah: nama di menu samping &amp; menu bawah, judul tab, dan judul halaman. Menu di luar paket tetap terkunci.</p>
  </div>
  <div class="me-prev" data-pane="prev" hidden>
    <p class="mu" style="margin:0 0 10px;font-size:12.5px">Tampilan menu samping di tiap portal sesuai isian tab <b>Atur nama menu</b> (belum tersimpan sampai klik simpan). Nama yang diubah ditandai kuning.</p>
    <div class="me-phones">@foreach ($roles as $r => $rl)
      <div><div class="me-phone"><div class="me-top" data-top="{{ $r }}"><b data-sname></b><span>{{ $rl }}</span></div><nav class="me-nav" data-nav="{{ $r }}"></nav></div><p class="me-cap" data-cap="{{ $r }}"></p></div>
    @endforeach</div>
  </div>
</div>
<script @nonce>
(() => {
  const root = document.currentScript.previousElementSibling;
  if (!root || root.dataset.ready) return; root.dataset.ready = 1;
  const tabs = root.previousElementSibling;
  const CAT = JSON.parse(root.dataset.catalog), LOCK = new Set(JSON.parse(root.dataset.locked)), TPL = JSON.parse(root.dataset.templates), MAX = +root.dataset.max;
  root.querySelectorAll('[data-sname]').forEach((x) => (x.textContent = root.dataset.school));
  const esc = (v) => String(v).replace(/[&<>"]/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));
  const row = (k) => root.querySelector(`tr[data-k="${k}"]`);
  const custom = (m, r) => (row(m.key)?.querySelector(`input[data-role="${r}"]`)?.value || '').trim();
  const hidden = (k) => { const cb = row(k)?.querySelector('input[type=checkbox]'); return cb ? !cb.checked : false; };
  function preview() {
    root.querySelectorAll('[data-nav]').forEach((nav) => {
      const p = nav.dataset.nav;
      const list = CAT.filter((m) => m[p] && !hidden(m.key) && (p === 'admin' || !LOCK.has(m.key)));
      nav.innerHTML = list.map((m) => { const c = custom(m, p); return `<a class="${c ? 'fl' : ''}"><i></i>${esc(c || m[p])}${LOCK.has(m.key) ? ' <span style="margin-left:auto">🔒</span>' : ''}</a>`; }).join('') + '<a><i></i>Profil</a>';
      const n = list.filter((m) => custom(m, p)).length;
      root.querySelector(`[data-cap="${p}"]`).textContent = `${list.length} menu tampil${n ? ' · ' + n + ' nama diubah' : ''}`;
    });
  }
  function show(t) {
    tabs.querySelectorAll('button').forEach((b) => { const on = b.dataset.tab === t; b.classList.toggle('on', on); b.setAttribute('aria-selected', on); });
    root.querySelectorAll('[data-pane]').forEach((x) => (x.hidden = x.dataset.pane !== t));
    if (t === 'prev') preview();
  }
  tabs.addEventListener('click', (e) => { const b = e.target.closest('button[data-tab]'); if (b) show(b.dataset.tab); });
  root.addEventListener('input', (e) => {
    const i = e.target.closest('input[type=text]'); if (!i) return;
    i.classList.toggle('chg', !!i.value); i.nextElementSibling.textContent = i.value ? i.value.length + '/' + MAX : '';
  });
  root.addEventListener('change', (e) => {
    const cb = e.target.closest('input[type=checkbox]');
    if (cb) { const tr = cb.closest('tr'); tr.classList.toggle('me-off', !cb.checked); tr.querySelector('.l').textContent = cb.checked ? 'Tampil' : 'Disembunyikan'; return; }
    const sel = e.target.closest('select[data-tpl]'); if (!sel || !sel.value) return;
    const t = TPL.find((x) => String(x.id) === sel.value); sel.value = ''; if (!t) return;
    root.querySelectorAll('input[type=text]').forEach((i) => {
      const k = i.closest('tr').dataset.k; if (i.readOnly) return;
      i.value = t.menu.names?.[k]?.[i.dataset.role] || ''; i.dispatchEvent(new Event('input', { bubbles: true }));
    });
    root.querySelectorAll('input[type=checkbox][name="show[]"]').forEach((cb) => { cb.checked = !(t.menu.hidden || []).includes(cb.value); cb.dispatchEvent(new Event('change', { bubbles: true })); });
  });
})();
</script>
