<?php

namespace App\Rules;

use App\Models\School;
use App\Tenancy\Subdomain;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Validasi subdomain untuk pendaftaran sekolah baru (tahap 4) dan panel super-admin.
 * Pemakaian: 'subdomain' => ['required', new AvailableSubdomain]
 */
class AvailableSubdomain implements ValidationRule
{
    public function __construct(private ?int $ignoreSchoolId = null) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $v = Subdomain::normalize((string) $value);

        if (! Subdomain::isValidFormat($v)) {
            $fail('Link portal hanya boleh huruf kecil, angka, dan tanda hubung (3–63 karakter), tidak diawali/diakhiri tanda hubung.');

            return;
        }

        if (Subdomain::isReserved($v)) {
            $fail("Link portal \"$v\" dicadangkan sistem. Pilih link lain.");

            return;
        }

        $taken = School::query()->where('subdomain', $v)
            ->when($this->ignoreSchoolId, fn ($q, $id) => $q->whereKeyNot($id))
            ->exists();

        if ($taken) {
            $fail("Link portal \"$v\" sudah dipakai sekolah lain.");

            return;
        }

        // Cegah alamat tiruan yang hanya beda tanda hubung atau huruf/angka mirip (phishing).
        $canonical = Subdomain::canonical($v);
        $lookalike = School::query()
            ->when($this->ignoreSchoolId, fn ($q, $id) => $q->whereKeyNot($id))
            ->pluck('subdomain')
            ->first(fn ($s) => Subdomain::canonical($s) === $canonical);

        if ($lookalike) {
            $fail("Link portal \"$v\" terlalu mirip dengan sekolah yang sudah terdaftar. Pilih link yang jelas berbeda.");
        }

        // Nama cadangan yang disamarkan (mis. adm1n, c-panel) juga ditolak.
        foreach (config('tenancy.reserved_subdomains', []) as $r) {
            if (Subdomain::canonical($r) === $canonical) {
                $fail("Link portal \"$v\" dicadangkan sistem. Pilih link lain.");

                return;
            }
        }
    }
}
