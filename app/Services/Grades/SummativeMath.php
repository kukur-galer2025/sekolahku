<?php

namespace App\Services\Grades;

/**
 * Rumus Daftar Nilai Sumatif (sesuai format sekolah):
 *  - NA Sumatif (S)  = rerata nilai Sumatif Lingkup Materi yang terisi (LM 1 … LM n)
 *  - NA STS          = rerata Non Tes & Tes STS yang terisi
 *  - NA SAS          = rerata Non Tes & Tes SAS yang terisi
 *  - Nilai Rapor     = rerata (S, STS, SAS) yang tersedia, dibulatkan ke bilangan bulat terdekat
 * "complete" = semua kolom terisi. Fungsi yang sama dipakai di PWA (daftar-nilai.js) agar hasil identik.
 */
class SummativeMath
{
    /** @param array{lm?: array, sts_nontes?: ?float, sts_tes?: ?float, sas_nontes?: ?float, sas_tes?: ?float} $e */
    public static function compute(array $e, int $lmCount): array
    {
        $lm = array_slice(array_pad(array_values((array) ($e['lm'] ?? [])), $lmCount, null), 0, $lmCount);
        $s = self::avg($lm);
        $sts = self::avg([$e['sts_nontes'] ?? null, $e['sts_tes'] ?? null]);
        $sas = self::avg([$e['sas_nontes'] ?? null, $e['sas_tes'] ?? null]);
        $parts = array_filter([$s, $sts, $sas], fn ($v) => $v !== null);
        $rapor = $parts ? (int) round(array_sum($parts) / count($parts), 0, PHP_ROUND_HALF_UP) : null;
        $filled = count(array_filter($lm, fn ($v) => $v !== null))
            + count(array_filter([$e['sts_nontes'] ?? null, $e['sts_tes'] ?? null, $e['sas_nontes'] ?? null, $e['sas_tes'] ?? null], fn ($v) => $v !== null));

        return [
            'na_s' => $s === null ? null : round($s, 2),
            'na_sts' => $sts === null ? null : round($sts, 2),
            'na_sas' => $sas === null ? null : round($sas, 2),
            'rapor' => $rapor,
            'complete' => $filled === $lmCount + 4,
        ];
    }

    private static function avg(array $v): ?float
    {
        $v = array_values(array_filter($v, fn ($x) => $x !== null && $x !== ''));

        return $v ? array_sum(array_map('floatval', $v)) / count($v) : null;
    }
}
