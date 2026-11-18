<?php

namespace App\Jobs;

use App\Models\PushSubscription;
use App\Models\School;
use App\Services\Notify\WebPush;
use App\Tenancy\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Bangunkan HP penerima (push tanpa isi).
 * Bawaan (PUSH_DELIVERY=after_response): dikirim tepat setelah respons ke pengirim selesai, tanpa menunggu cron antrean.
 * PUSH_DELIVERY=queue: lewat antrean (butuh worker / QUEUE_VIA_SCHEDULER).
 */
class SendWebPush implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    /** @param  list<int>  $subscriptionIds */
    public function __construct(public int $schoolId, public array $subscriptionIds) {}

    public function handle(TenantContext $tenant, WebPush $push): void
    {
        $school = School::find($this->schoolId);
        if (! $school || ! WebPush::configured()) {
            return;
        }
        $tenant->run($school, function () use ($push) {
            try {
                $push->sendMany(PushSubscription::query()->whereIn('id', $this->subscriptionIds)->get());
            } catch (\Throwable $e) {
                report($e);
            }
        });
    }
}
