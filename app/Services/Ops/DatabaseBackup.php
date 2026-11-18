<?php

namespace App\Services\Ops;

use App\Services\Platform\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use ZipArchive;

/**
 * Backup database PENUH tanpa mysqldump (shared hosting: shell_exec/proc_open dimatikan).
 * Dump SQL dibuat lewat PDO, lalu dibungkus ZIP AES-256 dengan BACKUP_PASSWORD.
 * Disimpan di disk backup: backups/db/db-YYYYmmdd-HHiiss.zip (di luar public_html), dirotasi BACKUP_KEEP.
 *
 * Pulihkan: buka ZIP dengan 7-Zip (password = BACKUP_PASSWORD) → impor database.sql lewat phpMyAdmin.
 */
class DatabaseBackup
{
    public const DIR = 'backups/db';

    public function __construct(private AuditLogger $audit) {}

    private function disk()
    {
        return Storage::disk(config('tenancy.backup_disk', 'local'));
    }

    /** @return array{name:string,size:int,tables:int,rows:int} */
    public function create(?string $by = null): array
    {
        $conn = DB::connection();
        if ($conn->getDriverName() !== 'mysql' && $conn->getDriverName() !== 'mariadb') {
            throw new RuntimeException('Backup database penuh hanya untuk MySQL/MariaDB.');
        }
        @set_time_limit(0);
        $pdo = $conn->getPdo();
        $tmp = storage_path('app/db-backup-'.Str::random(8).'.sql');
        $fh = fopen($tmp, 'wb');
        if (! $fh) {
            throw new RuntimeException('Tidak bisa menulis berkas sementara di storage/app.');
        }

        $tables = 0;
        $rows = 0;
        // Tanpa buffer: tabel besar (attendances) dialirkan baris demi baris, memori tetap kecil.
        $buffered = $pdo->getAttribute(\PDO::MYSQL_ATTR_USE_BUFFERED_QUERY);
        try {
            fwrite($fh, "-- MyPresence database backup\n-- ".now()->toIso8601String().' · '.config('app.url')."\n");
            fwrite($fh, "SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\nSET SQL_MODE='NO_AUTO_VALUE_ON_ZERO';\n\n");

            foreach ($pdo->query("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'")->fetchAll(\PDO::FETCH_NUM) as [$table]) {
                $tables++;
                $q = '`'.str_replace('`', '``', $table).'`';
                $pdo->setAttribute(\PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, true);
                $create = $pdo->query("SHOW CREATE TABLE $q")->fetch(\PDO::FETCH_NUM)[1];
                fwrite($fh, "DROP TABLE IF EXISTS $q;\n$create;\n");

                $pdo->setAttribute(\PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, false);
                $stmt = $pdo->query("SELECT * FROM $q");
                $batch = [];
                while ($r = $stmt->fetch(\PDO::FETCH_NUM)) {
                    $batch[] = '('.implode(',', array_map(fn ($v) => $v === null ? 'NULL' : $pdo->quote((string) $v), $r)).')';
                    $rows++;
                    if (count($batch) >= 200) {
                        fwrite($fh, "INSERT INTO $q VALUES\n".implode(",\n", $batch).";\n");
                        $batch = [];
                    }
                }
                if ($batch) {
                    fwrite($fh, "INSERT INTO $q VALUES\n".implode(",\n", $batch).";\n");
                }
                $stmt->closeCursor();
                fwrite($fh, "\n");
            }
            fwrite($fh, "SET FOREIGN_KEY_CHECKS=1;\n");
        } finally {
            fclose($fh);
            $pdo->setAttribute(\PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, $buffered);
        }

        $name = 'db-'.now()->format('Ymd-His').'.zip';
        $zipPath = storage_path('app/'.$name);
        $zip = new ZipArchive;
        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            @unlink($tmp);
            throw new RuntimeException('Tidak bisa membuat ZIP backup.');
        }
        $zip->addFile($tmp, 'database.sql');
        $password = (string) config('tenancy.backup_password');
        if ($password !== '') {
            $zip->setPassword($password);
            $zip->setEncryptionName('database.sql', ZipArchive::EM_AES_256);
        }
        $zip->close();
        @unlink($tmp);

        $stream = fopen($zipPath, 'rb');
        $this->disk()->put(self::DIR.'/'.$name, $stream);
        is_resource($stream) && fclose($stream);
        $size = (int) filesize($zipPath);
        @unlink($zipPath);
        $this->rotate();

        $this->audit->log('ops.db_backup', "Backup database penuh {$name} ({$tables} tabel, {$rows} baris)".($password === '' ? ' — TANPA enkripsi (BACKUP_PASSWORD kosong)' : ''), null, null, ['size' => $size], $by ?? 'Sistem');

        return ['name' => $name, 'size' => $size, 'tables' => $tables, 'rows' => $rows];
    }

    /** @return list<array{name:string,size:int,time:int}> terbaru dulu */
    public function list(): array
    {
        return collect($this->disk()->files(self::DIR))
            ->filter(fn ($f) => preg_match('/^db-\d{8}-\d{6}\.zip$/', basename($f)))
            ->map(fn ($f) => ['name' => basename($f), 'size' => $this->disk()->size($f), 'time' => $this->disk()->lastModified($f)])
            ->sortByDesc('name')->values()->all();
    }

    public function rotate(): int
    {
        $old = array_slice($this->list(), max(1, (int) config('tenancy.backup_keep', 14)));
        foreach ($old as $f) {
            $this->disk()->delete(self::DIR.'/'.$f['name']);
        }

        return count($old);
    }

    public function download(string $name)
    {
        abort_unless(preg_match('/^db-\d{8}-\d{6}\.zip$/', $name), 404);
        $path = self::DIR.'/'.$name;
        abort_unless($this->disk()->exists($path), 404);

        return $this->disk()->download($path, 'mypresence-'.$name);
    }

    /** Backup terakhir lebih tua dari 26 jam = cron/backup bermasalah. */
    public function stale(): bool
    {
        $last = $this->list()[0]['time'] ?? null;

        return ! $last || now()->getTimestamp() - $last > 26 * 3600;
    }
}
