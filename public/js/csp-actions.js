/*
 * Pengganti atribut onclick/onsubmit/onchange inline, supaya CSP tidak perlu 'unsafe-inline' untuk skrip.
 * Dimuat dari domain sendiri; aman dimuat lebih dari sekali.
 *
 *   <form data-confirm="Hapus?">            konfirmasi sebelum kirim
 *   <button data-confirm="Simpan?">         konfirmasi sebelum klik (tombol/tautan)
 *   data-action="print"                     window.print()
 *   data-action="reload"                    location.reload()
 *   data-action="install"                   tombol pasang PWA panel (window.mpInstall)
 *   data-action="select"                    pilih seluruh isi input
 *   data-action="copy" data-target="#id"    salin nilai input ke clipboard
 *   data-action="click" data-target="sel"   klik elemen lain (mis. input berkas tersembunyi)
 *   data-action="check-all" data-target="sel"  centang/hapus centang semua sesuai kotak ini
 *   data-action="reset-menu"                isi ulang menu sekolah ke bawaan (form panel)
 *   <select data-navigate="url?mode=">      buka url + nilai terpilih saat berubah
 */
(function () {
  if (window.__mpCspActions) return;
  window.__mpCspActions = true;

  document.addEventListener('submit', function (e) {
    var f = e.target;
    if (f instanceof HTMLFormElement && f.dataset.confirm && !window.confirm(f.dataset.confirm)) e.preventDefault();
  }, true);

  document.addEventListener('click', function (e) {
    var el = e.target instanceof Element ? e.target.closest('[data-confirm]:not(form), [data-action]') : null;
    if (!el) return;

    if (el.dataset.confirm && !(el instanceof HTMLFormElement) && !window.confirm(el.dataset.confirm)) {
      e.preventDefault();
      e.stopImmediatePropagation();
      return;
    }

    var target = el.dataset.target;
    switch (el.dataset.action) {
      case 'print':
        e.preventDefault(); window.print(); break;
      case 'reload':
        e.preventDefault(); location.reload(); break;
      case 'install':
        if (typeof window.mpInstall === 'function') window.mpInstall(); break;
      case 'select':
        if (typeof el.select === 'function') el.select(); break;
      case 'copy': {
        var src = target && document.querySelector(target);
        if (!src || !navigator.clipboard) break;
        var label = el.textContent;
        navigator.clipboard.writeText(src.value).then(function () {
          el.textContent = '✓ Tersalin';
          setTimeout(function () { el.textContent = label; }, 1500);
        });
        break;
      }
      case 'click': {
        var t = target && document.querySelector(target);
        if (t) t.click();
        break;
      }
      case 'check-all':
        if (target) document.querySelectorAll(target).forEach(function (c) { c.checked = el.checked; });
        break;
      case 'reset-menu':
        // Konfirmasi sudah lewat (data-confirm); kosongkan nama menu & centang semua sebelum form terkirim.
        if (el.form) {
          el.form.querySelectorAll('.me input[type=text]').forEach(function (i) { i.value = ''; });
          el.form.querySelectorAll('.me input[type=checkbox]').forEach(function (c) { c.checked = true; });
        }
        break;
    }
  }, true);

  document.addEventListener('change', function (e) {
    var el = e.target;
    if (el instanceof HTMLSelectElement && el.dataset.navigate) location.href = el.dataset.navigate + encodeURIComponent(el.value);
  });
})();
