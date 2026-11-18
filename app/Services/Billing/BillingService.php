<?php

namespace App\Services\Billing;

use App\Enums\Role;
use App\Enums\SchoolStatus;
use App\Mail\BillingMail;
use App\Models\Invoice;
use App\Models\InvoicePayment;
use App\Models\PaymentEvent;
use App\Models\School;
use App\Models\User;
use App\Services\Billing\Gateways\MidtransGateway;
use App\Services\Billing\Gateways\PaymentGateway;
use App\Services\Billing\Gateways\WebhookRejected;
use App\Services\Billing\Gateways\XenditGateway;
use App\Services\Platform\AuditLogger;
use App\Tenancy\SchoolScope;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Siklus tagihan:
 *   buat invoice → (bayar online via gateway | transfer + unggah bukti) → lunas → masa aktif & kuota diperbarui otomatis.
 * Pelunasan bersifat idempoten: notifikasi ganda atau konfirmasi manual berulang tidak memperpanjang dua kali.
 */
class BillingService
{
    public function __construct(private BillingCalculator $calc, private AuditLogger $audit) {}

    /** Query invoice tanpa filter tenant (panel, webhook, command). */
    public static function invoices()
    {
        return Invoice::query()->withoutGlobalScope(SchoolScope::class);
    }

    public function gateway(?string $name = null): ?PaymentGateway
    {
        return match ($name ?? config('billing.gateway')) {
            'midtrans' => app(MidtransGateway::class),
            'xendit' => app(XenditGateway::class),
            default => null,
        };
    }

    public function onlineEnabled(): bool
    {
        return $this->gateway() !== null;
    }

    // ── Membuat invoice ───────────────────────────────────────────────

    /** Paket baru / perpanjangan. Invoice belum lunas yang setara dipakai ulang (tidak menumpuk). */
    public function createSubscriptionInvoice(School $school, int $students, int $months, ?User $by = null, string $type = 'new', ?\DateTimeInterface $dueAt = null): Invoice
    {
        $q = $this->calc->quote($students, $months, $school->plan);
        $existing = self::invoices()->where('school_id', $school->id)->where('status', 'unpaid')->whereIn('type', ['new', 'renewal'])
            ->where('student_count', $q['student_count'])->where('months', $months)->where('total', $q['total'])->latest('id')->first();
        if ($existing) {
            return $existing;
        }

        $label = $type === 'renewal' ? 'Perpanjangan' : 'Langganan';

        return $this->store($school, $type, $q, "$label MyPresence ".\App\Support\Plan::label($school->plan)." {$months} bulan · {$q['student_count']} siswa", $by, $dueAt);
    }

    public function createUpgradeInvoice(School $school, int $newQuota, ?User $by = null): Invoice
    {
        $q = $this->calc->upgradeQuote($school, $newQuota);
        self::invoices()->where('school_id', $school->id)->where('status', 'unpaid')->where('type', 'upgrade')
            ->get()->each(fn (Invoice $i) => $this->void($i, 'Diganti tagihan tambah kuota yang baru.', 'Sistem'));

        return $this->store($school, 'upgrade', $q, "Tambah kuota menjadi {$q['student_count']} siswa", $by);
    }

    /** Tagihan khusus dari panel (setup, pelatihan, penyesuaian harga, dll.). */
    public function createCustomInvoice(School $school, string $description, int $amount, ?User $by = null, int $dueDays = 7): Invoice
    {
        if ($amount < 1000) {
            throw ValidationException::withMessages(['amount' => 'Nominal minimal Rp1.000.']);
        }
        $rate = (float) config('billing.tax_percent', 0);
        $tax = (int) round($amount * $rate / 100);
        $lines = [['label' => $description, 'amount' => $amount]];
        if ($tax) {
            $lines[] = ['label' => config('billing.tax_label').' '.$rate.'%', 'amount' => $tax];
        }
        $q = ['student_count' => (int) $school->student_quota, 'months' => 0, 'unit_price' => 0, 'gross' => $amount, 'discount' => 0, 'tax' => $tax, 'total' => $amount + $tax, 'lines' => $lines];

        return $this->store($school, 'custom', $q, $description, $by, now()->addDays($dueDays));
    }

    /** Kode 1–999 yang belum dipakai tagihan lain yang masih terbuka. */
    private function freeUniqueCode(): int
    {
        $used = self::invoices()->where('status', 'unpaid')->whereNotNull('unique_code')->pluck('unique_code')->all();
        $free = array_values(array_diff(range(1, 999), $used));

        return $free ? $free[random_int(0, count($free) - 1)] : random_int(1, 999);
    }

    private function store(School $school, string $type, array $q, string $description, ?User $by, ?\DateTimeInterface $dueAt = null): Invoice
    {
        if ($q['total'] <= 0) {
            throw ValidationException::withMessages(['students' => 'Total tagihan Rp0. Periksa harga paket di konfigurasi.']);
        }

        for ($try = 0; ; $try++) {
            try {
                $invoice = DB::transaction(function () use ($school, $type, $q, $description, $by, $dueAt) {
                    $prefix = 'INV/'.now()->format('Y/m').'/';
                    $last = self::invoices()->where('number', 'like', $prefix.'%')->lockForUpdate()->max('number');
                    $seq = $last ? ((int) substr($last, strlen($prefix))) + 1 : 1;
                    // Kode unik: total jadi "ganjil" & berbeda dari tagihan lain yang masih terbuka → mudah dikenali di mutasi bank.
                    $code = config('billing.unique_code', true) ? $this->freeUniqueCode() : null;
                    if ($code) {
                        $q['lines'][] = ['label' => 'Kode unik pembayaran', 'amount' => $code];
                        $q['total'] += $code;
                    }

                    return Invoice::create([
                        'school_id' => $school->id, 'number' => $prefix.str_pad((string) $seq, 5, '0', STR_PAD_LEFT),
                        'type' => $type, 'status' => 'unpaid', 'description' => $description,
                        'student_count' => $q['student_count'], 'months' => $q['months'], 'unit_price' => $q['unit_price'],
                        'gross' => $q['gross'], 'discount' => $q['discount'], 'tax' => $q['tax'], 'unique_code' => $code, 'total' => $q['total'], 'lines' => $q['lines'],
                        'due_at' => $dueAt ?? now()->addDays((int) config('billing.due_days')),
                        'created_by' => $by?->id,
                    ]);
                });
                break;
            } catch (QueryException $e) {
                if ($try >= 4 || ! str_contains(strtolower($e->getMessage()), 'unique')) {
                    throw $e;
                }
            }
        }

        $this->audit->log('billing.invoice_created', "Tagihan {$invoice->number} dibuat: {$description} · ".Invoice::rupiah($invoice->total), $school, $by,
            ['invoice' => $invoice->number, 'total' => $invoice->total], $by ? null : 'Sistem');

        return $invoice;
    }

    // ── Pembayaran online ─────────────────────────────────────────────

    /** Sesi pembayaran gateway. Tautan yang masih segar dipakai ulang. @return string URL halaman bayar */
    public function checkout(Invoice $invoice, School $school, string $finishUrl): string
    {
        if (! $invoice->isPayable()) {
            throw ValidationException::withMessages(['invoice' => 'Tagihan ini tidak bisa dibayar (status: '.$invoice->status.').']);
        }
        if ($invoice->isPartial()) {
            // Gateway menagih nominal penuh; setelah DP, sisa dilunasi lewat transfer & dikonfirmasi Parama Tech.
            throw ValidationException::withMessages(['invoice' => 'Tagihan ini sudah dibayar sebagian (DP). Lunasi sisa '.Invoice::rupiah($invoice->remaining()).' lewat transfer bank.']);
        }
        $gateway = $this->gateway() ?? throw ValidationException::withMessages(['invoice' => 'Pembayaran online belum diaktifkan. Gunakan transfer bank.']);

        if ($invoice->payment_url && $invoice->gateway === $gateway->name() && $invoice->updated_at->gt(now()->subHours(12))) {
            return $invoice->payment_url;
        }

        // order_id unik per percobaan (gateway menolak order_id yang sama dipakai dua kali).
        $attempt = $invoice->checkout_attempts + 1;
        $orderId = str_replace('/', '-', $invoice->number).'-'.$attempt;
        $r = $gateway->checkout($invoice, $school, $orderId, $finishUrl);
        $invoice->forceFill([
            'gateway' => $gateway->name(), 'gateway_ref' => $r['ref'], 'gateway_invoice_id' => $r['gateway_invoice_id'],
            'payment_url' => $r['url'], 'checkout_attempts' => $attempt,
        ])->save();

        return $r['url'];
    }

    /**
     * Notifikasi dari gateway. Selalu dicatat di payment_events.
     * @return array{0:int,1:string} kode HTTP & pesan
     */
    public function handleWebhook(string $gatewayName, Request $request): array
    {
        $gateway = $this->gateway($gatewayName) ?? throw new RuntimeException('Gateway tidak dikenal.');
        $payload = $request->json()->all();

        try {
            $r = $gateway->webhook($request);
        } catch (WebhookRejected $e) {
            $this->event($gatewayName, null, 'rejected:'.Str::uuid(), 'invalid', false, 'rejected', $e->getMessage(), $payload, $request->ip());
            Log::warning('Webhook pembayaran ditolak', ['gateway' => $gatewayName, 'ip' => $request->ip(), 'reason' => $e->getMessage()]);

            return [403, 'rejected'];
        }

        $invoice = self::invoices()->where('gateway', $gatewayName)->where('gateway_ref', $r['ref'])->first();
        $key = $gatewayName.':'.$r['ref'].':'.$r['status'].':'.($r['payment_ref'] ?? '-');

        return $this->apply($invoice, $r, $gatewayName, $key, $payload, $request->ip());
    }

    /** Tombol "Cek status pembayaran" (jika webhook terlambat). */
    public function refreshStatus(Invoice $invoice): string
    {
        if (! $invoice->isPayable() || ! $invoice->gateway_ref || ! ($gateway = $this->gateway($invoice->gateway))) {
            return $invoice->status;
        }
        $r = $gateway->status($invoice);
        $this->apply($invoice, $r, $gateway->name(), $gateway->name().':'.$r['ref'].':'.$r['status'].':'.($r['payment_ref'] ?? '-'), ['source' => 'status_check'] + $r, request()?->ip());

        return $invoice->fresh()->status;
    }

    private function apply(?Invoice $invoice, array $r, string $gateway, string $key, array $payload, ?string $ip): array
    {
        if (! $invoice) {
            $this->event($gateway, null, $key, $r['status'], true, 'ignored', 'Invoice dengan ref '.$r['ref'].' tidak ditemukan.', $payload, $ip);

            return [200, 'ignored'];
        }
        if (PaymentEvent::query()->withoutGlobalScope(SchoolScope::class)->where('event_key', $key)->exists()) {
            return [200, 'duplicate'];
        }

        if (! $r['paid']) {
            $msg = 'Status '.$r['raw_status'];
            if ($r['status'] === 'failed' && $invoice->isPayable()) {
                // Sesi kedaluwarsa/dibatalkan: tautan baru dibuat saat admin menekan "Bayar" lagi.
                $invoice->forceFill(['payment_url' => null])->save();
            }
            $this->event($gateway, $invoice, $key, $r['status'], true, 'ignored', $msg, $payload, $ip);

            return [200, 'ok'];
        }

        if ($r['amount'] !== (int) $invoice->total) {
            $this->event($gateway, $invoice, $key, 'paid', true, 'rejected', "Nominal dibayar {$r['amount']} ≠ tagihan {$invoice->total}", $payload, $ip);
            Log::error('Nominal pembayaran tidak cocok', ['invoice' => $invoice->number, 'paid' => $r['amount'], 'total' => $invoice->total]);

            return [200, 'amount_mismatch'];
        }

        $applied = $this->markPaid($invoice, $r['method'], $r['payment_ref'], 'Gateway '.ucfirst($gateway));
        $this->event($gateway, $invoice, $key, 'paid', true, $applied ? 'applied' : 'duplicate', $applied ? 'Lunas' : 'Sudah lunas sebelumnya', $payload, $ip);

        return [200, 'ok'];
    }

    private function event(string $gateway, ?Invoice $invoice, string $key, string $status, bool $verified, string $result, string $message, array $payload, ?string $ip): void
    {
        try {
            PaymentEvent::create([
                'school_id' => $invoice?->school_id, 'invoice_id' => $invoice?->id, 'gateway' => $gateway, 'event_key' => mb_substr($key, 0, 191),
                'status' => $status, 'verified' => $verified, 'result' => $result, 'message' => mb_substr($message, 0, 255),
                'payload' => $payload, 'ip' => $ip,
            ]);
        } catch (QueryException) {
            // event_key sama (notifikasi ganda yang datang bersamaan) — cukup sekali.
        }
    }

    // ── Pelunasan ─────────────────────────────────────────────────────

    /** @return bool true bila baru dilunasi sekarang, false bila sudah lunas/batal sebelumnya. */
    public function markPaid(Invoice $invoice, string $method, ?string $ref, string $by, ?User $actor = null): bool
    {
        $result = DB::transaction(function () use ($invoice, $method, $ref) {
            $inv = self::invoices()->lockForUpdate()->find($invoice->id);
            if (! $inv || ! $inv->isPayable()) {
                return null;
            }
            $school = School::query()->lockForUpdate()->find($inv->school_id);
            // Layanan yang sudah diaktifkan saat DP tidak diperpanjang dua kali.
            [$start, $end] = $inv->activated_at ? [$inv->period_start, $inv->period_end] : $this->applySubscription($school, $inv);

            // Sudah ada DP/cicilan → sisa dicatat sebagai pelunasan agar riwayat pembayaran lengkap.
            $rest = (int) $inv->total - (int) $inv->paid_amount;
            if ($rest > 0 && (int) $inv->paid_amount > 0) {
                InvoicePayment::create(['invoice_id' => $inv->id, 'kind' => 'pelunasan', 'amount' => $rest, 'paid_on' => now()->toDateString(),
                    'method' => str_contains($method, ':') ? 'gateway' : (array_key_exists($method, InvoicePayment::METHODS) ? $method : 'lainnya'),
                    'ref' => $ref ? mb_substr($ref, 0, 120) : null]);
            }

            $inv->forceFill([
                'status' => 'paid', 'paid_at' => now(), 'payment_method' => $method, 'payment_ref' => $ref,
                'period_start' => $start, 'period_end' => $end, 'payment_url' => null, 'paid_amount' => $inv->total,
                'activated_at' => $inv->activated_at ?? now(),
            ])->save();

            // Tagihan paket lain yang belum dibayar menjadi tidak relevan.
            if (in_array($inv->type, ['new', 'renewal'], true)) {
                self::invoices()->where('school_id', $school->id)->where('status', 'unpaid')->whereIn('type', ['new', 'renewal'])
                    ->where('id', '!=', $inv->id)->update(['status' => 'void', 'notes' => 'Dibatalkan otomatis: paket lain sudah dibayar ('.$inv->number.').', 'payment_url' => null, 'updated_at' => now()]);
            }

            return [$inv, $school];
        });

        if (! $result) {
            return false;
        }
        [$inv, $school] = $result;
        $invoice->setRawAttributes($inv->getAttributes(), true);

        $this->audit->log('billing.paid', "Tagihan {$inv->number} lunas (".Invoice::rupiah($inv->total).", $method) · dikonfirmasi oleh $by"
            .($inv->period_end ? ' · aktif s.d. '.$inv->period_end->format('d/m/Y') : ''), $school, $actor, ['invoice' => $inv->number, 'ref' => $ref], $actor ? null : $by);
        $this->notify($school, 'receipt', ['invoice' => $inv]);

        return true;
    }

    /**
     * Catat DP / pembayaran tambahan dari panel. Bila sisa menjadi 0, tagihan otomatis dilunasi.
     * $activate: aktifkan masa aktif & kuota sekarang (saat DP) — tidak diterapkan ulang ketika lunas.
     * @return array{payment: InvoicePayment, paid: bool, activated: bool}
     */
    public function recordPayment(Invoice $invoice, array $d, string $by, ?User $actor = null): array
    {
        $amount = (int) $d['amount'];
        $r = DB::transaction(function () use ($invoice, $d, $amount, $actor) {
            $inv = self::invoices()->lockForUpdate()->find($invoice->id);
            if (! $inv || ! $inv->isPayable()) {
                throw ValidationException::withMessages(['amount' => 'Tagihan ini sudah tidak menunggu pembayaran.']);
            }
            $rest = (int) $inv->total - (int) $inv->paid_amount;
            if ($amount < 1 || $amount > $rest) {
                throw ValidationException::withMessages(['amount' => 'Nominal harus antara Rp1 dan sisa tagihan '.Invoice::rupiah($rest).'.']);
            }
            $kind = $amount === $rest ? 'pelunasan' : ($d['kind'] ?? ((int) $inv->paid_amount === 0 ? 'dp' : 'tambahan'));
            $pay = InvoicePayment::create(['invoice_id' => $inv->id, 'kind' => $kind, 'amount' => $amount, 'paid_on' => $d['paid_on'],
                'method' => $d['method'], 'ref' => $d['ref'] ?? null, 'note' => $d['note'] ?? null, 'created_by' => $actor?->id]);

            $fill = ['paid_amount' => (int) $inv->paid_amount + $amount];
            $activated = false;
            if (! empty($d['activate']) && ! $inv->activated_at && $amount < $rest) {
                $school = School::query()->lockForUpdate()->find($inv->school_id);
                [$start, $end] = $this->applySubscription($school, $inv);
                $fill += ['activated_at' => now(), 'period_start' => $start, 'period_end' => $end];
                $activated = true;
            }
            $inv->forceFill($fill)->save();

            return [$inv, $pay, $amount === $rest, $activated];
        });
        [$inv, $pay, $full, $activated] = $r;
        $school = School::find($inv->school_id);
        $this->audit->log('billing.payment', "Pembayaran {$pay->kindLabel()} ".Invoice::rupiah($amount)." untuk {$inv->number} dicatat oleh $by"
            .' · sisa '.Invoice::rupiah(max(0, $inv->total - $inv->paid_amount)).($activated ? ' · layanan diaktifkan' : ''), $school, $actor,
            ['invoice' => $inv->number, 'amount' => $amount, 'kind' => $pay->kind]);

        if ($full) {
            // paid_amount sudah = total, jadi markPaid tidak membuat baris pelunasan ganda.
            $this->markPaid($inv, $pay->method, $pay->ref ?: $pay->kindLabel(), $by, $actor);
        }
        $invoice->setRawAttributes($inv->fresh()->getAttributes(), true);

        return ['payment' => $pay, 'paid' => $full, 'activated' => $activated];
    }

    /** Hapus pembayaran yang salah catat (hanya selama tagihan belum lunas). Aktivasi layanan tidak dibatalkan. */
    public function deletePayment(Invoice $invoice, InvoicePayment $payment, string $by, ?User $actor = null): void
    {
        abort_unless($payment->invoice_id === $invoice->id, 404);
        if (! $invoice->isPayable()) {
            throw ValidationException::withMessages(['payment' => 'Pembayaran pada tagihan yang sudah lunas/batal tidak bisa dihapus.']);
        }
        DB::transaction(function () use ($invoice, $payment) {
            $payment->delete();
            $invoice->forceFill(['paid_amount' => (int) InvoicePayment::where('invoice_id', $invoice->id)->sum('amount')])->save();
        });
        $this->audit->log('billing.payment_deleted', "Pembayaran ".Invoice::rupiah($payment->amount)." ({$payment->kindLabel()}) pada {$invoice->number} dihapus oleh $by",
            School::find($invoice->school_id), $actor, ['invoice' => $invoice->number, 'amount' => $payment->amount]);
    }

    /** @return array{0:?\Illuminate\Support\Carbon,1:?\Illuminate\Support\Carbon} periode yang dibayar */
    private function applySubscription(School $school, Invoice $inv): array
    {
        $fill = [];
        $start = $end = null;

        if (in_array($inv->type, ['new', 'renewal'], true) && $inv->months > 0) {
            // Perpanjangan ditumpuk dari akhir masa aktif yang masih berjalan; jika sudah lewat, mulai hari ini.
            // Dihitung dalam zona waktu sekolah agar "aktif s.d. tanggal X" sama di invoice dan portal.
            $tz = $school->timezone ?: config('tenancy.default_timezone');
            $start = ($school->status === SchoolStatus::Active && $school->active_until?->isFuture() ? $school->active_until->copy() : now())->timezone($tz);
            $end = $start->copy()->addMonthsNoOverflow($inv->months)->endOfDay();
            $fill = ['status' => SchoolStatus::Active, 'active_until' => $end->copy()->timezone(config('app.timezone')), 'student_quota' => $inv->student_count, 'billing_notices' => null];
        } elseif ($inv->type === 'upgrade') {
            $start = now()->timezone($school->timezone ?: config('tenancy.default_timezone'));
            $end = $school->active_until?->copy()->timezone($start->getTimezone());
            $fill = ['student_quota' => max((int) $school->student_quota, $inv->student_count)];
        }

        // Blokir karena tagihan dibuka otomatis; penangguhan manual oleh Parama Tech tetap berlaku.
        if ($school->isBillingLocked() && $fill) {
            $fill += ['suspended_at' => null, 'suspended_reason' => null, 'suspension_type' => null];
            $fill['status'] = SchoolStatus::Active;
        } elseif ($school->isSuspended()) {
            unset($fill['status']);
        }

        if ($fill) {
            $school->forceFill($fill)->save();
        }

        return [$start, $end];
    }

    public function void(Invoice $invoice, string $reason, string $by, ?User $actor = null): void
    {
        if (! $invoice->isPayable()) {
            throw ValidationException::withMessages(['invoice' => 'Hanya tagihan belum dibayar yang bisa dibatalkan.']);
        }
        $invoice->forceFill(['status' => 'void', 'notes' => mb_substr($reason, 0, 255), 'payment_url' => null])->save();
        $this->audit->log('billing.void', "Tagihan {$invoice->number} dibatalkan oleh $by: $reason", School::find($invoice->school_id), $actor, ['invoice' => $invoice->number], $actor ? null : $by);
    }

    // ── Masa aktif & blokir ───────────────────────────────────────────

    public function suspendForBilling(School $school): void
    {
        $school->forceFill([
            'status' => SchoolStatus::Suspended, 'suspended_at' => now(), 'suspension_type' => 'billing',
            'suspended_reason' => ($school->status === SchoolStatus::Trial ? 'Masa trial' : 'Langganan').' berakhir '.$school->expiresAt()?->format('d/m/Y').' dan belum diperpanjang.',
        ])->save();
        $this->audit->log('billing.suspended', 'Layanan diblokir otomatis: '.$school->suspended_reason, $school, null, [], 'Sistem');
        $this->notify($school, 'suspended');
    }

    // ── Email ─────────────────────────────────────────────────────────

    public function notify(School $school, string $kind, array $data = []): int
    {
        $admins = User::query()->withoutGlobalScope(SchoolScope::class)->where('school_id', $school->id)
            ->where('role', Role::Admin->value)->where('is_active', true)->whereNotNull('email')->get();
        foreach ($admins as $admin) {
            try {
                Mail::to($admin->email)->send(new BillingMail($kind, $school, $admin, $data));
            } catch (\Throwable $e) {
                Log::error('Email tagihan gagal', ['school' => $school->id, 'kind' => $kind, 'error' => $e->getMessage()]);
            }
        }

        return $admins->count();
    }
}
