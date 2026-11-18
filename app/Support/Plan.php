<?php

namespace App\Support;

use App\Models\Invoice;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Paket langganan MyPresence: Starter, Pro, All Service (config/billing.php → 'plans').
 * Harga per siswa per bulan diisi super-admin di panel (Paket & harga), cadangan: .env PLAN_*_PRICE.
 * Selama harga paket kosong, tagihan memakai harga umum BILLING_PRICE_PER_STUDENT.
 */
final class Plan
{
    private const CACHE_KEY = 'platform:plan_prices';

    private const FEATURE_CACHE_KEY = 'platform:plan_features';

    /** @return array<string, array{label:string, price:?int, summary:string}> */
    public static function all(): array
    {
        return config('billing.plans', []);
    }

    /** @return list<string> */
    public static function keys(): array
    {
        return array_keys(self::all());
    }

    public static function default(): string
    {
        return (string) config('billing.default_plan', 'starter');
    }

    /** Nilai lama (mis. 'per_student') atau tidak dikenal → paket bawaan. */
    public static function normalize(?string $plan): string
    {
        return $plan !== null && isset(self::all()[$plan]) ? $plan : self::default();
    }

    public static function label(?string $plan): string
    {
        return self::all()[self::normalize($plan)]['label'] ?? ucfirst((string) $plan);
    }

    /**
     * Harga per siswa per bulan; null = belum ditentukan.
     * Urutan: harga dari panel (tabel plan_prices) → PLAN_*_PRICE di .env → null.
     */
    public static function price(?string $plan): ?int
    {
        $key = self::normalize($plan);
        $p = self::panelPrices()[$key] ?? self::all()[$key]['price'] ?? null;

        return $p === null ? null : max(0, (int) $p);
    }

    /** Harga yang diisi super-admin di panel. @return array<string, ?int> */
    public static function panelPrices(): array
    {
        try {
            return Cache::remember(self::CACHE_KEY, 600, fn () => DB::table('plan_prices')->pluck('price', 'plan')
                ->map(fn ($v) => $v === null ? null : (int) $v)->all());
        } catch (\Throwable) {
            return []; // tabel belum dimigrasi: pakai .env
        }
    }

    /** Simpan harga dari panel. Nilai null = kosongkan (kembali ke .env / harga umum). @param array<string, ?int> $prices */
    public static function savePrices(array $prices, ?string $byName = null): void
    {
        $now = now();
        foreach (self::keys() as $plan) {
            if (! array_key_exists($plan, $prices)) {
                continue;
            }
            DB::table('plan_prices')->updateOrInsert(['plan' => $plan],
                ['price' => $prices[$plan], 'updated_by_name' => $byName, 'updated_at' => $now, 'created_at' => $now]);
        }
        Cache::forget(self::CACHE_KEY);
    }

    /** Info pengubah terakhir per paket. @return array<string, object> */
    public static function priceHistory(): array
    {
        try {
            return DB::table('plan_prices')->get()->keyBy('plan')->all();
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * Pembagian fitur per paket: bawaan config/billing.php → 'features', ditimpa pengaturan panel (tabel plan_features).
     *
     * @return array<string, list<string>> kunci fitur → paket yang memuatnya
     */
    public static function featureMap(): array
    {
        $map = config('billing.features', []);
        try {
            $panel = Cache::remember(self::FEATURE_CACHE_KEY, 600, fn () => DB::table('plan_features')->pluck('plans', 'feature')
                ->map(fn ($v) => array_values(array_intersect(self::keys(), (array) json_decode((string) $v, true))))->all());
        } catch (\Throwable) {
            $panel = []; // tabel belum dimigrasi
        }
        foreach ($panel as $f => $plans) {
            if (array_key_exists($f, $map)) {
                $map[$f] = $plans;
            }
        }

        return $map;
    }

    /** Simpan pembagian fitur dari panel. @param array<string, list<string>> $map */
    public static function saveFeatures(array $map, ?string $byName = null): void
    {
        $now = now();
        foreach ($map as $feature => $plans) {
            if (! array_key_exists($feature, config('billing.features', []))) {
                continue;
            }
            DB::table('plan_features')->updateOrInsert(['feature' => $feature],
                ['plans' => json_encode(array_values(array_intersect(self::keys(), $plans))), 'updated_by_name' => $byName, 'updated_at' => $now, 'created_at' => $now]);
        }
        Cache::forget(self::FEATURE_CACHE_KEY);
    }

    /** Apakah paket ini memuat fitur tersebut? Fitur yang tidak terdaftar di billing.features terbuka untuk semua paket. */
    public static function allows(?string $plan, string $feature): bool
    {
        $allowed = self::featureMap()[$feature] ?? null;

        return $allowed === null || in_array(self::normalize($plan), (array) $allowed, true);
    }

    /** Kunci fitur yang terkunci untuk paket ini. @return list<string> */
    public static function locked(?string $plan): array
    {
        return array_values(array_filter(array_keys(config('billing.features', [])), fn ($f) => ! self::allows($plan, $f)));
    }

    /**
     * Daftar menu untuk satu paket (katalog billing.catalog): menu inti + fitur yang dimuat paket.
     *
     * @return array<string, list<array{label:string, desc:string, roles:string}>> per kelompok
     */
    public static function menus(?string $plan): array
    {
        $out = [];
        foreach (config('billing.catalog', []) as $group => $items) {
            foreach ($items as $i) {
                if (! isset($i['lock']) || self::allows($plan, $i['lock'])) {
                    $out[$group][] = $i;
                }
            }
        }

        return $out;
    }

    /** Paket termurah yang memuat fitur ini (untuk pesan "tersedia di paket …"). */
    public static function cheapestWith(string $feature): ?string
    {
        foreach (self::keys() as $p) {
            if (self::allows($p, $feature)) {
                return $p;
            }
        }

        return null;
    }

    /** Fitur dari alamat request (config billing.feature_paths), mis. 'api/cbt/3' → 'cbt'. */
    public static function featureForPath(string $path): ?string
    {
        foreach (config('billing.feature_paths', []) as $pattern => $feature) {
            if (\Illuminate\Support\Str::is($pattern, $path)) {
                return $feature;
            }
        }

        return null;
    }

    /** Batas kuantitas paket (null = tanpa batas). */
    public static function limit(?string $plan, string $key): ?int
    {
        $v = config('billing.limits.'.self::normalize($plan).'.'.$key);

        return $v === null ? null : (int) $v;
    }

    /** Pesan untuk fitur yang terkunci. */
    public static function lockedMessage(string $feature): string
    {
        $p = self::cheapestWith($feature);

        return 'Fitur ini tersedia mulai paket '.($p ? self::label($p) : 'lebih tinggi').'. Admin sekolah bisa upgrade paket di menu Langganan.';
    }

    /** "Rp15.000" atau "Rp –" bila harga belum diisi. */
    public static function priceLabel(?string $plan): string
    {
        $p = self::price($plan);

        return $p === null ? 'Rp –' : Invoice::rupiah($p);
    }
}
