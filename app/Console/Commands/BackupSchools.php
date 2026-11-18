<?php

namespace App\Console\Commands;

use App\Models\School;
use App\Services\Platform\SchoolBackup;
use Illuminate\Console\Command;
use Throwable;

class BackupSchools extends Command
{
    protected $signature = 'school:backup {--school= : Subdomain sekolah} {--all : Semua sekolah (dipakai jadwal harian)}';

    protected $description = 'Backup data & berkas per sekolah (ZIP terenkripsi, dirotasi)';

    public function handle(SchoolBackup $backup): int
    {
        $schools = $this->option('all') ? School::query()->orderBy('id')->get()
            : School::query()->where('subdomain', (string) $this->option('school'))->get();

        if ($schools->isEmpty()) {
            $this->error('Sekolah tidak ditemukan. Pakai --school=subdomain atau --all.');

            return self::FAILURE;
        }
        if (! config('tenancy.backup_password')) {
            $this->warn('BACKUP_PASSWORD belum diisi: backup TIDAK terenkripsi. Isi di .env untuk produksi.');
        }

        $failed = 0;
        foreach ($schools as $s) {
            try {
                $r = $backup->create($s, 'Jadwal backup');
                $this->line(sprintf('%-24s %s  %s KB', $s->subdomain, $r['name'], number_format($r['size'] / 1024, 0, ',', '.')));
            } catch (Throwable $e) {
                report($e);
                $failed++;
                $this->error("{$s->subdomain}: ".$e->getMessage());
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
