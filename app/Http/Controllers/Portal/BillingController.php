<?php

namespace App\Http\Controllers\Portal;

use App\Enums\SchoolStatus;
use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Models\Student;
use App\Services\Billing\BillingCalculator;
use App\Services\Billing\BillingService;
use App\Services\ImageSanitizer;
use App\Services\Platform\AuditLogger;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantFiles;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/** Halaman Langganan admin sekolah: paket, tagihan, pembayaran, bukti transfer, invoice & kwitansi. */
class BillingController extends Controller
{
    public function __construct(private BillingService $billing, private BillingCalculator $calc, private TenantContext $tenant) {}

    public function index(): View
    {
        $school = $this->tenant->get();
        $active = Student::query()->where('is_active', true)->count();
        $invoices = Invoice::query()->latest('id')->limit(50)->get();
        $exp = $school->expiresAt();
        $grace = $school->graceEndsAt();

        $state = match (true) {
            $school->isBillingLocked() => ['Diblokir — menunggu pembayaran', 'er'],
            $exp && $exp->isPast() => ['Masa tenggang s.d. '.$grace->timezone($school->timezone)->format('d/m/Y'), 'wr'],
            $school->status === SchoolStatus::Trial => ['Trial', 'wr'],
            default => ['Aktif', 'ok'],
        };
        $suggest = $this->calc->normalizeStudents($active); // per siswa aktif persis
        $canUpgrade = $school->status === SchoolStatus::Active && $school->active_until?->isFuture();

        return view('portal.billing.index', [
            'school' => $school, 'activeStudents' => $active, 'invoices' => $invoices, 'state' => $state,
            'expires' => $exp, 'daysLeft' => $exp && $exp->isFuture() ? (int) ceil(now()->floatDiffInDays($exp)) : 0,
            'suggest' => $suggest, 'periods' => $this->calc->periods(), 'unit' => $this->calc->unitPrice($school->plan),
            'quotes' => collect($this->calc->periods())->mapWithKeys(fn ($p, $m) => [$m => $this->calc->quote($suggest, $m, $school->plan)]),
            'canUpgrade' => $canUpgrade, 'minStudents' => $this->calc->minStudents(),
            'upgradeDays' => $canUpgrade ? max(1, (int) ceil(now()->floatDiffInDays($school->active_until))) : 0,
            'unpaid' => $invoices->where('status', 'unpaid'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $school = $this->tenant->get();
        $d = $request->validate([
            'type' => ['required', 'in:new,upgrade'],
            'students' => ['required', 'integer', 'min:1', 'max:100000'],
            'months' => ['required_if:type,new', 'nullable', 'integer'],
        ], [], ['students' => 'jumlah siswa', 'months' => 'periode']);

        $active = Student::query()->where('is_active', true)->count();
        if ($d['type'] === 'new') {
            $d['students'] = $this->calc->normalizeStudents($active); // langganan selalu sebanyak siswa aktif saat ini
        }
        if ($d['students'] < $active) {
            throw ValidationException::withMessages(['students' => "Kuota tidak boleh kurang dari jumlah siswa aktif sekarang ($active)."]);
        }
        // Batas siswa per paket (Starter 300, Pro 1.000, All Service tanpa batas).
        $max = \App\Support\Plan::limit($school->plan, 'max_students');
        if ($max !== null && $d['students'] > $max) {
            throw ValidationException::withMessages(['students' => 'Paket '.\App\Support\Plan::label($school->plan).' maksimal '.number_format($max, 0, ',', '.').' siswa. Hubungi Parama Tech untuk pindah ke paket yang lebih tinggi.']);
        }

        $invoice = $d['type'] === 'upgrade'
            ? $this->billing->createUpgradeInvoice($school, (int) $d['students'], $request->user())
            : $this->billing->createSubscriptionInvoice($school, (int) $d['students'], (int) $d['months'], $request->user());

        if ($invoice->wasRecentlyCreated) {
            $this->billing->notify($school, 'invoice', ['invoice' => $invoice]);
        }

        return redirect()->route('portal.billing.invoice', $invoice)->with('ok', "Tagihan {$invoice->number} siap dibayar.");
    }

    public function show(Request $request, Invoice $invoice, TenantFiles $files): View
    {
        // Kembali dari halaman gateway: langsung cek status agar tidak menunggu webhook.
        if ($request->boolean('selesai') && $invoice->isPayable() && $invoice->gateway_ref) {
            try {
                $this->billing->refreshStatus($invoice);
                $invoice->refresh();
            } catch (\Throwable $e) {
                Log::warning('Cek status pembayaran gagal', ['invoice' => $invoice->number, 'error' => $e->getMessage()]);
            }
        }

        return view('portal.billing.invoice', [
            'school' => $this->tenant->get(), 'invoice' => $invoice, 'online' => $this->billing->onlineEnabled(),
            'bank' => config('billing.bank'), 'proofUrl' => $files->url($invoice->transfer_proof_path),
        ]);
    }

    public function pay(Invoice $invoice): RedirectResponse
    {
        try {
            $url = $this->billing->checkout($invoice, $this->tenant->get(), route('portal.billing.invoice', ['invoice' => $invoice, 'selesai' => 1]));
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            Log::error('Checkout gagal', ['invoice' => $invoice->number, 'error' => $e->getMessage()]);

            return back()->withErrors(['invoice' => 'Halaman pembayaran belum bisa dibuka. Coba lagi beberapa saat atau gunakan transfer bank.']);
        }

        return redirect()->away($url);
    }

    public function check(Invoice $invoice): RedirectResponse
    {
        try {
            $status = $this->billing->refreshStatus($invoice);
        } catch (\Throwable $e) {
            return back()->withErrors(['invoice' => 'Status belum bisa dicek. Coba lagi beberapa saat.']);
        }

        return back()->with('ok', $status === 'paid' ? 'Pembayaran diterima. Terima kasih!' : 'Pembayaran belum kami terima. Jika sudah membayar, tunggu beberapa menit.');
    }

    public function proof(Request $request, Invoice $invoice, TenantFiles $files, ImageSanitizer $images, AuditLogger $audit): RedirectResponse
    {
        abort_unless($invoice->isPayable(), 422, 'Tagihan ini sudah tidak menunggu pembayaran.');
        $d = $request->validate([
            'proof' => ['required', 'file', 'max:5120', 'mimes:jpg,jpeg,png,webp,pdf'],
            'sender' => ['required', 'string', 'max:100'],
        ], [], ['proof' => 'bukti transfer', 'sender' => 'nama pengirim']);

        $file = $d['proof'];
        $base = 'billing/'.str_replace('/', '-', $invoice->number).'-bukti-'.now()->format('YmdHis');
        if (strtolower($file->getClientOriginalExtension()) === 'pdf' || $file->getMimeType() === 'application/pdf') {
            if (! str_starts_with((string) file_get_contents($file->getRealPath(), false, null, 0, 5), '%PDF-')) {
                throw ValidationException::withMessages(['proof' => 'Berkas PDF tidak valid.']);
            }
            $path = $files->put($base.'.pdf', (string) file_get_contents($file->getRealPath()));
        } else {
            $path = $files->put($base.'.webp', $images->webp($file, 1600));
        }

        $files->delete($invoice->transfer_proof_path);
        $invoice->forceFill(['transfer_proof_path' => $path, 'transfer_proof_at' => now(), 'payment_method' => 'transfer_manual',
            'notes' => 'Pengirim: '.$d['sender']])->save();
        $audit->log('billing.proof_uploaded', "Bukti transfer {$invoice->number} diunggah (pengirim: {$d['sender']})", $this->tenant->get());

        return back()->with('ok', 'Bukti transfer terkirim. Tim Parama Tech akan mengonfirmasi paling lambat 1×24 jam kerja; layanan aktif otomatis setelah dikonfirmasi.');
    }

    public function print(Invoice $invoice): View
    {
        return view('billing.print', ['school' => $this->tenant->get(), 'invoice' => $invoice, 'seller' => config('billing.seller'), 'bank' => config('billing.bank')]);
    }
}
