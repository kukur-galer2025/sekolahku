<?php

namespace App\Services\Billing;

use App\Enums\SchoolStatus;
use App\Models\Invoice;
use App\Models\School;
use App\Models\Student;
use App\Tenancy\SchoolScope;

/**
 * Dijalankan harian (billing:run):
 *  1. Pengingat sebelum trial/langganan berakhir (H-14, H-7, H-3, H-1) — masing-masing sekali.
 *  2. Invoice perpanjangan otomatis H-14 untuk sekolah berlangganan.
 *  3. Setelah berakhir: email masa tenggang; lewat masa tenggang → blokir (suspension_type = billing).
 */
class BillingCycle
{
    public function __construct(private BillingService $billing) {}

    /** @return array{reminders:int,invoices:int,expired:int,suspended:int} */
    public function run(?School $only = null): array
    {
        $stats = ['reminders' => 0, 'invoices' => 0, 'expired' => 0, 'suspended' => 0];
        $q = School::query()->whereIn('status', [SchoolStatus::Trial->value, SchoolStatus::Active->value]);
        if ($only) {
            $q->whereKey($only->id);
        }
        $q->orderBy('id')->each(function (School $school) use (&$stats) {
            $this->process($school, $stats);
        });

        return $stats;
    }

    private function process(School $school, array &$stats): void
    {
        $exp = $school->expiresAt();
        if (! $exp) {
            return; // tanpa batas waktu (mis. sekolah internal/gratis)
        }
        $trial = $school->status === SchoolStatus::Trial;
        $notices = $school->billing_notices ?? [];
        $tag = $exp->format('Ymd'); // periode baru → pengingat mulai dari awal lagi
        $sent = fn (string $k) => in_array($k, $notices, true);
        $mark = function (string $k) use (&$notices) { $notices[] = $k; };

        if ($exp->isFuture()) {
            $daysLeft = max(1, (int) ceil(now()->floatDiffInDays($exp)));
            $invoice = null;

            if (! $trial && $daysLeft <= (int) config('billing.renewal_days_before')) {
                $invoice = BillingService::invoices()->where('school_id', $school->id)->where('status', 'unpaid')->whereIn('type', ['new', 'renewal'])->latest('id')->first();
                if (! $invoice && ! $sent("renewal:$tag")) {
                    $invoice = $this->renewalInvoice($school, $exp);
                    $this->billing->notify($school, 'invoice', ['invoice' => $invoice]);
                    $stats['invoices']++;
                }
                $mark("renewal:$tag");
            }

            // Tiap awal bulan: siswa aktif > yang sudah dibayar → tagihan tambahan prorata sisa masa aktif.
            // Dilewati menjelang perpanjangan (invoice perpanjangan sudah memakai jumlah siswa aktif terbaru).
            $month = 'addon:'.now()->format('Ym');
            if (! $trial && $school->status === SchoolStatus::Active && $daysLeft > (int) config('billing.renewal_days_before') && ! $sent($month)) {
                if ($this->addonInvoice($school)) {
                    $stats['invoices']++;
                }
                $mark($month);
            }

            $thresholds = collect(config('billing.reminder_days'))->map(fn ($d) => (int) $d)->sort()->values();
            $hit = $thresholds->first(fn ($d) => $daysLeft <= $d);
            if ($hit !== null && ! $sent("remind:$tag:$hit")) {
                $this->billing->notify($school, 'reminder', ['days' => $daysLeft, 'ends' => $exp, 'trial' => $trial, 'invoice' => $invoice]);
                $thresholds->filter(fn ($d) => $d >= $hit)->each(fn ($d) => $mark("remind:$tag:$d"));
                $stats['reminders']++;
            }
        } elseif (now()->lt($school->graceEndsAt())) {
            if (! $sent("expired:$tag")) {
                $this->billing->notify($school, 'expired', ['trial' => $trial, 'grace' => $school->graceEndsAt()]);
                $mark("expired:$tag");
                $stats['expired']++;
            }
        } else {
            $school->forceFill(['billing_notices' => array_values(array_unique($notices))]);
            $this->billing->suspendForBilling($school);
            $stats['suspended']++;

            return;
        }

        $notices = array_values(array_unique($notices));
        if ($notices !== ($school->billing_notices ?? [])) {
            $school->forceFill(['billing_notices' => $notices])->saveQuietly();
        }
    }

    /** Tagihan tambahan otomatis untuk siswa baru di tengah periode (prorata). */
    private function addonInvoice(School $school): ?Invoice
    {
        $active = Student::query()->withoutGlobalScope(SchoolScope::class)->where('school_id', $school->id)->where('is_active', true)->count();
        if ($active <= (int) $school->student_quota) {
            return null;
        }
        $pending = BillingService::invoices()->where('school_id', $school->id)->where('status', 'unpaid')->where('type', 'upgrade')->exists();
        if ($pending) {
            return null; // lunasi tagihan tambahan sebelumnya dulu; sisanya ikut bulan depan
        }
        try {
            $invoice = $this->billing->createUpgradeInvoice($school, $active);
        } catch (\Illuminate\Validation\ValidationException) {
            return null;
        }
        $this->billing->notify($school, 'invoice', ['invoice' => $invoice]);

        return $invoice;
    }

    /** Perpanjangan dengan periode terakhir yang dibayar (bawaan 12 bulan) sebanyak siswa aktif saat ini. */
    private function renewalInvoice(School $school, $exp): Invoice
    {
        $lastMonths = BillingService::invoices()->where('school_id', $school->id)->where('status', 'paid')
            ->whereIn('type', ['new', 'renewal'])->latest('paid_at')->value('months');
        $months = array_key_exists((int) $lastMonths, config('billing.periods')) ? (int) $lastMonths : 12;
        $active = Student::query()->withoutGlobalScope(SchoolScope::class)->where('school_id', $school->id)->where('is_active', true)->count();

        // Per siswa AKTIF persis: bila siswa berkurang (mis. lulusan), tagihan ikut turun.
        return $this->billing->createSubscriptionInvoice($school, $active, $months, null, 'renewal', $exp);
    }
}
