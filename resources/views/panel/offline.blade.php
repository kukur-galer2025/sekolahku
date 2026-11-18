<!DOCTYPE html>
<html lang="id" data-portal="panel"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title>Offline · Panel Parama Tech</title>
@include('panel.pwa-head')
<style>
  .off{min-height:100vh;display:grid;place-items:center;padding:24px;text-align:center}
  .off .lc{max-width:380px}
  .off img{width:84px;height:84px;border-radius:20px;box-shadow:0 10px 30px #0b2d6b40}
  .off h1{font-size:20px;margin:16px 0 6px}
</style>
<script src="/js/csp-actions.js?v=1" defer></script></head>
<body><div class="off"><div class="lc">
  <img src="/pwa/icons/panel-192.png" alt="">
  <h1>Kamu sedang offline</h1>
  <p class="mu">Panel butuh koneksi internet. Data sekolah, keuangan, dan gaji sengaja tidak disimpan di perangkat demi keamanan.</p>
  <button class="btn w" type="button" data-action="reload" style="margin-top:14px">Coba lagi</button>
</div></div>
<script @nonce>window.addEventListener('online', () => location.reload());</script>
</body></html>
