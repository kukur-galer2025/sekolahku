<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

/*
| Di VPS cukup satu cron:  * * * * * cd /var/www/mypresence && php artisan schedule:run >> /dev/null 2>&1
|
| Shared hosting (Rumahweb cPanel) mematikan proc_open, sehingga Schedule::command() (yang menjalankan
| "php artisan ..." sebagai proses terpisah) selalu gagal. Karena itu semua tugas dijalankan DI DALAM proses
| schedule:run lewat Artisan::call(). Hasilnya sama, tanpa butuh proc_open.
*/
$task = fn (string $command, string $name) => Schedule::call(fn () => Artisan::call($command))->name($name);

// Alpa otomatis per sekolah (zona waktu masing-masing).
$task('attendance:mark-absent', 'attendance:mark-absent')->everyFiveMinutes()->withoutOverlapping(10)->onOneServer();

// Hapus berkas impor & daftar password awal yang lebih tua dari 7 hari.
Schedule::call(fn () => \App\Services\Import\ImportManager::prune(7))->dailyAt('02:10')->name('imports:prune')->onOneServer();

// Backup harian per sekolah (ZIP terenkripsi, rotasi BACKUP_KEEP).
$task('school:backup --all', 'school:backup')->dailyAt('01:30')->withoutOverlapping(60)->onOneServer();

// Hapus berkas ekspor data yang sudah kedaluwarsa (7 hari).
Schedule::call(fn () => \App\Services\Platform\SchoolExport::prune())->dailyAt('02:20')->name('exports:prune')->onOneServer();

// Langganan: pengingat H-14/7/3/1, invoice perpanjangan, masa tenggang, blokir otomatis. Pagi WIB.
$task('billing:run', 'billing:run')->timezone('Asia/Jakarta')->dailyAt('07:05')->withoutOverlapping(30)->onOneServer();

// Detak scheduler untuk /health (cron mati = monitor uptime berbunyi).
Schedule::call(fn () => \Illuminate\Support\Facades\Cache::forever(\App\Services\Ops\HealthCheck::HEARTBEAT, now()->getTimestamp()))->everyMinute()->name('ops:heartbeat')->onOneServer();

// Bersihkan job gagal > 30 hari.
$task('queue:prune-failed --hours=720', 'queue:prune-failed')->dailyAt('03:10')->onOneServer();

// Lifecycle (Tahap 6): pengingat tutup tahun ajaran, akhir masa alumni 90 hari, anonimisasi data siswa yang lama keluar.
$task('lifecycle:daily', 'lifecycle:daily')->timezone('Asia/Jakarta')->dailyAt('07:20')->withoutOverlapping(30)->onOneServer();

// Tahap 7 — operasional: backup database penuh 00:45 WIB & pembersihan data menumpuk.
$task('db:backup', 'db:backup')->timezone('Asia/Jakarta')->dailyAt('00:45')->withoutOverlapping(30)->onOneServer();
$task('ops:prune', 'ops:prune')->timezone('Asia/Jakarta')->dailyAt('03:30')->onOneServer();

// Shared hosting tanpa Supervisor: worker antrean dijalankan tiap menit di dalam scheduler
// sampai antrean kosong (maks. 45 detik). Aktifkan dengan QUEUE_VIA_SCHEDULER=true di .env.
if (config('queue.via_scheduler')) {
    $task('queue:work --stop-when-empty --max-time=45 --tries=3 --backoff=30', 'queue:cron-worker')
        ->everyMinute()->withoutOverlapping(2);
}
