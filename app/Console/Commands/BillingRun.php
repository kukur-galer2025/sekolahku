<?php

namespace App\Console\Commands;

use App\Models\School;
use App\Services\Billing\BillingCycle;
use Illuminate\Console\Command;

class BillingRun extends Command
{
    protected $signature = 'billing:run {--school= : subdomain (opsional, untuk satu sekolah)}';

    protected $description = 'Pengingat langganan, invoice perpanjangan, masa tenggang & blokir otomatis';

    public function handle(BillingCycle $cycle): int
    {
        $only = null;
        if ($s = $this->option('school')) {
            $only = School::where('subdomain', $s)->first();
            if (! $only) {
                $this->error("Sekolah $s tidak ditemukan.");

                return self::FAILURE;
            }
        }
        $r = $cycle->run($only);
        $this->info("Pengingat: {$r['reminders']} · Invoice perpanjangan: {$r['invoices']} · Masa tenggang: {$r['expired']} · Diblokir: {$r['suspended']}");

        return self::SUCCESS;
    }
}
