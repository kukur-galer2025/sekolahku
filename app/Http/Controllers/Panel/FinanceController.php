<?php

namespace App\Http\Controllers\Panel;

use App\Enums\SchoolStatus;
use App\Http\Controllers\Controller;
use App\Models\BankMutation;
use App\Models\CashEntry;
use App\Models\Invoice;
use App\Models\PayrollRun;
use App\Models\School;
use App\Services\Billing\BillingService;
use App\Services\Finance\Ledger;
use App\Services\Finance\MutationImporter;
use App\Services\Platform\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Panel Parama Tech → Keuangan: ringkasan, buku kas & laporan, dan pencocokan mutasi bank.
 */
class FinanceController extends Controller
{
    private const TZ = 'Asia/Jakarta';

    public function __construct(private Ledger $ledger, private BillingService $billing, private AuditLogger $audit) {}

    // ── Ringkasan ───────────────────────────────────────────────────

    public function dashboard(): View
    {
        $now = now(self::TZ);
        $from = $now->copy()->startOfMonth()->subMonths(11);
        $rows = $this->ledger->entries($from, $now->copy()->endOfMonth());
        $months = collect(range(0, 11))->map(function ($k) use ($from, $rows) {
            $m = $from->copy()->addMonths($k);
            $r = $rows->filter(fn ($x) => $x['date']->format('Y-m') === $m->format('Y-m'));

            return ['label' => $m->translatedFormat('M y'), 'in' => (int) $r->where('direction', 'in')->sum('amount'), 'out' => (int) $r->where('direction', 'out')->sum('amount'),
                'revenue' => (int) $r->where('direction', 'in')->where('operating', true)->sum('amount'), 'expense' => (int) $r->where('direction', 'out')->where('operating', true)->sum('amount')];
        });
        $balance = $this->ledger->balanceBefore($now->copy()->addDay()->startOfDay());

        // Piutang per umur (dari sisa tagihan terbuka).
        $open = BillingService::invoices()->with('school:id,name')->where('status', 'unpaid')->orderBy('due_at')->get();
        $aging = ['current' => 0, 'd30' => 0, 'd60' => 0, 'over' => 0];
        foreach ($open as $i) {
            $late = (int) floor($i->due_at->diffInDays($now, false));
            $aging[$late <= 0 ? 'current' : ($late <= 30 ? 'd30' : ($late <= 60 ? 'd60' : 'over'))] += $i->remaining();
        }
        $due30 = (int) $open->filter(fn ($i) => $i->due_at->lte($now->copy()->addDays(30)))->sum(fn ($i) => $i->remaining());

        // Sekolah yang masa aktifnya habis ≤ 30 hari.
        $expiring = School::query()->where('status', SchoolStatus::Active)->whereNotNull('active_until')
            ->whereBetween('active_until', [$now->copy()->startOfDay(), $now->copy()->addDays(30)->endOfDay()])->orderBy('active_until')->get(['id', 'name', 'active_until', 'student_quota']);
        $hasOpen = $open->pluck('school_id')->flip();

        // Proyeksi 30 hari: saldo + piutang jatuh tempo ≤30 hari − gaji (periode terakhir) − rata-rata beban manual 3 bulan.
        $lastRun = PayrollRun::query()->orderByDesc('year')->orderByDesc('month')->first();
        $payroll = $lastRun ? (int) $lastRun->summary()['net'] : 0;
        $avgOps = (int) round($rows->where('direction', 'out')->where('source', 'manual')->where('operating', true)
            ->filter(fn ($x) => $x['date']->gte($now->copy()->startOfMonth()->subMonths(3)) && $x['date']->lt($now->copy()->startOfMonth()))->sum('amount') / 3);

        $thisM = $months->last();
        $prevM = $months->slice(-2, 1)->first();

        return view('panel.finance.dashboard', [
            'months' => $months, 'balance' => $balance, 'aging' => $aging, 'open' => $open->take(8), 'openCount' => $open->count(),
            'expiring' => $expiring, 'hasOpen' => $hasOpen, 'due30' => $due30, 'payroll' => $payroll, 'lastRun' => $lastRun, 'avgOps' => $avgOps,
            'projection' => $balance + $due30 - $payroll - $avgOps, 'thisM' => $thisM, 'prevM' => $prevM,
            'pendingMut' => BankMutation::where('status', 'pending')->count(),
            'year' => ['revenue' => (int) $rows->where('direction', 'in')->where('operating', true)->filter(fn ($x) => $x['date']->year === $now->year)->sum('amount'),
                'expense' => (int) $rows->where('direction', 'out')->where('operating', true)->filter(fn ($x) => $x['date']->year === $now->year)->sum('amount')],
        ]);
    }

    // ── Buku kas ────────────────────────────────────────────────────

    public function ledger(Request $request): View
    {
        [$from, $to, $label] = $this->period($request);
        $rows = $this->ledger->entries($from, $to);
        $arah = in_array($request->query('arah'), ['in', 'out'], true) ? $request->query('arah') : null;
        $cari = mb_strtolower(trim((string) $request->query('cari')));
        $opening = $this->ledger->balanceBefore($from);
        $sum = $this->ledger->summary($rows, $opening);

        // Saldo berjalan per baris.
        $run = $opening;
        $rows = $rows->map(function ($r) use (&$run) {
            $run += $r['direction'] === 'in' ? $r['amount'] : -$r['amount'];

            return $r + ['balance' => $run];
        });

        return view('panel.finance.ledger', [
            'rows' => $rows->when($arah, fn ($c) => $c->where('direction', $arah))
                ->when($cari !== '', fn ($c) => $c->filter(fn ($r) => str_contains(mb_strtolower($r['code'].' '.$r['label'].' '.$r['ref'].' '.$r['category_label']), $cari)))->values(),
            'cari' => $cari, 'sum' => $sum, 'label' => $label,
            'from' => $from, 'to' => $to, 'arah' => $arah,
            'edit' => $request->integer('edit') ? CashEntry::find($request->integer('edit')) : null,
        ]);
    }

    public function storeEntry(Request $request, ?CashEntry $entry = null): RedirectResponse
    {
        $request->merge(['amount' => (int) preg_replace('/\D/', '', explode(',', (string) $request->input('amount'))[0])]);
        $d = $request->validate([
            'entry_date' => ['required', 'date', 'before_or_equal:'.now(self::TZ)->addDay()->toDateString()],
            'direction' => ['required', 'in:in,out'],
            'category' => ['required', Rule::in(array_keys($request->input('direction') === 'in' ? CashEntry::IN : CashEntry::OUT))],
            'amount' => ['required', 'integer', 'min:1', 'max:100000000000'],
            'description' => ['required', 'string', 'max:200'],
            'method' => ['nullable', Rule::in(array_keys(CashEntry::METHODS))],
            'ref' => ['nullable', 'string', 'max:120'],
        ], [], ['entry_date' => 'tanggal', 'category' => 'kategori', 'amount' => 'nominal', 'description' => 'keterangan']);

        if ($entry) {
            $entry->update($d);
        } else {
            $entry = CashEntry::create($d + ['created_by' => $request->user()->id]);
        }
        $this->audit->log('finance.cash_saved', $entry->code.' · '.($entry->direction === 'in' ? 'Pemasukan ' : 'Pengeluaran ').Invoice::rupiah($entry->amount).' dicatat: '.$entry->description, null, $request->user());

        return redirect()->route('panel.finance.ledger', ['bulan' => $entry->entry_date->format('Y-m')])->with('ok', "Catatan kas {$entry->code} disimpan.");
    }

    public function destroyEntry(Request $request, CashEntry $entry): RedirectResponse
    {
        BankMutation::where('cash_entry_id', $entry->id)->update(['status' => 'pending', 'cash_entry_id' => null, 'handled_by' => null, 'handled_at' => null]);
        $entry->delete();
        $this->audit->log('finance.cash_deleted', 'Catatan kas '.$entry->code.' dihapus: '.$entry->description.' · '.Invoice::rupiah($entry->amount), null, $request->user());

        return back()->with('ok', 'Catatan kas dihapus.');
    }

    /** Laporan XLSX: Buku kas (dengan saldo berjalan), Ringkasan per kategori, Laba/rugi. */
    public function exportLedger(Request $request): BinaryFileResponse
    {
        [$from, $to, $label] = $this->period($request);
        $rows = $this->ledger->entries($from, $to);
        $opening = $this->ledger->balanceBefore($from);
        $s = $this->ledger->summary($rows, $opening);

        $tmp = tempnam(sys_get_temp_dir(), 'kas').'.xlsx';
        $w = new \OpenSpout\Writer\XLSX\Writer;
        $w->openToFile($tmp);
        $bold = (new \OpenSpout\Common\Entity\Style\Style)->setFontBold();
        $head = (new \OpenSpout\Common\Entity\Style\Style)->setFontBold()->setFontColor('FFFFFF')->setBackgroundColor('0B63E5');
        $title = (new \OpenSpout\Common\Entity\Style\Style)->setFontBold()->setFontSize(14);
        $add = fn (array $v, $st = null) => $w->addRow(\OpenSpout\Common\Entity\Row::fromValues($v, $st));

        $w->getCurrentSheet()->setName('Buku Kas');
        $add(['BUKU KAS PARAMAEDU.TECH'], $title);
        $add(['Periode', $label]);
        $add(['Dibuat', now(self::TZ)->format('d-m-Y H:i').' WIB']);
        $add([]);
        $add(['Tanggal', 'Kode', 'Keterangan', 'Kategori', 'Sumber', 'Referensi eksternal', 'Masuk', 'Keluar', 'Saldo'], $head);
        $add(['', '', 'Saldo awal periode', '', '', '', '', '', $opening], $bold);
        $bal = $opening;
        foreach ($rows as $r) {
            $bal += $r['direction'] === 'in' ? $r['amount'] : -$r['amount'];
            $add([$r['date']->format('d-m-Y'), (string) $r['code'], $r['label'], $r['category_label'], ['invoice' => 'Tagihan', 'payroll' => 'Penggajian', 'manual' => 'Manual'][$r['source']],
                (string) $r['ref'], $r['direction'] === 'in' ? $r['amount'] : '', $r['direction'] === 'out' ? $r['amount'] : '', $bal]);
        }
        $add(['', '', 'TOTAL', '', '', '', $s['in'], $s['out'], $s['closing']], $bold);

        $w->addNewSheetAndMakeItCurrent()->setName('Per Kategori');
        $add(['RINGKASAN PER KATEGORI · '.$label], $title);
        $add([]);
        $add(['Pemasukan', 'Jumlah transaksi', 'Nominal'], $head);
        foreach ($s['in_by'] as $c) {
            $add([$c['label'], $c['count'], $c['amount']]);
        }
        $add(['Total pemasukan', '', $s['in']], $bold);
        $add([]);
        $add(['Pengeluaran', 'Jumlah transaksi', 'Nominal'], $head);
        foreach ($s['out_by'] as $c) {
            $add([$c['label'], $c['count'], $c['amount']]);
        }
        $add(['Total pengeluaran', '', $s['out']], $bold);

        $w->addNewSheetAndMakeItCurrent()->setName('Laba Rugi');
        $add(['LAPORAN LABA/RUGI SEDERHANA · '.$label], $title);
        $add(['Dasar kas: dicatat saat uang diterima/dibayar. Modal, prive, pinjaman, dan saldo awal tidak termasuk.']);
        $add([]);
        $add(['PENDAPATAN'], $bold);
        foreach ($s['in_by']->where('operating', true) as $c) {
            $add(['   '.$c['label'], $c['amount']]);
        }
        $add(['Total pendapatan', $s['revenue']], $bold);
        $add([]);
        $add(['BEBAN'], $bold);
        foreach ($s['out_by']->where('operating', true) as $c) {
            $add(['   '.$c['label'], $c['amount']]);
        }
        $add(['Total beban', $s['expense']], $bold);
        $add([]);
        $add([$s['profit'] >= 0 ? 'LABA BERSIH' : 'RUGI BERSIH', $s['profit']], $title);
        $add([]);
        $add(['Saldo kas awal', $opening]);
        $add(['Saldo kas akhir', $s['closing']]);
        $w->close();
        $this->audit->log('finance.exported', 'Unduh laporan keuangan '.$label, null, $request->user());

        return response()->download($tmp, 'Laporan_Keuangan_ParamaEduTech_'.$from->format('Ymd').'-'.$to->format('Ymd').'.xlsx')->deleteFileAfterSend();
    }

    // ── Mutasi bank ─────────────────────────────────────────────────

    public function mutations(Request $request): View
    {
        $status = $request->query('status', 'pending');
        $list = BankMutation::query()->with(['suggested:id,number,total,paid_amount,status,school_id', 'invoice:id,number', 'handler:id,name', 'cashEntry:id,code'])
            ->when(array_key_exists($status, BankMutation::STATUSES), fn ($q) => $q->where('status', $status))
            ->orderByDesc('trx_date')->orderByDesc('id')->paginate(50)->withQueryString();
        $open = BillingService::invoices()->with('school:id,name')->where('status', 'unpaid')->orderBy('number')->get(['id', 'number', 'school_id', 'total', 'paid_amount', 'status']);
        $counts = BankMutation::query()->selectRaw('status, count(*) n')->groupBy('status')->pluck('n', 'status');

        return view('panel.finance.mutations', ['list' => $list, 'status' => $status, 'open' => $open, 'counts' => $counts,
            'suggestN' => BankMutation::where('status', 'pending')->whereNotNull('suggested_invoice_id')->count()]);
    }

    public function importMutations(Request $request, MutationImporter $importer): RedirectResponse
    {
        $request->validate(['file' => ['required', 'file', 'max:5120']], [], ['file' => 'file mutasi']);
        $ext = strtolower($request->file('file')->getClientOriginalExtension());
        if (! in_array($ext, ['csv', 'xlsx', 'txt'], true)) {
            return back()->withErrors(['file' => 'Pilih file mutasi .csv atau .xlsx dari internet banking.']);
        }
        $r = $importer->import($request->file('file')->getRealPath(), $ext === 'xlsx' ? 'xlsx' : 'csv', $request->file('file')->getClientOriginalName());
        $this->audit->log('finance.mutations_imported', "Impor mutasi bank: {$r['added']} baris baru, {$r['skipped']} sudah pernah diimpor", null, $request->user());

        return redirect()->route('panel.finance.mutations')->with('ok', "{$r['added']} baris mutasi baru diimpor".($r['skipped'] ? " ({$r['skipped']} sudah ada, dilewati)" : '')
            .". {$r['matched_suggestions']} kredit punya saran invoice yang cocok — periksa lalu klik Cocokkan.");
    }

    /** Kredit → pembayaran invoice (DP / tambahan / pelunasan otomatis sesuai nominal). */
    public function matchMutation(Request $request, BankMutation $mutation): RedirectResponse
    {
        $d = $request->validate(['invoice_id' => ['required', 'integer']], [], ['invoice_id' => 'invoice']);
        $msg = $this->applyMatch($mutation, (int) $d['invoice_id'], $request);

        return back()->with('ok', $msg);
    }

    public function matchAll(Request $request): RedirectResponse
    {
        $ok = 0;
        $fail = [];
        foreach (BankMutation::where('status', 'pending')->where('direction', 'in')->whereNotNull('suggested_invoice_id')->orderBy('trx_date')->get() as $m) {
            try {
                $this->applyMatch($m, $m->suggested_invoice_id, $request);
                $ok++;
            } catch (ValidationException $e) {
                $fail[] = $m->trx_date->format('d/m').' '.Invoice::rupiah($m->amount).': '.collect($e->errors())->flatten()->first();
            }
        }
        $r = back()->with('ok', "$ok mutasi dicocokkan ke invoice.");

        return $fail ? $r->withErrors(['match' => 'Tidak bisa dicocokkan: '.implode(' · ', array_slice($fail, 0, 5))]) : $r;
    }

    /** Catat mutasi ke buku kas (pengeluaran operasional, pendapatan lain, dll.). */
    public function recordMutation(Request $request, BankMutation $mutation): RedirectResponse
    {
        abort_unless($mutation->status === 'pending', 422, 'Mutasi ini sudah diproses.');
        $cats = $mutation->direction === 'in' ? CashEntry::IN : CashEntry::OUT;
        $d = $request->validate(['category' => ['required', Rule::in(array_keys($cats))], 'description' => ['nullable', 'string', 'max:200']], [], ['category' => 'kategori']);
        $e = CashEntry::create(['entry_date' => $mutation->trx_date, 'direction' => $mutation->direction, 'category' => $d['category'], 'amount' => $mutation->amount,
            'description' => mb_substr(($d['description'] ?? null) ?: $mutation->description, 0, 200), 'method' => 'transfer', 'ref' => 'Mutasi bank', 'created_by' => $request->user()->id]);
        $mutation->update(['status' => 'recorded', 'cash_entry_id' => $e->id, 'handled_by' => $request->user()->id, 'handled_at' => now()]);

        return back()->with('ok', "Dicatat di buku kas: {$e->code} · ".CashEntry::categoryLabel($e->direction, $e->category).'.');
    }

    public function ignoreMutation(Request $request, BankMutation $mutation): RedirectResponse
    {
        if ($mutation->status === 'ignored') {
            $mutation->update(['status' => 'pending', 'handled_by' => null, 'handled_at' => null]);

            return back()->with('ok', 'Mutasi dikembalikan ke daftar belum diproses.');
        }
        abort_unless($mutation->status === 'pending', 422, 'Mutasi ini sudah diproses.');
        $mutation->update(['status' => 'ignored', 'handled_by' => $request->user()->id, 'handled_at' => now()]);

        return back()->with('ok', 'Mutasi diabaikan (mis. gaji yang sudah tercatat di Penggajian, atau transfer antar-rekening sendiri).');
    }

    // ── bantuan ──

    private function applyMatch(BankMutation $m, int $invoiceId, Request $request): string
    {
        if ($m->status !== 'pending' || $m->direction !== 'in') {
            throw ValidationException::withMessages(['invoice_id' => 'Hanya kredit yang belum diproses yang bisa dicocokkan.']);
        }
        $inv = BillingService::invoices()->findOrFail($invoiceId);
        if (! $inv->isPayable()) {
            throw ValidationException::withMessages(['invoice_id' => "Tagihan {$inv->number} sudah tidak menunggu pembayaran."]);
        }
        if ($m->amount > $inv->remaining()) {
            throw ValidationException::withMessages(['invoice_id' => 'Nominal '.Invoice::rupiah($m->amount)." lebih besar dari sisa {$inv->number} (".Invoice::rupiah($inv->remaining()).'). Catat selisihnya manual.']);
        }
        $r = $this->billing->recordPayment($inv, ['amount' => $m->amount, 'paid_on' => $m->trx_date->toDateString(), 'method' => 'transfer_manual',
            'ref' => mb_substr('Mutasi: '.$m->description, 0, 120)], $request->user()->name.' (cocokkan mutasi)', $request->user());
        $m->update(['status' => 'matched', 'invoice_id' => $inv->id, 'invoice_payment_id' => $r['payment']->id, 'handled_by' => $request->user()->id, 'handled_at' => now()]);

        return Invoice::rupiah($m->amount)." dicocokkan ke {$inv->number}".($r['paid'] ? ' — tagihan LUNAS, masa aktif sekolah diperbarui.' : ' — sisa '.Invoice::rupiah($inv->remaining()).'.');
    }

    /** @return array{0:Carbon,1:Carbon,2:string} */
    private function period(Request $request): array
    {
        $now = now(self::TZ);
        if ($request->filled('dari') && $request->filled('sampai')) {
            try {
                $f = Carbon::parse($request->query('dari'), self::TZ)->startOfDay();
                $t = Carbon::parse($request->query('sampai'), self::TZ)->endOfDay();
                if ($f->lte($t)) {
                    return [$f, $t, $f->translatedFormat('d M Y').' – '.$t->translatedFormat('d M Y')];
                }
            } catch (\Throwable) {
            }
        }
        if ($request->filled('tahun') && preg_match('/^\d{4}$/', (string) $request->query('tahun'))) {
            $y = (int) $request->query('tahun');

            return [Carbon::create($y, 1, 1, 0, 0, 0, self::TZ), Carbon::create($y, 12, 31, 23, 59, 59, self::TZ), 'Tahun '.$y];
        }
        $m = preg_match('/^\d{4}-\d{2}$/', (string) $request->query('bulan')) ? Carbon::createFromFormat('!Y-m', $request->query('bulan'), self::TZ) : $now->copy()->startOfMonth();

        return [$m->copy()->startOfMonth(), $m->copy()->endOfMonth(), PayrollRun::MONTHS[$m->month].' '.$m->year];
    }
}
