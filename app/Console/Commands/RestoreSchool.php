<?php

namespace App\Console\Commands;

use App\Models\School;
use App\Services\Platform\SchoolBackup;
use Illuminate\Console\Command;

/**
 * php artisan school:restore smkn1 20261103-013000.zip
 * Sengaja hanya lewat CLI (bukan tombol di panel): mengganti seluruh data satu sekolah.
 */
class RestoreSchool extends Command
{
    protected $signature = 'school:restore {subdomain} {backup? : Nama berkas backup; kosongkan untuk melihat daftar} {--force : Tanpa konfirmasi}';

    protected $description = 'Pulihkan data & berkas SATU sekolah dari backup-nya';

    public function handle(SchoolBackup $backup): int
    {
        $school = School::query()->where('subdomain', $this->argument('subdomain'))->first();
        if (! $school) {
            $this->error('Sekolah tidak ditemukan.');

            return self::FAILURE;
        }

        $name = $this->argument('backup');
        if (! $name) {
            $this->table(['Backup', 'Ukuran'], array_map(fn ($b) => [$b['name'], number_format($b['size'] / 1024, 0, ',', '.').' KB'], $backup->list($school)));

            return self::SUCCESS;
        }

        if (! $this->option('force') && ! $this->confirm("Semua data {$school->name} saat ini akan DIGANTI dengan isi $name. Lanjutkan?")) {
            return self::FAILURE;
        }

        // Backup pengaman sebelum dipulihkan, supaya langkah ini bisa dibatalkan.
        $safety = $backup->create($school, 'Sebelum pemulihan');
        $r = $backup->restore($school, $name, 'CLI');
        $this->info('Dipulihkan: '.array_sum($r['counts']).' baris, '.$r['files'].' berkas (backup '.$r['created_at'].').');
        $this->line('Backup pengaman sebelum pemulihan: '.$safety['name']);

        return self::SUCCESS;
    }
}
