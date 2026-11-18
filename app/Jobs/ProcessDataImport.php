<?php

namespace App\Jobs;

use App\Models\DataImport;
use App\Models\School;
use App\Services\Import\ImportManager;
use App\Tenancy\TenantContext;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Pola job multi-tenant: job hanya membawa ID (school_id ikut disimpan), lalu
 * seluruh pekerjaan dijalankan di dalam TenantContext::run() sekolah itu.
 * Tanpa itu, global scope akan menyembunyikan semua data (gagal tertutup).
 */
class ProcessDataImport implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 900;

    public function __construct(public int $importId, public int $schoolId) {}

    public function handle(TenantContext $tenant, ImportManager $manager): void
    {
        $school = School::find($this->schoolId);
        if (! $school) {
            return;
        }

        $tenant->run($school, function () use ($manager) {
            $import = DataImport::find($this->importId);
            if (! $import || ! in_array($import->status, ['queued', 'processing'], true)) {
                return;
            }

            try {
                $manager->process($import);
            } catch (Throwable $e) {
                report($e);
                $import->forceFill(['status' => 'failed', 'error' => 'Impor gagal: '.$e->getMessage(), 'finished_at' => now()])->save();
            }

            // Admin tidak perlu menunggu di halaman: kabari lewat lonceng/push saat impor selesai.
            $import->refresh();
            $labels = ['teachers' => 'data guru', 'students' => 'data siswa', 'lesson_periods' => 'jam KBM', 'schedules' => 'jadwal mengajar', 'class_schedules' => 'jadwal kelas'];
            rescue(fn () => app(\App\Services\Notify\Notifier::class)->send([\App\Models\User::query()->find($import->user_id)], 'import',
                ($import->status === 'done' ? 'Impor selesai: ' : 'Impor gagal: ').($labels[$import->type] ?? $import->type),
                $import->status === 'done' ? 'Buka untuk melihat hasil dan mencetak kartu aktivasi.' : 'Buka untuk melihat penyebabnya.',
                '/impor/'.$import->id));
        });
    }
}
