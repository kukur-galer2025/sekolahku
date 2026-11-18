const $ = (s, r = document) => r.querySelector(s);
/* MyPresence PWA — kamera & pemindai QR (dipakai siswa dan guru). */
let stream = null, loop = 0;
export function stopCamera() {
  cancelAnimationFrame(loop);
  stream?.getTracks().forEach((t) => t.stop());
  stream = null;
}
addEventListener('hashchange', stopCamera);
document.addEventListener('visibilitychange', () => document.hidden && stopCamera());

/** @param pattern regex isi QR yang diterima (bawaan: QR kelas MP-…) */
export async function startCamera(box, onCode, { pattern = /^MP-\d+-/, hint = 'Arahkan kamera ke QR kelas.' } = {}) {
  if (!navigator.mediaDevices?.getUserMedia) throw new Error('Browser ini tidak mendukung kamera. Ketik kode di bawah QR.');
  try {
    stream = await navigator.mediaDevices.getUserMedia({ video: { facingMode: { ideal: 'environment' }, width: { ideal: 1280 } }, audio: false });
  } catch (e) {
    throw new Error(e.name === 'NotAllowedError' ? 'Izin kamera ditolak. Izinkan kamera untuk situs ini di pengaturan browser.' : 'Kamera tidak bisa dibuka: ' + e.message);
  }
  box.innerHTML = '<div class="scan-box"><video playsinline muted></video></div><p class="mu" style="text-align:center">' + hint + ' <button class="linkbtn" id="stop" type="button">Tutup kamera</button></p>';
  const video = $('video', box);
  video.srcObject = stream;
  await video.play();
  $('#stop', box).onclick = () => { stopCamera(); box.innerHTML = ''; const b = $('#start'); if (b) b.disabled = false; };

  let detect;
  if ('BarcodeDetector' in window && (await BarcodeDetector.getSupportedFormats?.())?.includes('qr_code')) {
    const bd = new BarcodeDetector({ formats: ['qr_code'] });
    detect = async () => (await bd.detect(video))[0]?.rawValue;
  } else {
    // iPhone/Safari: jsQR (Apache-2.0) dimuat hanya saat dibutuhkan.
    if (!window.jsQR) await new Promise((ok, no) => { const s = document.createElement('script'); s.src = '/pwa/vendor/jsQR.js'; s.onload = ok; s.onerror = () => no(new Error('Pemindai gagal dimuat. Ketik kode di bawah QR.')); document.head.append(s); });
    const c = document.createElement('canvas'), ctx = c.getContext('2d', { willReadFrequently: true });
    detect = async () => {
      const w = video.videoWidth, h = video.videoHeight; if (!w) return null;
      const sc = Math.min(1, 640 / Math.max(w, h)); c.width = w * sc; c.height = h * sc;
      ctx.drawImage(video, 0, 0, c.width, c.height);
      return window.jsQR(ctx.getImageData(0, 0, c.width, c.height).data, c.width, c.height, { inversionAttempts: 'dontInvert' })?.data;
    };
  }
  let busy = false, last = 0;
  const tick = async (t) => {
    if (!stream) return;
    if (!busy && t - last > 180) {
      busy = true; last = t;
      try { const v = await detect(); if (v && pattern.test(v)) { onCode(v); busy = false; return; } } catch (_) {}
      busy = false;
    }
    loop = requestAnimationFrame(tick);
  };
  loop = requestAnimationFrame(tick);
}
