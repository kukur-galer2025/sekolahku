<?php

namespace App\Support;

use Symfony\Component\HttpFoundation\StreamedResponse;

/** Unduhan CSV yang aman dibuka di Excel: BOM UTF-8, pemisah titik koma, dan sel berawalan = + - @ dinetralkan. */
class Csv
{
    public static function cell(mixed $v): string
    {
        $v = (string) ($v ?? '');

        return $v !== '' && in_array($v[0], ['=', '+', '-', '@', "\t", "\r"], true) ? "'".$v : $v;
    }

    /** @param iterable<array> $rows */
    public static function download(string $filename, array $header, iterable $rows): StreamedResponse
    {
        return response()->streamDownload(function () use ($header, $rows) {
            $out = fopen('php://output', 'wb');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, $header, ';');
            foreach ($rows as $r) {
                fputcsv($out, array_map([self::class, 'cell'], $r), ';');
            }
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
