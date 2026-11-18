<?php

namespace App\Services\Finance;

use App\Models\BankMutation;
use App\Models\Invoice;
use App\Services\Billing\BillingService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Impor mutasi rekening (CSV / XLSX hasil unduhan internet banking bisnis: BNIdirect, KlikBCA Bisnis, Kopra, QLola, dll.).
 * Format tiap bank berbeda, jadi kolom dikenali dari judul kolom:
 *   tanggal  : tanggal | tgl | date | posting | transaction date
 *   uraian   : keterangan | uraian | deskripsi | description | remark | berita | narasi
 *   kredit   : kredit | credit | cr | masuk   (kolom terpisah)
 *   debit    : debit | debet | db | keluar
 *   nominal  : nominal | jumlah | amount | mutasi  + kolom jenis D/K, DB/CR (bila tidak ada kolom kredit/debit terpisah)
 * Setiap baris disimpan sekali (hash tanggal+uraian+nominal+arah+urutan kembar), lalu kredit dicarikan invoice yang cocok.
 */
class MutationImporter
{
    private const H_DATE = ['tanggal', 'tgl', 'date', 'posting date', 'transaction date', 'tanggal transaksi', 'tgl. transaksi', 'post date', 'trx date'];
    private const H_DESC = ['keterangan', 'uraian', 'deskripsi', 'description', 'remark', 'remarks', 'berita', 'narasi', 'uraian transaksi', 'transaction description', 'detail'];
    private const H_CR = ['kredit', 'credit', 'cr', 'masuk', 'credit amount', 'mutasi kredit', 'kredit (idr)'];
    private const H_DB = ['debit', 'debet', 'db', 'keluar', 'debit amount', 'mutasi debet', 'mutasi debit', 'debet (idr)', 'debit (idr)'];
    private const H_AMT = ['nominal', 'jumlah', 'amount', 'mutasi', 'amount (idr)', 'nilai'];
    private const H_TYPE = ['d/k', 'db/cr', 'dk', 'jenis', 'type', 'cr/db', 'd/c', 'tipe'];

    /** @return array{added:int, skipped:int, matched_suggestions:int} */
    public function import(string $path, string $ext, string $batch): array
    {
        $rows = $ext === 'csv' ? $this->readCsv($path) : $this->readXlsx($path);
        [$head, $map] = $this->findHeader($rows);
        if ($map === null) {
            throw ValidationException::withMessages(['file' => 'Kolom mutasi tidak dikenali. Pastikan file punya judul kolom Tanggal, Keterangan, dan Kredit/Debit (atau Nominal + D/K).']);
        }

        $added = $skipped = $suggested = 0;
        $seen = [];
        foreach (array_slice($rows, $head + 1) as $r) {
            $date = $this->date($r[$map['date']] ?? null);
            $desc = trim(preg_replace('/\s+/', ' ', (string) ($r[$map['desc']] ?? '')));
            [$dir, $amount] = $this->amount($r, $map);
            if (! $date || $amount <= 0) {
                continue; // baris saldo awal/akhir, subtotal, atau kosong
            }
            $base = $date->toDateString().'|'.mb_strtolower($desc).'|'.$amount.'|'.$dir;
            $n = $seen[$base] = ($seen[$base] ?? 0) + 1; // transaksi kembar di hari yang sama tetap dihitung terpisah
            $hash = hash('sha256', $base.'|'.$n);
            if (BankMutation::where('hash', $hash)->exists()) {
                $skipped++;

                continue;
            }
            [$inv, $why] = $dir === 'in' ? $this->suggest($desc, $amount) : [null, null];
            BankMutation::create(['hash' => $hash, 'trx_date' => $date->toDateString(), 'description' => mb_substr($desc ?: '-', 0, 255), 'direction' => $dir,
                'amount' => $amount, 'status' => 'pending', 'suggested_invoice_id' => $inv?->id, 'suggest_reason' => $why, 'batch' => mb_substr($batch, 0, 40)]);
            $added++;
            $suggested += $inv ? 1 : 0;
        }

        return ['added' => $added, 'skipped' => $skipped, 'matched_suggestions' => $suggested];
    }

    /**
     * Cari invoice untuk sebuah kredit:
     *  1) nomor invoice tertulis di berita transfer (INV/2026/11/00001, INV2026110001, 2026/11/00001),
     *  2) nominal = sisa tagihan tepat SATU invoice terbuka (kode unik membuat nominal berbeda-beda),
     *  3) nominal = total tepat satu invoice terbuka yang belum dibayar sama sekali.
     * @return array{0:?Invoice,1:?string}
     */
    public function suggest(string $desc, int $amount): array
    {
        $open = BillingService::invoices()->where('status', 'unpaid')->get(['id', 'number', 'total', 'paid_amount', 'school_id', 'status']);
        $flat = strtoupper(preg_replace('/[^0-9A-Za-z]/', '', $desc));
        foreach ($open as $i) {
            $num = strtoupper(preg_replace('/[^0-9A-Za-z]/', '', $i->number));        // INV20261100001
            if ($flat !== '' && (str_contains($flat, $num) || str_contains($flat, substr($num, 3)))) {
                return [$i, 'nomor invoice di berita transfer'];
            }
        }
        $byRest = $open->filter(fn ($i) => $i->remaining() === $amount);
        if ($byRest->count() === 1) {
            return [$byRest->first(), 'nominal = sisa tagihan'];
        }

        return [null, $byRest->count() > 1 ? 'nominal sama dengan '.$byRest->count().' tagihan' : null];
    }

    // ── pembacaan file ──

    private function readCsv(string $path): array
    {
        $raw = (string) file_get_contents($path);
        $raw = preg_replace('/^\xEF\xBB\xBF/', '', $raw);
        if (! mb_check_encoding($raw, 'UTF-8')) {
            $raw = mb_convert_encoding($raw, 'UTF-8', 'Windows-1252');
        }
        $lines = preg_split('/\r\n|\r|\n/', $raw);
        $sample = implode("\n", array_slice($lines, 0, 15));
        $delim = collect([';' => substr_count($sample, ';'), ',' => substr_count($sample, ','), "\t" => substr_count($sample, "\t"), '|' => substr_count($sample, '|')])->sortDesc()->keys()->first();

        return array_values(array_filter(array_map(fn ($l) => str_getcsv($l, $delim), $lines), fn ($r) => count(array_filter($r, fn ($v) => trim((string) $v) !== '')) > 0));
    }

    private function readXlsx(string $path): array
    {
        $reader = new \OpenSpout\Reader\XLSX\Reader;
        try {
            $reader->open($path);
        } catch (\Throwable) {
            throw ValidationException::withMessages(['file' => 'File Excel tidak bisa dibaca. Simpan ulang sebagai .xlsx atau unduh mutasi dalam format CSV.']);
        }
        $rows = [];
        foreach ($reader->getSheetIterator() as $sheet) {
            foreach ($sheet->getRowIterator() as $row) {
                $rows[] = array_map(fn ($c) => $c instanceof \DateTimeInterface ? $c->format('Y-m-d') : $c, $row->toArray());
            }
            break;
        }
        $reader->close();

        return $rows;
    }

    /** @return array{0:int, 1:?array} indeks baris judul & peta kolom */
    private function findHeader(array $rows): array
    {
        foreach (array_slice($rows, 0, 40, true) as $idx => $r) {
            $norm = array_map(fn ($v) => trim(mb_strtolower(preg_replace('/\s+/', ' ', (string) $v))), $r);
            $find = function (array $names) use ($norm) {
                foreach ($norm as $k => $v) {
                    if ($v !== '' && in_array($v, $names, true)) {
                        return $k;
                    }
                }
                foreach ($norm as $k => $v) { // judul panjang: "Tanggal Transaksi (WIB)", "Kredit (Rp)"
                    foreach ($names as $n) {
                        if ($v !== '' && mb_strlen($n) > 2 && Str::startsWith($v, $n)) {
                            return $k;
                        }
                    }
                }

                return null;
            };
            $map = ['date' => $find(self::H_DATE), 'desc' => $find(self::H_DESC), 'cr' => $find(self::H_CR), 'db' => $find(self::H_DB), 'amt' => $find(self::H_AMT), 'type' => $find(self::H_TYPE)];
            if ($map['date'] !== null && $map['desc'] !== null && ($map['cr'] !== null || $map['amt'] !== null)) {
                return [$idx, $map];
            }
        }

        return [0, null];
    }

    /** @return array{0:string,1:int} [in|out, nominal] */
    private function amount(array $r, array $map): array
    {
        if ($map['cr'] !== null) {
            $cr = $this->num($r[$map['cr']] ?? 0);
            $db = $map['db'] !== null ? $this->num($r[$map['db']] ?? 0) : 0;
            if ($cr > 0) {
                return ['in', $cr];
            }

            return ['out', $db];
        }
        $raw = (string) ($r[$map['amt']] ?? '');
        $amt = $this->num($raw);
        $type = strtoupper(trim((string) ($map['type'] !== null ? ($r[$map['type']] ?? '') : '')));
        // "1.500.000,00 CR" / "DB" di kolom nominal, atau kolom jenis D/K terpisah, atau tanda minus.
        $flag = $type !== '' ? $type : strtoupper($raw);
        $out = str_contains($flag, 'DB') || str_contains($flag, 'DEBIT') || str_contains($flag, 'DEBET') || $flag === 'D' || str_starts_with(trim($raw), '-');

        return [$out ? 'out' : 'in', $amt];
    }

    /** "1.500.000,00" | "1,500,000.00" | 1500000 | "Rp 1.500.000" → 1500000 (rupiah, sen dibuang). */
    private function num(mixed $v): int
    {
        if (is_int($v) || is_float($v)) {
            return (int) round(abs($v));
        }
        $s = preg_replace('/[^0-9.,]/', '', (string) $v);
        if ($s === '') {
            return 0;
        }
        // Pemisah desimal = tanda terakhir bila diikuti tepat 1–2 digit.
        if (preg_match('/[.,](\d{1,2})$/', $s)) {
            $s = substr($s, 0, (int) strrpos(str_replace(',', '.', $s), '.'));
        }

        return (int) preg_replace('/\D/', '', $s);
    }

    private function date(mixed $v): ?Carbon
    {
        if ($v === null || $v === '') {
            return null;
        }
        if (is_numeric($v) && (float) $v > 20000 && (float) $v < 80000) {
            return Carbon::create(1899, 12, 30)->addDays((int) $v);
        }
        $s = trim((string) $v);
        $s = preg_replace('/\s+\d{1,2}[:.]\d{2}([:.]\d{2})?.*$/', '', $s); // buang jam
        foreach (['Y-m-d', 'd/m/Y', 'd-m-Y', 'd/m/y', 'd-m-y', 'd.m.Y', 'Y/m/d', 'd M Y', 'd-M-Y', 'd-M-y', 'd M y'] as $fmt) {
            try {
                $d = Carbon::createFromFormat('!'.$fmt, $s);
                if ($d && $d->year > 2000 && $d->year < 2100) {
                    return $d;
                }
            } catch (\Throwable) {
            }
        }
        // Nama bulan Indonesia: "15 Nov 2026", "15 Agustus 2026"
        $id = ['januari' => 'jan', 'februari' => 'feb', 'maret' => 'mar', 'april' => 'apr', 'mei' => 'may', 'juni' => 'jun', 'juli' => 'jul', 'agustus' => 'aug', 'agu' => 'aug', 'agt' => 'aug',
            'september' => 'sep', 'oktober' => 'oct', 'okt' => 'oct', 'november' => 'nov', 'nop' => 'nov', 'desember' => 'dec', 'des' => 'dec'];
        $en = strtr(mb_strtolower($s), $id);
        try {
            $d = Carbon::parse($en);

            return $d->year > 2000 && $d->year < 2100 ? $d->startOfDay() : null;
        } catch (\Throwable) {
            return null;
        }
    }
}
