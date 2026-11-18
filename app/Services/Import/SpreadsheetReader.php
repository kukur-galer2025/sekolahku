<?php

namespace App\Services\Import;

use DateInterval;
use DateTimeInterface;
use OpenSpout\Reader\CSV\Options as CsvOptions;
use OpenSpout\Reader\CSV\Reader as CsvReader;
use OpenSpout\Reader\XLSX\Reader as XlsxReader;
use RuntimeException;

/**
 * Membaca .xlsx / .csv (dan PDF jadwal aSc, lihat TimetablePdfReader) menjadi [nama sheet => baris[] => sel[]].
 * Sel berupa string yang sudah di-trim, atau DateTimeInterface/DateInterval untuk tanggal & jam.
 */
class SpreadsheetReader
{
    public const MAX_ROWS = 20000;

    /** @return array<string, list<list<string|DateTimeInterface|DateInterval>>> */
    public function read(string $path, string $extension): array
    {
        $ext = strtolower($extension);

        if ($ext === 'pdf') {
            return (new TimetablePdfReader)->read($path); // jadwal cetakan aSc Timetables
        }
        if ($ext === 'csv') {
            $reader = new CsvReader($this->csvOptions($path));
        } elseif ($ext === 'xlsx') {
            $reader = new XlsxReader;
        } else {
            throw new RuntimeException('Format file tidak didukung. Simpan ulang sebagai .xlsx atau CSV.');
        }

        $reader->open($path);
        $sheets = [];
        $count = 0;

        try {
            foreach ($reader->getSheetIterator() as $sheet) {
                $rows = [];
                foreach ($sheet->getRowIterator() as $row) {
                    if (++$count > self::MAX_ROWS) {
                        throw new RuntimeException('File terlalu besar (lebih dari '.self::MAX_ROWS.' baris).');
                    }
                    $rows[] = array_map(fn ($v) => $this->cell($v), $row->toArray());
                }
                $sheets[$sheet->getName()] = $rows;
            }
        } finally {
            $reader->close();
        }

        return $sheets;
    }

    private function cell(mixed $v): string|DateTimeInterface|DateInterval
    {
        if ($v instanceof DateTimeInterface || $v instanceof DateInterval) {
            return $v;
        }
        if (is_float($v) && floor($v) === $v) {
            $v = (int) $v; // 1001.0 → "1001"
        }

        return trim(preg_replace('/\s+/u', ' ', (string) $v));
    }

    /** CSV dari Excel Indonesia sering memakai titik koma. */
    private function csvOptions(string $path): CsvOptions
    {
        $o = new CsvOptions;
        $first = (string) fgets(fopen($path, 'r'));
        $o->FIELD_DELIMITER = substr_count($first, ';') > substr_count($first, ',') ? ';' : ',';

        return $o;
    }
}
