<?php

namespace App\Services\Import;

use Carbon\CarbonImmutable;
use DateInterval;
use DateTimeInterface;
use Illuminate\Support\Facades\Hash;

/** Bantuan bersama para importer. */
trait ImportHelpers
{
    private const PASSWORD_ALPHABET = 'abcdefghjkmnpqrstuvwxyz23456789'; // tanpa 0/o/1/l/i

    /** Cari baris header: baris pertama yang memuat semua pola wajib. @return array{0:int,1:array<string,int>}|null */
    protected function findHeader(array $rows, array $required, array $optional = [], int $scan = 15): ?array
    {
        foreach (array_slice($rows, 0, $scan, true) as $i => $row) {
            $cols = [];
            foreach ($row as $j => $cell) {
                $h = is_string($cell) ? strtoupper(trim($cell)) : '';
                foreach ($required + $optional as $key => $re) {
                    if (! isset($cols[$key]) && $h !== '' && preg_match($re, $h)) {
                        $cols[$key] = $j;
                    }
                }
            }
            if (count(array_intersect_key($cols, $required)) === count($required)) {
                return [$i, $cols];
            }
        }

        return null;
    }

    protected function str(array $row, ?int $col): string
    {
        if ($col === null || ! array_key_exists($col, $row)) {
            return '';
        }
        $v = $row[$col];

        return $v instanceof DateTimeInterface ? $v->format('Y-m-d') : (is_string($v) ? trim($v) : '');
    }

    /** Nama tanpa gelar depan (Apt., Ns., Dr., Drs., H., Hj., Ir., Mr., Mrs., Prof.) dan tanpa gelar belakang (setelah koma). */
    protected function bareName(string $v): string
    {
        $v = trim(explode(',', $v)[0]);
        do {
            $before = $v;
            $v = preg_replace('/^(apt|ns|dr|drs|dra|hj|h|ir|mr|mrs|ms|prof|bpk|bu|ibu|pak)(\.\s*|\s+)(?=\p{L})/iu', '', $v);
        } while ($v !== $before);

        return $v;
    }

    /** Kunci pencocokan nama orang tanpa gelar & tanda baca: "Apt.Erlin Nur Azizah, S.Farm." → "erlinnurazizah". */
    protected function personKey(string $v): string
    {
        return preg_replace('/[^a-z0-9]/', '', strtolower($this->bareName($v)));
    }

    /** Nama kelas rapi: "x - fkk 1" → "X-FKK 1". */
    protected function className(string $v): string
    {
        $v = strtoupper(trim(preg_replace('/\s+/', ' ', $v)));

        return trim(preg_replace('/\s*-\s*/', '-', $v), '- ');
    }

    /** Kunci pencocokan kelas: abaikan spasi & tanda hubung. */
    protected function classKey(string $v): string
    {
        return preg_replace('/[\s-]+/', '', strtoupper($v));
    }

    protected function date(mixed $v): ?string
    {
        if ($v instanceof DateTimeInterface) {
            return $v->format('Y-m-d');
        }
        $v = trim((string) $v);
        if ($v === '') {
            return null;
        }
        if (is_numeric($v) && (float) $v > 20000 && (float) $v < 80000) { // nomor seri tanggal Excel
            return CarbonImmutable::create(1899, 12, 30)->addDays((int) $v)->toDateString();
        }
        foreach (['Y-m-d', 'd/m/Y', 'd-m-Y', 'd.m.Y', 'j/n/Y', 'j-n-Y'] as $f) {
            try {
                $d = CarbonImmutable::createFromFormat('!'.$f, $v);
            } catch (\Throwable) {
                continue;
            }
            if ($d && $d->format($f) === $v && $d->year > 1950 && $d->year < 2030) {
                return $d->toDateString();
            }
        }

        return null;
    }

    protected function time(mixed $v): ?string
    {
        if ($v instanceof DateTimeInterface) {
            return $v->format('H:i');
        }
        if ($v instanceof DateInterval) {
            return sprintf('%02d:%02d', $v->h + $v->d * 24, $v->i);
        }
        $v = trim((string) $v);
        if (is_numeric($v) && (float) $v >= 0 && (float) $v < 1) {
            $m = (int) round((float) $v * 1440);

            return sprintf('%02d:%02d', intdiv($m, 60), $m % 60);
        }
        if (preg_match('/^(\d{1,2})[:.](\d{2})/', $v, $m) && (int) $m[1] < 24 && (int) $m[2] < 60) {
            return sprintf('%02d:%02d', $m[1], $m[2]);
        }

        return null;
    }

    protected function randomPassword(int $length = 8): string
    {
        $out = '';
        for ($i = 0; $i < $length; $i++) {
            $out .= self::PASSWORD_ALPHABET[random_int(0, strlen(self::PASSWORD_ALPHABET) - 1)];
        }

        return $out;
    }

    /**
     * Hash password awal dengan cost lebih rendah agar impor ribuan akun tidak memakan waktu
     * berjam-jam. Aman karena wajib diganti saat login pertama (lalu di-hash ulang dengan cost penuh).
     */
    protected function hashInitial(string $password): string
    {
        return Hash::make($password, ['rounds' => min(10, (int) config('hashing.bcrypt.rounds', 12))]);
    }
}
