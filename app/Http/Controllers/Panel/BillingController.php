<?php

namespace App\Http\Controllers\Panel;

use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Models\InvoicePayment;
use App\Models\PaymentEvent;
use App\Models\School;
use App\Services\Billing\BillingService;
use App\Tenancy\SchoolScope;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantFiles;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/** Panel Parama Tech: semua tagihan, konfirmasi transfer manual, pembatalan, tagihan khusus, log webhook. */
class BillingController extends Controller
{
    public function __construct(private BillingService $billing) {}

    private function find(int $id): Invoice
    {
        return BillingService::invoices()->findOrFail($id);
    }

    public function index(Request $request): View
    {
        $status = $request->query('status');
        $q = trim((string) $request->query('q'));
        $invoices = BillingService::invoices()->with('school:id,name,subdomain')
            ->when(in_array($status, ['unpaid', 'paid', 'void'], true), fn ($b) => $b->where('status', $status))
            ->when($status === 'partial', fn ($b) => $b->where('status', 'unpaid')->where('paid_amount', '>', 0))
            ->when($status === 'proof', fn ($b) => $b->where('status', 'unpaid')->whereNotNull('transfer_proof_at'))
            ->when($q !== '', fn ($b) => $b->where(fn ($w) => $w->where('number', 'like', "%$q%")
                ->orWhereIn('school_id', School::where('name', 'like', "%$q%")->orWhere('subdomain', 'like', "%$q%")->pluck('id'))))
            ->orderByRaw("CASE WHEN status = 'unpaid' AND transfer_proof_at IS NOT NULL THEN 0 ELSE 1 END")
            ->latest('id')->paginate(30)->withQueryString();

        $paid = BillingService::invoices()->where('status', 'paid');
        $summary = [
            'month' => (clone $paid)->where('paid_at', '>=', now()->startOfMonth())->sum('total'),
            'year' => (clone $paid)->where('paid_at', '>=', now()->startOfYear())->sum('total'),
            'unpaid' => (int) BillingService::invoices()->where('status', 'unpaid')->sum(DB::raw('total - paid_amount')),
            'partial_n' => BillingService::invoices()->where('status', 'unpaid')->where('paid_amount', '>', 0)->count(),
            'unpaid_n' => BillingService::invoices()->where('status', 'unpaid')->count(),
            'proof_n' => BillingService::invoices()->where('status', 'unpaid')->whereNotNull('transfer_proof_at')->count(),
            'locked_n' => School::where('suspension_type', 'billing')->count(),
        ];
        $events = PaymentEvent::query()->withoutGlobalScope(SchoolScope::class)->latest('id')->limit(15)->get();

        return view('panel.billing.index', compact('invoices', 'summary', 'status', 'q', 'events'));
    }

    public function show(int $invoice, TenantFiles $files, TenantContext $tenant): View
    {
        $inv = $this->find($invoice)->load('payments.creator:id,name');
        $school = School::findOrFail($inv->school_id);
        // URL bukti transfer dibuat dalam konteks sekolah pemiliknya (rute bertanda tangan di subdomain sekolah).
        $proofUrl = $inv->transfer_proof_path ? $tenant->run($school, fn () => $files->url($inv->transfer_proof_path, 15)) : null;
        $events = PaymentEvent::query()->withoutGlobalScope(SchoolScope::class)->where('invoice_id', $inv->id)->latest('id')->get();

        return view('panel.billing.show', ['invoice' => $inv, 'school' => $school, 'proofUrl' => $proofUrl, 'events' => $events, 'online' => $this->billing->onlineEnabled()]);
    }

    public function markPaid(Request $request, int $invoice): RedirectResponse
    {
        $inv = $this->find($invoice);
        $d = $request->validate([
            'method' => ['required', 'in:transfer_manual,tunai,lainnya'],
            'ref' => ['required', 'string', 'max:120'],
        ], [], ['ref' => 'referensi pembayaran']);

        $done = $this->billing->markPaid($inv, $d['method'], $d['ref'], $request->user()->name.' (Parama Tech)', $request->user());

        return back()->with('ok', $done ? "Tagihan {$inv->number} dikonfirmasi lunas. Masa aktif & kuota sekolah sudah diperbarui." : 'Tagihan ini sudah tidak menunggu pembayaran.');
    }

    /** DP / uang muka / pembayaran tambahan. Sisa 0 → otomatis lunas. */
    public function storePayment(Request $request, int $invoice): RedirectResponse
    {
        $inv = $this->find($invoice);
        $d = $request->validate([
            'kind' => ['nullable', 'in:dp,tambahan'],
            'amount' => ['required', 'integer', 'min:1', 'max:1000000000'],
            'paid_on' => ['required', 'date', 'before_or_equal:today'],
            'method' => ['required', 'in:transfer_manual,tunai,lainnya'],
            'ref' => ['nullable', 'string', 'max:120'],
            'note' => ['nullable', 'string', 'max:200'],
            'activate' => ['nullable', 'boolean'],
        ], [], ['amount' => 'nominal', 'paid_on' => 'tanggal bayar', 'ref' => 'referensi', 'note' => 'catatan']);

        $r = $this->billing->recordPayment($inv, $d, $request->user()->name.' (Parama Tech)', $request->user());
        $msg = $r['payment']->kindLabel().' '.Invoice::rupiah($r['payment']->amount).' dicatat.';
        $msg .= $r['paid'] ? " Tagihan {$inv->number} lunas; masa aktif & kuota sekolah sudah diperbarui."
            : ' Sisa tagihan '.Invoice::rupiah($inv->remaining()).'.'.($r['activated'] ? ' Layanan sekolah sudah diaktifkan.' : '');

        return back()->with('ok', $msg);
    }

    public function destroyPayment(Request $request, int $invoice, int $payment): RedirectResponse
    {
        $inv = $this->find($invoice);
        $p = InvoicePayment::query()->where('invoice_id', $inv->id)->findOrFail($payment);
        $this->billing->deletePayment($inv, $p, $request->user()->name.' (Parama Tech)', $request->user());

        return back()->with('ok', 'Pembayaran '.Invoice::rupiah($p->amount).' dihapus. Sisa tagihan '.Invoice::rupiah($inv->remaining()).'.');
    }

    public function void(Request $request, int $invoice): RedirectResponse
    {
        $inv = $this->find($invoice);
        $d = $request->validate(['reason' => ['required', 'string', 'max:200']], [], ['reason' => 'alasan']);
        $this->billing->void($inv, $d['reason'], $request->user()->name.' (Parama Tech)', $request->user());

        return back()->with('ok', "Tagihan {$inv->number} dibatalkan.");
    }

    public function check(int $invoice): RedirectResponse
    {
        $inv = $this->find($invoice);
        try {
            $status = $this->billing->refreshStatus($inv);
        } catch (\Throwable $e) {
            return back()->withErrors(['invoice' => 'Gateway tidak bisa dihubungi: '.$e->getMessage()]);
        }

        return back()->with('ok', "Status terbaru: $status.");
    }

    /** Tagihan dari panel: paket (new) atau tagihan khusus (custom). */
    public function store(Request $request, School $school): RedirectResponse
    {
        $d = $request->validate([
            'kind' => ['required', 'in:package,custom'],
            'students' => ['required_if:kind,package', 'nullable', 'integer', 'min:1', 'max:100000'],
            'months' => ['required_if:kind,package', 'nullable', 'integer'],
            'description' => ['required_if:kind,custom', 'nullable', 'string', 'max:200'],
            'amount' => ['required_if:kind,custom', 'nullable', 'integer', 'min:1000', 'max:1000000000'],
            'send' => ['nullable', 'boolean'],
        ], [], ['students' => 'jumlah siswa', 'months' => 'periode', 'description' => 'uraian', 'amount' => 'nominal']);

        $inv = $d['kind'] === 'package'
            ? $this->billing->createSubscriptionInvoice($school, (int) $d['students'], (int) $d['months'], $request->user())
            : $this->billing->createCustomInvoice($school, $d['description'], (int) $d['amount'], $request->user());

        if (! empty($d['send'])) {
            $this->billing->notify($school, 'invoice', ['invoice' => $inv]);
        }

        return redirect()->route('panel.billing.show', $inv->id)->with('ok', "Tagihan {$inv->number} dibuat".(! empty($d['send']) ? ' dan dikirim ke email admin sekolah.' : '.'));
    }

    public function print(int $invoice): View
    {
        $inv = $this->find($invoice);

        $inv->load('payments');

        return view('billing.print', ['school' => School::findOrFail($inv->school_id), 'invoice' => $inv, 'seller' => config('billing.seller'), 'bank' => config('billing.bank')]);
    }
}
