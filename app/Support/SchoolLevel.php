<?php

namespace App\Support;

/**
 * Jenjang sekolah dan tingkat kelasnya.
 *   sd  → SD/MI       : 1–6
 *   smp → SMP/MTs     : 7–9
 *   sma → SMA/SMK/MA  : X–XII
 */
class SchoolLevel
{
    public const LABELS = ['sd' => 'SD / MI', 'smp' => 'SMP / MTs', 'sma' => 'SMA / SMK / MA'];

    private const ROMAN = ['I' => 1, 'II' => 2, 'III' => 3, 'IV' => 4, 'V' => 5, 'VI' => 6, 'VII' => 7, 'VIII' => 8, 'IX' => 9, 'X' => 10, 'XI' => 11, 'XII' => 12];

    /** Tebak jenjang dari nama sekolah ("SMP Negeri 1 ..." → smp). Bawaan: sma. */
    public static function guess(?string $schoolName): string
    {
        $n = ' '.strtoupper((string) $schoolName).' ';
        if (preg_match('/[^A-Z](SD|SDN|SDIT|MI|MIN|MIS)[^A-Z]/', $n)) {
            return 'sd';
        }
        if (preg_match('/[^A-Z](SMP|SMPN|SMPIT|MTS|MTSN)[^A-Z]/', $n)) {
            return 'smp';
        }

        return 'sma';
    }

    public static function normalize(?string $level, ?string $schoolName = null): string
    {
        return array_key_exists((string) $level, self::LABELS) ? $level : self::guess($schoolName);
    }

    /** @return list<string> */
    public static function grades(string $level): array
    {
        return match ($level) {
            'sd' => ['1', '2', '3', '4', '5', '6'],
            'smp' => ['7', '8', '9'],
            default => ['X', 'XI', 'XII'],
        };
    }

    /**
     * Tingkat dari nama kelas: "7A", "VII-B", "VIII C" → 7/8 (SMP); "X-FKK 1", "10 IPA 2", "XII" → X/XII (SMA).
     * Null bila tidak cocok dengan tingkat jenjang ini.
     */
    public static function gradeOf(string $className, string $level): ?string
    {
        $head = strtoupper(trim($className));
        $head = preg_replace('/^KELAS\s+/', '', $head);
        $num = null;
        if (preg_match('/^(XII|XI|X|IX|VIII|VII|VI|V|IV|III|II|I)(?=$|[^A-Z]|[A-Z](?:$|[^A-Z]))/', $head, $m)) {
            $num = self::ROMAN[$m[1]];
        } elseif (preg_match('/^(1[0-2]|[1-9])(?!\d)/', $head, $m)) {
            $num = (int) $m[1];
        }
        if ($num === null) {
            return null;
        }

        $grade = $level === 'sma' ? array_search($num, self::ROMAN, true) : (string) $num;

        return $grade !== false && in_array($grade, self::grades($level), true) ? $grade : null;
    }

    /** Penanda rombel tanpa tingkat: "7A" → "A", "VIII-B" → "B", "X-TKJ 1" → "TKJ1", "Kelas 9 C" → "C". */
    public static function suffixOf(string $className): string
    {
        $head = strtoupper(trim($className));
        $head = preg_replace('/^KELAS\s+/', '', $head);
        $head = preg_replace('/^(XII|XI|X|IX|VIII|VII|VI|V|IV|III|II|I)(?=$|[^A-Z]|[A-Z](?:$|[^A-Z]))/', '', $head, 1, $n);
        if (! $n) {
            $head = preg_replace('/^(1[0-2]|[1-9])(?!\d)/', '', $head, 1);
        }

        return preg_replace('/[^A-Z0-9]/', '', $head);
    }

    /** Tingkat berikutnya, atau null bila sudah tingkat akhir (lulus). */
    public static function nextGrade(string $grade, string $level): ?string
    {
        $g = self::grades($level);
        $i = array_search($grade, $g, true);

        return $i === false ? null : ($g[$i + 1] ?? null);
    }

    /** Tahun ajaran pada tanggal tertentu: Juli–Desember 2026 → 2026/2027, Januari–Juni 2027 → 2026/2027. */
    public static function academicYear(\DateTimeInterface $date): string
    {
        $y = (int) $date->format('Y');
        $start = (int) $date->format('n') >= 7 ? $y : $y - 1;

        return $start.'/'.($start + 1);
    }
}
