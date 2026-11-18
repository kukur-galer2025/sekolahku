<?php

namespace App\Console\Commands;

use App\Models\School;
use App\Models\User;
use App\Tenancy\TenantFiles;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;

/**
 * Bersih-bersih file sisa di hosting. Default HANYA menampilkan daftar (aman); hapus dengan --hapus.
 *
 * Yang dibersihkan:
 *  1. Folder file & backup milik sekolah yang sudah dihapus dari platform (school_{id} yatim).
 *  2. Sisa proses: SQL/ZIP sementara backup database yang tertinggal di storage/app.
 *  3. Log lama: laravel-*.log > 14 hari; laravel.log > 10 MB dipangkas (sisakan 2 MB terakhir).
 *  4. Sesi login kedaluwarsa di tabel sessions.
 *  5. Foto profil panel yang sudah tidak dipakai.
 *  6. Zip update MyPresence yang tertinggal di folder home (mypresence-update-*.zip).
 *  7. Berkas lama yang sudah diganti versi baru (mis. pdf.js .mjs).
 * Hanya DILAPORKAN (tidak pernah dihapus otomatis): berkas di public_html yang tidak ada di aplikasi.
 * Folder lain di home (mis. mypresence_business_app) tidak disentuh.
 */
class CleanupFiles extends Command
{
    protected $signature = 'platform:bersihkan {--hapus : Benar-benar hapus (tanpa ini hanya menampilkan daftar)}';

    protected $description = 'Bersihkan file sisa: folder sekolah terhapus, file sementara, log lama, sesi kedaluwarsa, zip update';

    /** Berkas lama yang sudah diganti (relatif ke folder public). */
    private const OBSOLETE = ['vendor/pdfjs/pdf.min.mjs', 'vendor/pdfjs/pdf.worker.min.mjs'];

    private array $rows = [];

    private int $bytes = 0;

    public function handle(): int
    {
        $del = (bool) $this->option('hapus');
        $this->info($del ? 'Mode HAPUS — file di bawah ini dihapus.' : 'Mode CEK (aman) — belum ada yang dihapus. Jalankan ulang dengan --hapus untuk menghapus.');
        $this->newLine();

        $this->orphanSchools($del);
        $this->leftovers($del);
        $this->logs($del);
        $this->sessions($del);
        $this->avatars($del);
        $this->homeZips($del);
        $this->obsolete($del);

        $this->table(['Kategori', 'Jumlah', 'Ukuran', 'Contoh'], $this->rows ?: [['—', 0, '0 B', 'Tidak ada yang perlu dibersihkan']]);
        $this->line(($del ? 'Dibebaskan: ' : 'Bisa dibebaskan: ').self::size($this->bytes));
        $this->publicReport();

        return self::SUCCESS;
    }

    private function orphanSchools(bool $del): void
    {
        $ids = School::query()->withoutGlobalScopes()->pluck('id')->flip();
        $targets = [];
        $files = Storage::disk(TenantFiles::diskName());
        foreach ($files->directories() as $d) {
            if (preg_match('/^school_(\d+)$/', $d, $m) && ! isset($ids[(int) $m[1]])) {
                $targets[] = [$files, $d];
            }
        }
        $backups = Storage::disk(config('tenancy.backup_disk', 'local'));
        foreach ($backups->directories('backups') as $d) {
            if (preg_match('/school_(\d+)$/', $d, $m) && ! isset($ids[(int) $m[1]])) {
                $targets[] = [$backups, $d];
            }
        }
        $size = 0;
        foreach ($targets as [$disk, $dir]) {
            $size += array_sum(array_map(fn ($f) => $disk->size($f), $disk->allFiles($dir)));
            if ($del) {
                $disk->deleteDirectory($dir);
            }
        }
        $this->add('Folder sekolah yang sudah dihapus', count($targets), $size, array_map(fn ($t) => $t[1], $targets));
    }

    private function leftovers(bool $del): void
    {
        $paths = array_merge(glob(storage_path('app/db-backup-*.sql')) ?: [], glob(storage_path('app/db-*.zip')) ?: [], glob(storage_path('app/private/db-backup-*.sql')) ?: []);
        $paths = array_values(array_filter($paths, fn ($p) => is_file($p) && filemtime($p) < time() - 86400));
        $this->files('File sementara backup tertinggal', $paths, $del);
    }

    private function logs(bool $del): void
    {
        $old = array_values(array_filter(glob(storage_path('logs/*.log')) ?: [], fn ($p) => basename($p) !== 'laravel.log' && filemtime($p) < time() - 14 * 86400));
        $this->files('Log lama (> 14 hari)', $old, $del);

        $main = storage_path('logs/laravel.log');
        if (is_file($main) && filesize($main) > 10 * 1048576) {
            $cut = filesize($main) - 2 * 1048576;
            if ($del) {
                $fh = fopen($main, 'rb');
                fseek($fh, -2 * 1048576, SEEK_END);
                $tail = stream_get_contents($fh);
                fclose($fh);
                file_put_contents($main, '['.now()->format('Y-m-d H:i:s')."] cleanup: log dipangkas, 2 MB terakhir disimpan\n".$tail, LOCK_EX);
            }
            $this->add('laravel.log terlalu besar (dipangkas)', 1, $cut, ['storage/logs/laravel.log '.self::size(filesize($main) + ($del ? $cut : 0))]);
        }
    }

    private function sessions(bool $del): void
    {
        if (config('session.driver') !== 'database') {
            return;
        }
        $q = DB::table(config('session.table', 'sessions'))->where('last_activity', '<', time() - (int) config('session.lifetime', 120) * 60);
        $n = $del ? $q->delete() : $q->count();
        if ($n) {
            $this->rows[] = ['Sesi login kedaluwarsa (database)', $n, '—', 'baris tabel sessions'];
        }
    }

    private function avatars(bool $del): void
    {
        $disk = Storage::disk('local');
        $used = User::query()->withoutGlobalScopes()->whereNotNull('photo_path')->where('photo_path', 'like', 'panel/avatars/%')->pluck('photo_path')->flip();
        $orphans = array_values(array_filter($disk->files('panel/avatars'), fn ($f) => ! isset($used[$f])));
        $size = array_sum(array_map(fn ($f) => $disk->size($f), $orphans));
        if ($del) {
            $disk->delete($orphans);
        }
        $this->add('Foto profil panel tidak terpakai', count($orphans), $size, $orphans);
    }

    private function homeZips(bool $del): void
    {
        $home = dirname(base_path());
        $zips = array_values(array_filter(glob($home.'/mypresence-update-*.zip') ?: [], 'is_file'));
        $this->files('Zip update MyPresence di folder home', $zips, $del);
    }

    private function obsolete(bool $del): void
    {
        $roots = array_filter([public_path(), self::docroot()]);
        $found = [];
        foreach ($roots as $root) {
            foreach (self::OBSOLETE as $rel) {
                if (is_file($root.'/'.$rel)) {
                    $found[] = $root.'/'.$rel;
                }
            }
        }
        $this->files('Berkas versi lama yang sudah diganti', $found, $del);
    }

    /** Berkas di public_html yang tidak ada di folder public aplikasi — hanya laporan, tidak dihapus. */
    private function publicReport(): void
    {
        $doc = self::docroot();
        if (! $doc) {
            return;
        }
        $extra = [];
        foreach (File::allFiles($doc, true) as $f) {
            $rel = str_replace('\\', '/', $f->getRelativePathname());
            if (str_starts_with($rel, 'storage/') || in_array($rel, ['index.php', '.htaccess', '.user.ini', 'php.ini', 'robots.txt', 'error_log'], true)) {
                continue;
            }
            if (! is_file(public_path($rel))) {
                $extra[] = $rel.' ('.self::size($f->getSize()).')';
            }
        }
        $this->newLine();
        if (! $extra) {
            $this->line('public_html: semua berkas cocok dengan aplikasi.');

            return;
        }
        $this->warn('Berkas di public_html yang TIDAK ada di aplikasi ('.count($extra).') — periksa manual, TIDAK dihapus otomatis:');
        foreach (array_slice($extra, 0, 40) as $e) {
            $this->line('  · '.$e);
        }
        if (count($extra) > 40) {
            $this->line('  … dan '.(count($extra) - 40).' lainnya');
        }
    }

    // ── bantuan ──

    private function files(string $label, array $paths, bool $del): void
    {
        $size = array_sum(array_map(fn ($p) => (int) @filesize($p), $paths));
        if ($del) {
            foreach ($paths as $p) {
                @unlink($p);
            }
        }
        $home = dirname(base_path());
        $this->add($label, count($paths), $size, array_map(fn ($p) => str_replace($home.'/', '~/', $p), $paths));
    }

    private function add(string $label, int $n, int $size, array $examples): void
    {
        if (! $n) {
            return;
        }
        $this->bytes += $size;
        $ex = implode(', ', array_slice($examples, 0, 3)).(count($examples) > 3 ? ' …(+'.(count($examples) - 3).')' : '');
        $this->rows[] = [$label, $n, self::size($size), mb_strimwidth($ex, 0, 90, '…')];
    }

    /** ~/public_html bila aplikasi dipasang di ~/mypresence dengan docroot terpisah. */
    private static function docroot(): ?string
    {
        $d = dirname(base_path()).'/public_html';

        return is_dir($d) && realpath($d) !== realpath(public_path()) ? $d : null;
    }

    private static function size(int $b): string
    {
        return $b >= 1048576 ? round($b / 1048576, 1).' MB' : ($b >= 1024 ? round($b / 1024).' KB' : $b.' B');
    }
}
