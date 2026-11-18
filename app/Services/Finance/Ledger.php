<?php

namespace App\Services\Finance;

use App\Models\CashEntry;
use App\Models\Invoice;
use App\Models\InvoicePayment;
use App\Models\PayrollItem;
use App\Models\PayrollRun;
use App\Models\School;
use App\Services\Billing\BillingService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Buku kas Parama Tech, dirangkai dari tiga sumber tanpa menyalin data:
 *  1. Pembayaran invoice (invoice_payments; invoice lunas tanpa riwayat pembayaran dihitung satu baris sebesar total),
 *  2. Gaji yang sudah dibayar (payroll_items berstatus dibayar/parsial, digabung per periode & tanggal bayar),
 *  3. Catatan manual (cash_entries).
 * Karena dihitung langsung dari sumbernya, angka di buku kas selalu sama dengan menu Tagihan & Penggajian.
 *
 * Setiap baris: date, direction(in|out), category, label, amount, source(invoice|payroll|manual), id, url, operating(bool).
 */
class Ledger
{
    public function entries(Carbon $from, Carbon $to): Collection
    {
        $f = $from->toDateString();
        $t = $to->toDateString();
        $rows = collect();

        // 1a. Pembayaran invoice yang tercatat per transaksi (DP, cicilan, pelunasan).
        $pays = InvoicePayment::query()->whereBetween('paid_on', [$f, $t])->get();
        $inv = BillingService::invoices()->whereIn('id', $pays->pluck('invoice_id')->unique())->get(['id', 'number', 'school_id'])->keyBy('id');
        $schools = School::query()->whereIn('id', $inv->pluck('school_id')->unique())->pluck('name', 'id');
        foreach ($pays as $p) {
            $i = $inv->get($p->invoice_id);
            $rows->push($this->row($p->paid_on, 'in', 'langganan', $p->kindLabel().' '.($i?->number ?? '').' · '.($schools[$i?->school_id] ?? 'sekolah'),
                $p->amount, 'invoice', $p->invoice_id, route('panel.billing.show', $p->invoice_id), true, $p->ref, $i?->number));
        }

        // 1b. Invoice lunas sebelum fitur DP / lewat gateway tanpa baris pembayaran → satu baris sebesar total.
        $whole = BillingService::invoices()->where('status', 'paid')->whereBetween('paid_at', [$from->copy()->startOfDay()->utc(), $to->copy()->endOfDay()->utc()]) // paid_at disimpan UTC
            ->whereDoesntHave('payments')->get(['id', 'number', 'school_id', 'total', 'paid_at', 'payment_ref']);
        $names = School::query()->whereIn('id', $whole->pluck('school_id')->unique())->pluck('name', 'id');
        foreach ($whole as $i) {
            $rows->push($this->row($i->paid_at->copy()->timezone('Asia/Jakarta')->toDateString(), 'in', 'langganan', 'Pelunasan '.$i->number.' · '.($names[$i->school_id] ?? 'sekolah'),
                $i->total, 'invoice', $i->id, route('panel.billing.show', $i->id), true, $i->payment_ref, $i->number));
        }

        // 2. Gaji yang sudah dibayar, digabung per periode + tanggal bayar.
        $items = PayrollItem::query()->with('run:id,year,month,pay_date')->whereIn('pay_status', ['dibayar', 'parsial'])->get();
        $items->groupBy(fn ($i) => $i->payroll_run_id.'|'.($i->paid_on ?? $i->run?->pay_date ?? $this->runEnd($i->run))?->toDateString())
            ->each(function ($g, $key) use ($rows, $f, $t) {
                [$runId, $date] = explode('|', $key);
                if (! $date || $date < $f || $date > $t) {
                    return;
                }
                $run = $g->first()->run;
                $rows->push($this->row($date, 'out', 'gaji', 'Gaji '.($run?->label() ?? '').' · '.$g->count().' karyawan',
                    (int) $g->sum(fn ($i) => $i->paidValue()), 'payroll', (int) $runId, route('panel.payroll.show', (int) $runId), true, null,
                    $run ? sprintf('GJ/%04d/%02d', $run->year, $run->month) : null));
            });

        // 3. Catatan manual.
        foreach (CashEntry::query()->whereBetween('entry_date', [$f, $t])->get() as $e) {
            $rows->push($this->row($e->entry_date, $e->direction, $e->category, $e->description, $e->amount, 'manual', $e->id, null,
                CashEntry::isOperating($e->direction, $e->category), $e->ref, $e->code));
        }

        return $rows->filter(fn ($r) => $r['amount'] > 0)->sortBy([['date', 'asc'], ['direction', 'asc'], ['code', 'asc'], ['label', 'asc']])->values();
    }

    /** Saldo kas sebelum tanggal $date (semua catatan sejak awal). */
    public function balanceBefore(Carbon $date): int
    {
        $rows = $this->entries(Carbon::create(2000, 1, 1), $date->copy()->subDay());

        return (int) $rows->where('direction', 'in')->sum('amount') - (int) $rows->where('direction', 'out')->sum('amount');
    }

    /** Ringkasan satu periode untuk laporan. */
    public function summary(Collection $rows, int $opening): array
    {
        $in = (int) $rows->where('direction', 'in')->sum('amount');
        $out = (int) $rows->where('direction', 'out')->sum('amount');
        $revenue = (int) $rows->where('direction', 'in')->where('operating', true)->sum('amount');
        $expense = (int) $rows->where('direction', 'out')->where('operating', true)->sum('amount');
        $by = fn ($dir) => $rows->where('direction', $dir)->groupBy('category')
            ->map(fn ($g, $k) => ['label' => self::categoryLabel($dir, $k), 'amount' => (int) $g->sum('amount'), 'count' => $g->count(), 'operating' => (bool) $g->first()['operating']])
            ->sortByDesc('amount');

        return ['opening' => $opening, 'in' => $in, 'out' => $out, 'closing' => $opening + $in - $out,
            'revenue' => $revenue, 'expense' => $expense, 'profit' => $revenue - $expense, 'in_by' => $by('in'), 'out_by' => $by('out')];
    }

    public static function categoryLabel(string $dir, string $key): string
    {
        return match ($key) {
            'langganan' => 'Langganan sekolah (invoice)',
            'gaji' => 'Gaji karyawan (Penggajian)',
            default => CashEntry::categoryLabel($dir, $key),
        };
    }

    private function row($date, string $dir, string $cat, string $label, int $amount, string $source, int $id, ?string $url, bool $operating, ?string $ref = null, ?string $code = null): array
    {
        return ['date' => Carbon::parse($date instanceof \DateTimeInterface ? $date->format('Y-m-d') : $date, 'Asia/Jakarta')->startOfDay(), 'direction' => $dir, 'category' => $cat, 'category_label' => self::categoryLabel($dir, $cat),
            'label' => $label, 'amount' => $amount, 'source' => $source, 'id' => $id, 'url' => $url, 'operating' => $operating, 'ref' => $ref, 'code' => $code];
    }

    private function runEnd(?PayrollRun $run): ?Carbon
    {
        return $run ? Carbon::create($run->year, $run->month, 1)->endOfMonth() : null;
    }
}
