<?php

namespace App\Services\Import;

use App\Models\LessonPeriod;

/**
 * Jam KBM dari Excel (sama dengan prototipe): kolom Sesi/Jam ke | Mulai | Selesai | Keterangan.
 * Baris berlabel/berketerangan "Istirahat" menjadi jam istirahat. Mengganti seluruh tabel Jam KBM.
 */
class LessonPeriodImport
{
    use ImportHelpers;

    private const REQUIRED = ['start' => '/^(MULAI|JAM MULAI|WAKTU MULAI|START|DARI)$/', 'end' => '/^(SELESAI|JAM SELESAI|WAKTU SELESAI|END|SAMPAI)$/'];

    private const OPTIONAL = ['label' => '/^(SESI|JAM KE-?|JAM|NO\.?|URUTAN|JENIS)$/', 'kind' => '/^(ISTIRAHAT|JENIS WAKTU|KETERANGAN|KET\.?)$/'];

    public function rows(array $sheets): array
    {
        $out = ['rows' => [], 'errors' => [], 'warnings' => []];

        foreach ($sheets as $rows) {
            if (! $h = $this->findHeader($rows, self::REQUIRED, self::OPTIONAL)) {
                continue;
            }
            [$hi, $c] = $h;
            $n = 0;
            foreach (array_slice($rows, $hi + 1) as $i => $row) {
                $start = $this->time($row[$c['start']] ?? null);
                $end = $this->time($row[$c['end']] ?? null);
                if (! $start || ! $end) {
                    continue;
                }
                $label = $this->str($row, $c['label'] ?? null);
                $isBreak = (bool) preg_match('/istirahat|break|ishoma/i', $label.' '.$this->str($row, $c['kind'] ?? null));
                $n += $isBreak ? 0 : 1;
                $out['rows'][] = [
                    'label' => $isBreak ? 'Istirahat' : (preg_replace('/^jam\s*(ke[- ]?)?/i', '', $label) ?: (string) $n),
                    'starts_at' => $start, 'ends_at' => $end, 'is_break' => $isBreak,
                ];
            }
            break;
        }

        foreach ($out['rows'] as $i => $r) {
            if ($r['starts_at'] >= $r['ends_at']) {
                $out['errors'][] = 'Baris ke-'.($i + 1).": jam selesai ({$r['ends_at']}) harus setelah jam mulai ({$r['starts_at']}).";
            }
            if ($i > 0 && $r['starts_at'] < $out['rows'][$i - 1]['ends_at']) {
                $out['errors'][] = 'Baris ke-'.($i + 1).": bertabrakan dengan sesi sebelumnya ({$out['rows'][$i - 1]['ends_at']}).";
            }
        }

        if (! $out['rows'] && ! $out['errors']) {
            $out['errors'][] = 'Kolom Mulai dan Selesai tidak ditemukan atau tidak ada baris jam yang valid.';
        }

        return $out;
    }

    public function preview(array $sheets): array
    {
        $r = $this->rows($sheets);
        $rows = collect($r['rows']);

        return [
            'ok' => $r['errors'] === [],
            'errors' => $r['errors'],
            'warnings' => array_merge($r['warnings'], ['Tabel Jam KBM yang aktif sekarang ('.LessonPeriod::count().' sesi) akan diganti seluruhnya. Batas presensi & jendela scan mengikuti tabel baru.']),
            'stats' => ['Jam pelajaran' => $rows->where('is_break', false)->count(), 'Istirahat' => $rows->where('is_break', true)->count()],
            'columns' => ['Sesi', 'Mulai', 'Selesai'],
            'sample' => $rows->map(fn ($x) => [$x['is_break'] ? 'Istirahat' : 'Jam ke-'.$x['label'], $x['starts_at'], $x['ends_at']])->all(),
        ];
    }

    public function apply(array $sheets): array
    {
        $rows = $this->rows($sheets)['rows'];
        LessonPeriod::query()->delete();
        foreach ($rows as $i => $r) {
            LessonPeriod::create($r + ['sort' => $i + 1]);
        }

        return ['result' => ['Sesi Jam KBM' => count($rows)], 'credentials' => []];
    }
}
