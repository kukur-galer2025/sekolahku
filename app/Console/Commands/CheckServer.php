<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Cek kesiapan server (terutama shared hosting/cPanel):
 *   php artisan platform:check
 * Di cPanel tanpa terminal: jalankan lewat Cron Jobs sekali, hasilnya di storage/logs/check.txt.
 */
class CheckServer extends Command
{
    protected $signature = 'platform:check {--out= : simpan hasil ke berkas}';

    protected $description = 'Periksa versi PHP, ekstensi, izin folder, database, dan konfigurasi produksi';

    private array $rows = [];

    private int $fail = 0;

    public function handle(): int
    {
        $this->row('PHP >= 8.2', version_compare(PHP_VERSION, '8.2.0', '>='), PHP_VERSION);
        foreach (['pdo_mysql', 'mbstring', 'openssl', 'tokenizer', 'xml', 'ctype', 'fileinfo', 'curl', 'zip', 'gd', 'intl'] as $ext) {
            $this->row("ekstensi $ext", extension_loaded($ext), extension_loaded($ext) ? 'ada' : 'AKTIFKAN di Select PHP Version');
        }
        $gd = function_exists('gd_info') ? gd_info() : [];
        $this->row('GD mendukung WebP (logo & foto)', ! empty($gd['WebP Support']), ! empty($gd['WebP Support']) ? 'ya' : 'tidak');
        $this->row('Zip mendukung enkripsi AES (backup)', class_exists(\ZipArchive::class) && method_exists(\ZipArchive::class, 'setEncryptionName')
            && \ZipArchive::isEncryptionMethodSupported(\ZipArchive::EM_AES_256), 'libzip '.(defined('ZipArchive::LIBZIP_VERSION') ? \ZipArchive::LIBZIP_VERSION : '?'));
        $this->row('fungsi proc_open (queue worker)', function_exists('proc_open') && ! in_array('proc_open', array_map('trim', explode(',', (string) ini_get('disable_functions'))), true), 'dibutuhkan runInBackground');

        foreach (['storage/framework/cache', 'storage/framework/sessions', 'storage/framework/views', 'storage/logs', 'storage/app', 'bootstrap/cache'] as $d) {
            $this->row("folder $d bisa ditulis", is_writable(base_path($d)), base_path($d));
        }

        $this->row('APP_KEY terisi', (string) config('app.key') !== '', '');
        $this->row('APP_ENV=production & APP_DEBUG=false', app()->environment('production') && ! config('app.debug'), app()->environment().' / debug='.(config('app.debug') ? 'true' : 'false'));
        $this->row('APP_DOMAIN', ! str_ends_with((string) config('tenancy.central_domain'), '.test'), (string) config('tenancy.central_domain'));
        $this->row('SESSION_DOMAIN kosong (isolasi sekolah)', in_array(config('session.domain'), [null, ''], true), var_export(config('session.domain'), true));

        try {
            $v = DB::connection()->getDriverName().' '.DB::getPdo()->getAttribute(\PDO::ATTR_SERVER_VERSION);
            $this->row('koneksi database', true, $v);
            $this->row('tabel sudah dimigrasi', Schema::hasTable('schools') && Schema::hasTable('invoices'), Schema::hasTable('schools') ? 'ya' : 'jalankan php artisan migrate --force');
        } catch (Throwable $e) {
            $this->row('koneksi database', false, mb_substr($e->getMessage(), 0, 120));
        }
        try {
            Cache::put('check:ping', 1, 10);
            $this->row('cache ('.Cache::getDefaultDriver().')', Cache::get('check:ping') === 1, '');
        } catch (Throwable $e) {
            $this->row('cache', false, mb_substr($e->getMessage(), 0, 120));
        }
        $this->row('BACKUP_PASSWORD terisi', (string) config('tenancy.backup_password') !== '', '');
        $this->row('HEALTH_TOKEN terisi', (string) config('app.health_token') !== '', '');
        $mail = config('mail.default');
        $this->row('email keluar (MAIL_MAILER)', ! in_array($mail, ['log', 'array'], true), $mail);

        $out = collect($this->rows)->map(fn ($r) => sprintf('[%s] %-42s %s', $r[1] ? ' OK ' : 'GAGAL', $r[0], $r[2]))->implode(PHP_EOL);
        $summary = $this->fail ? "{$this->fail} pemeriksaan GAGAL — perbaiki sebelum dipakai sekolah." : 'Semua pemeriksaan OK.';
        $text = 'MyPresence platform:check — '.now()->timezone('Asia/Jakarta')->format('Y-m-d H:i').' WIB'.PHP_EOL.$out.PHP_EOL.$summary.PHP_EOL;
        $this->line($text);
        if ($file = $this->option('out')) {
            // Jalur absolut: /var/... (Linux) atau D:\... / D:/... / \\server\... (Windows).
            $absolute = str_starts_with($file, '/') || str_starts_with($file, '\\') || preg_match('#^[A-Za-z]:[\\\\/]#', $file);
            file_put_contents($absolute ? $file : base_path($file), $text);
        }

        return $this->fail ? self::FAILURE : self::SUCCESS;
    }

    private function row(string $label, bool $ok, string $detail): void
    {
        $this->rows[] = [$label, $ok, $detail];
        $this->fail += $ok ? 0 : 1;
    }
}
