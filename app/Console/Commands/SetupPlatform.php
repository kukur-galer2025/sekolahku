<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Symfony\Component\Console\Output\BufferedOutput;
use Throwable;

/**
 * Instalasi awal di shared hosting tanpa terminal — jalankan SEKALI lewat Cron Jobs cPanel:
 *   php /home/USER/mypresence/artisan platform:setup superadmin email@domain.id
 * Hasil: storage/logs/setup.txt (buka lewat File Manager). Hapus baris cron-nya setelah berhasil.
 */
class SetupPlatform extends Command
{
    protected $signature = 'platform:setup {username=superadmin} {email?} {--skip-optimize : lewati cache konfigurasi (dipakai tes)}';

    protected $description = 'Migrasi database, buat super-admin dari SUPERADMIN_PASSWORD, lalu cek server (untuk cPanel)';

    public function handle(): int
    {
        $log = storage_path('logs/setup.txt');
        $lines = ['MyPresence platform:setup — '.now()->timezone('Asia/Jakarta')->format('Y-m-d H:i:s').' WIB', ''];
        $ok = true;

        $run = function (string $cmd, array $args = []) {
            $buf = new BufferedOutput;
            $code = Artisan::call($cmd, $args, $buf);

            return [$code, $buf->fetch()];
        };
        $step = function (string $title, callable $fn) use (&$lines, &$ok) {
            $lines[] = "── $title";
            try {
                [$code, $out] = $fn();
                $lines[] = trim($out) ?: '(tanpa keluaran)';
                $lines[] = $code === 0 ? '→ OK' : '→ GAGAL';
                $ok = $ok && $code === 0;
            } catch (Throwable $e) {
                $lines[] = '→ GAGAL: '.$e->getMessage();
                $ok = false;
            }
            $lines[] = '';
        };

        // Konfigurasi lama yang di-cache membuat env() di luar config bernilai null — bersihkan dulu.
        // Hanya berkas cache (bukan cache:clear: tabel cache belum ada pada instalasi baru).
        foreach (['config:clear', 'route:clear', 'event:clear', 'view:clear'] as $c) {
            try {
                $run($c);
            } catch (Throwable) {
            }
        }

        $step('1. Migrasi database', fn () => $run('migrate', ['--force' => true]));

        if ((string) env('SUPERADMIN_PASSWORD') !== '' && $this->argument('email')) {
            $step('2. Akun super-admin', fn () => $run('platform:superadmin', [
                'username' => $this->argument('username'), 'email' => $this->argument('email'), '--from-env' => true,
            ]));
        } else {
            $lines[] = '── 2. Akun super-admin: dilewati (isi SUPERADMIN_PASSWORD di .env dan email di perintah cron untuk membuatnya)';
            $lines[] = '';
        }

        if (! $this->option('skip-optimize')) {
            $step('3. Cache konfigurasi & rute', fn () => $run('optimize'));
        }
        $step('4. Cek server', fn () => $run('platform:check'));

        $lines[] = $ok ? 'SELESAI. Hapus baris cron platform:setup dan kosongkan SUPERADMIN_PASSWORD di .env.' : 'Ada langkah GAGAL — kirim isi berkas ini ke Parama Tech.';
        @file_put_contents($log, implode(PHP_EOL, $lines).PHP_EOL);
        $this->line(implode(PHP_EOL, $lines));

        return $ok ? self::SUCCESS : self::FAILURE;
    }
}
