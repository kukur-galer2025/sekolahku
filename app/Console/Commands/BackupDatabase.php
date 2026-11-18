<?php

namespace App\Console\Commands;

use App\Services\Ops\DatabaseBackup;
use Illuminate\Console\Command;

class BackupDatabase extends Command
{
    protected $signature = 'db:backup';

    protected $description = 'Backup database penuh (tanpa mysqldump) ke ZIP AES-256, dirotasi BACKUP_KEEP';

    public function handle(DatabaseBackup $backup): int
    {
        $r = $backup->create();
        $this->info("Backup {$r['name']}: {$r['tables']} tabel, {$r['rows']} baris, ".number_format($r['size'] / 1024, 0, ',', '.').' KB');
        if ((string) config('tenancy.backup_password') === '') {
            $this->warn('BACKUP_PASSWORD kosong: backup TIDAK terenkripsi. Isi BACKUP_PASSWORD di .env.');
        }

        return self::SUCCESS;
    }
}
