<?php

/*
| MyPresence — index.php untuk shared hosting (cPanel).
| public_html/ berisi salinan folder public/, sedangkan aplikasi berada di luar public_html:
|   /home/USER/mypresence      ← kode, .env, storage (TIDAK bisa diakses dari internet)
|   /home/USER/public_html     ← berkas ini + css/js/gambar PWA
| Bila nama folder aplikasi berbeda, ubah $base di bawah.
*/

use Illuminate\Foundation\Application;
use Illuminate\Http\Request;

define('LARAVEL_START', microtime(true));

$base = dirname(__DIR__).'/mypresence';

if (file_exists($maintenance = $base.'/storage/framework/maintenance.php')) {
    require $maintenance;
}

require $base.'/vendor/autoload.php';

/** @var Application $app */
$app = require_once $base.'/bootstrap/app.php';
$app->usePublicPath(__DIR__);

$app->handleRequest(Request::capture());
