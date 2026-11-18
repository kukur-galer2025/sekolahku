<?php

namespace App\Http\Controllers\Panel;

use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Services\Billing\BillingCalculator;
use App\Services\Platform\AuditLogger;
use App\Support\Plan;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/** Panel Parama Tech → Paket & harga: harga per siswa per bulan + menu & fitur yang termasuk di tiap paket. */
class PlanController extends Controller
{
    public function __construct(private AuditLogger $audit, private BillingCalculator $calc) {}

    public function index(): View
    {
        $counts = DB::table('schools')->selectRaw('plan, count(*) as n')->groupBy('plan')->pluck('n', 'plan');

        return view('panel.plans', [
            'plans' => Plan::all(),
            'history' => Plan::priceHistory(),
            'counts' => $counts,
            'periods' => $this->calc->periods(),
            'fallback' => (int) config('billing.price_per_student_month'),
            'catalog' => config('billing.catalog', []),
            'featureMap' => Plan::featureMap(),
        ]);
    }

    /** PUT /panel/paket — simpan harga. Kosong = belum ditetapkan (tagihan memakai harga umum). */
    public function update(Request $request): RedirectResponse
    {
        $prices = [];
        $errors = [];
        foreach (Plan::keys() as $plan) {
            $raw = trim((string) $request->input("price.$plan", ''));
            $digits = preg_replace('/\D/', '', $raw); // terima "15.000", "Rp15.000", "15000"
            if ($raw !== '' && $digits === '') {
                $errors["price.$plan"] = 'Harga '.Plan::label($plan).' harus berupa angka rupiah.';
                continue;
            }
            $value = $digits === '' ? null : (int) $digits;
            if ($value !== null && ($value < 100 || $value > 10_000_000)) {
                $errors["price.$plan"] = 'Harga '.Plan::label($plan).' antara Rp100 dan Rp10.000.000 per siswa per bulan.';
                continue;
            }
            $prices[$plan] = $value;
        }
        if ($errors) {
            throw ValidationException::withMessages($errors);
        }

        $before = collect(Plan::keys())->mapWithKeys(fn ($p) => [$p => Plan::price($p)])->all();
        Plan::savePrices($prices, $request->user()->name);
        $changed = collect($prices)->filter(fn ($v, $p) => $before[$p] !== $v)
            ->map(fn ($v, $p) => Plan::label($p).': '.($before[$p] === null ? 'Rp –' : Invoice::rupiah($before[$p])).' → '.($v === null ? 'Rp –' : Invoice::rupiah($v)));

        if ($changed->isNotEmpty()) {
            $this->audit->log('plan.prices_updated', 'Harga paket diubah ('.$changed->implode('; ').')', null, null, ['before' => $before, 'after' => $prices]);
        }

        return back()->with('ok', $changed->isEmpty() ? 'Tidak ada perubahan harga.' : 'Harga paket tersimpan. Berlaku untuk tagihan baru; tagihan yang sudah dibuat tidak berubah.');
    }

    /**
     * PUT /panel/paket/fitur — centang menu per paket. Menu inti tidak bisa dimatikan.
     * Berlaku langsung untuk semua sekolah di paket itu (menu tersembunyi + akses API ditolak).
     */
    public function updateFeatures(Request $request): RedirectResponse
    {
        $request->validate(['features' => ['nullable', 'array'], 'features.*' => ['array'], 'features.*.*' => ['string']]);
        $before = Plan::featureMap();
        $input = (array) $request->input('features', []);
        $map = [];
        foreach (array_keys(config('billing.features', [])) as $f) {
            $map[$f] = array_values(array_intersect(Plan::keys(), (array) ($input[$f] ?? [])));
        }
        Plan::saveFeatures($map, $request->user()->name);

        $labels = collect(config('billing.catalog', []))->flatten(1)->filter(fn ($i) => isset($i['lock']))->pluck('label', 'lock');
        $changed = collect($map)->filter(fn ($plans, $f) => $plans != array_values($before[$f] ?? []))
            ->map(fn ($plans, $f) => ($labels[$f] ?? $f).': '.($plans ? implode(', ', array_map([Plan::class, 'label'], $plans)) : 'tidak ada paket'));
        if ($changed->isNotEmpty()) {
            $this->audit->log('plan.features_updated', 'Menu per paket diubah ('.$changed->implode('; ').')', null, null, ['before' => $before, 'after' => $map]);
        }

        return back()->with('ok', $changed->isEmpty() ? 'Tidak ada perubahan menu.' : 'Menu per paket tersimpan dan langsung berlaku untuk semua sekolah.');
    }
}
