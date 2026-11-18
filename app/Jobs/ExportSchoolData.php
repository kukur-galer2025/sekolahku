<?php

namespace App\Jobs;

use App\Models\DataExport;
use App\Models\School;
use App\Services\Platform\SchoolExport;
use App\Tenancy\TenantContext;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class ExportSchoolData implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 900;

    public function __construct(public int $exportId, public int $schoolId) {}

    public function handle(TenantContext $tenant, SchoolExport $exporter): void
    {
        $school = School::find($this->schoolId);
        if (! $school) {
            return;
        }

        $tenant->run($school, function () use ($exporter) {
            $export = DataExport::find($this->exportId);
            if (! $export || $export->status !== 'queued') {
                return;
            }
            try {
                $exporter->run($export);
            } catch (Throwable $e) {
                report($e);
                $export->forceFill(['status' => 'failed', 'error' => $e->getMessage()])->save();
            }
        });
    }
}
