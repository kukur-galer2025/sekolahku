<?php

namespace App\Console\Commands;

use App\Services\Notify\WebPush;
use Illuminate\Console\Command;

/** Buat kunci VAPID untuk push notification. Cukup sekali; mengganti kunci membuat semua HP harus mengizinkan ulang. */
class GenerateVapidKeys extends Command
{
    protected $signature = 'push:vapid {--write : tulis langsung ke .env (tanpa menampilkan kunci privat)}';

    protected $description = 'Buat kunci VAPID (Web Push) untuk notifikasi MyPresence';

    public function handle(): int
    {
        if (WebPush::configured() && ! $this->confirm('Kunci VAPID sudah ada. Ganti? Semua HP harus mengaktifkan notifikasi ulang.', false)) {
            return self::SUCCESS;
        }
        $k = WebPush::generateKeys();
        $lines = "VAPID_PUBLIC_KEY={$k['public']}\nVAPID_PRIVATE_KEY={$k['private']}\n";

        if ($this->option('write')) {
            $env = base_path('.env');
            $content = (string) @file_get_contents($env);
            $content = preg_replace('/^VAPID_(PUBLIC|PRIVATE)_KEY=.*\R?/m', '', $content);
            file_put_contents($env, rtrim($content)."\n\n# Push notification (php artisan push:vapid --write)\n".$lines);
            $this->info('Kunci VAPID ditulis ke .env. Jalankan: php artisan optimize:clear');

            return self::SUCCESS;
        }

        $this->line($lines);
        $this->comment('Salin dua baris di atas ke .env, lalu jalankan php artisan optimize:clear');

        return self::SUCCESS;
    }
}
