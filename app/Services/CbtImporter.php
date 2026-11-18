<?php

namespace App\Services;

use App\Services\Import\SpreadsheetReader;
use Illuminate\Validation\ValidationException;

/**
 * Impor soal CBT dari Excel/CSV ke paket guru. Format utama: database/data/FORMAT_CBT.xlsx
 * (satu baris satu soal, PG & essay). Format lama Q/A tetap dibaca.
 */
class CbtImporter
{
    public const MAX_QUESTIONS = 300;

    /**
     * Dua format diterima:
     *  1) FORMAT_CBT.xlsx baru (satu baris = satu soal): No | Jenis (PG/ESSAY) | Pertanyaan | Opsi A–E | Kunci | Skor | Pedoman.
     *     Kolom dikenali dari judulnya, jadi urutan kolom boleh bergeser. Sheet "Petunjuk" & "Contoh" diabaikan.
     *  2) Format lama: baris soal bertanda "Q", lalu baris jawaban bertanda "A", jawaban benar bernilai 1.
     *
     * @return array{questions:list<array{type:string,body:string,options:list<string>,answer:?int,score:float,guide:?string}>,warnings:list<string>}
     */
    public function parse(string $path, string $ext): array
    {
        $questions = [];
        $warnings = [];
        foreach (app(SpreadsheetReader::class)->read($path, $ext) as $sheet => $rows) {
            if (in_array(mb_strtolower(trim((string) $sheet)), ['petunjuk', 'contoh', 'panduan'], true)) {
                continue;
            }
            $rows = array_map(fn ($row) => array_map(fn ($v) => is_string($v) ? trim($v) : '', $row), $rows);
            $head = $this->findHeader($rows);
            if ($head) {
                $this->parseTable($rows, $head, (string) $sheet, $questions, $warnings);
            } else {
                $this->parseMarked($rows, (string) $sheet, $questions, $warnings);
            }
        }

        $out = [];
        foreach ($questions as $n => $q) {
            if ($q['type'] === 'essay') {
                $out[] = $q;

                continue;
            }
            if (count($q['options']) < 2) {
                $warnings[] = 'Soal '.($n + 1).' dilewati: jawaban kurang dari 2.';

                continue;
            }
            if ($q['answer'] === null) {
                $warnings[] = 'Soal '.($n + 1).' belum punya kunci (jawaban bernilai 1).';
            }
            $out[] = $q;
        }
        if (! $out) {
            throw ValidationException::withMessages(['file' => 'Tidak ada soal yang terbaca. Gunakan FORMAT_CBT.xlsx: isi sheet "Soal", satu baris satu soal (Jenis, Pertanyaan, Opsi A–E, Kunci).']);
        }
        if (count($out) > self::MAX_QUESTIONS) {
            throw ValidationException::withMessages(['file' => 'Maksimal '.self::MAX_QUESTIONS.' soal per bank soal.']);
        }

        return ['questions' => $out, 'warnings' => array_slice($warnings, 0, 30)];
    }

    /** Salinan FORMAT_CBT.xlsx (dropdown, petunjuk, contoh) untuk diunduh; berkas asli tidak ikut terhapus. */
    public function template(): string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'cbt').'.xlsx';
        copy(database_path('data/FORMAT_CBT.xlsx'), $tmp);

        return $tmp;
    }

    /** Baris judul format baru: harus ada kolom Pertanyaan/Soal dan Kunci. @return array<string,int>|null */
    private function findHeader(array $rows): ?array
    {
        foreach (array_slice($rows, 0, 15, true) as $i => $cells) {
            $map = [];
            foreach ($cells as $j => $v) {
                $k = mb_strtolower(preg_replace('/[^a-z ]/i', '', $v));
                $k = trim(preg_replace('/\s+/', ' ', $k));
                $key = match (true) {
                    $k === 'no' || $k === 'nomor' => 'no',
                    str_starts_with($k, 'jenis') || $k === 'tipe' => 'type',
                    str_starts_with($k, 'pertanyaan') || $k === 'soal' || str_starts_with($k, 'butir soal') => 'body',
                    (bool) preg_match('/^(opsi|pilihan|jawaban) ([a-e])$/', $k, $m) => 'opt_'.$m[2],
                    str_starts_with($k, 'kunci') => 'key',
                    str_starts_with($k, 'skor') || str_starts_with($k, 'bobot') => 'score',
                    str_starts_with($k, 'pedoman') || str_starts_with($k, 'rubrik') => 'guide',
                    default => null,
                };
                if ($key && ! isset($map[$key])) {
                    $map[$key] = $j;
                }
            }
            if (isset($map['body'], $map['key'])) {
                return $map + ['_row' => $i];
            }
        }

        return null;
    }

    private function parseTable(array $rows, array $h, string $sheet, array &$questions, array &$warnings): void
    {
        foreach ($rows as $i => $c) {
            if ($i <= $h['_row']) {
                continue;
            }
            $get = fn (string $k) => isset($h[$k]) ? ($c[$h[$k]] ?? '') : '';
            $body = $get('body');
            if ($body === '') {
                continue; // baris bernomor yang belum diisi
            }
            $line = "Sheet $sheet baris ".($i + 1);
            $t = mb_strtolower($get('type'));
            $opts = [];
            $letters = [];
            foreach (['a', 'b', 'c', 'd', 'e'] as $l) {
                if (($o = $get('opt_'.$l)) !== '') {
                    $letters[strtoupper($l)] = count($opts);
                    $opts[] = mb_substr($o, 0, 1000);
                }
            }
            $essay = str_contains($t, 'essay') || str_contains($t, 'esai') || str_contains($t, 'uraian') || ($t === '' && ! $opts);
            $score = str_replace(',', '.', $get('score'));
            $score = is_numeric($score) ? max(0, min(100, (float) $score)) : ($essay ? 5.0 : 1.0);
            if ($essay) {
                $questions[] = ['type' => 'essay', 'body' => mb_substr($body, 0, 4000), 'options' => [], 'answer' => null, 'score' => $score,
                    'guide' => mb_substr($get('guide'), 0, 3000) ?: null];

                continue;
            }
            $key = strtoupper(substr(preg_replace('/[^a-e]/i', '', $get('key')), 0, 1));
            $answer = $key !== '' ? ($letters[$key] ?? null) : null;
            if ($key !== '' && $answer === null) {
                $warnings[] = "$line: kunci $key menunjuk opsi yang kosong.";
            }
            $questions[] = ['type' => 'pg', 'body' => mb_substr($body, 0, 4000), 'options' => $opts, 'answer' => $answer, 'score' => $score, 'guide' => null];
        }
    }

    /** Format lama Q/A. */
    private function parseMarked(array $rows, string $sheet, array &$questions, array &$warnings): void
    {
        $q = null;
        foreach ($rows as $i => $cells) {
            $mark = null;
            $mi = null;
            foreach ($cells as $j => $v) {
                if (in_array(strtoupper($v), ['Q', 'A'], true)) {
                    [$mark, $mi] = [strtoupper($v), $j];
                    break;
                }
            }
            if (! $mark) {
                continue;
            }
            $rest = array_values(array_filter(array_slice($cells, $mi + 1, null, true), fn ($v) => $v !== ''));
            $text = collect($rest)->first(fn ($v) => ! in_array($v, ['0', '1'], true)) ?? '';
            if ($text === '') {
                $warnings[] = "Sheet $sheet baris ".($i + 1).': isi kosong, dilewati.';

                continue;
            }
            if ($mark === 'Q') {
                if ($q) {
                    $questions[] = $q;
                }
                $q = ['type' => 'pg', 'body' => mb_substr($text, 0, 4000), 'options' => [], 'answer' => null, 'score' => 1.0, 'guide' => null];
            } elseif ($q) {
                $correct = collect($cells)->except([$mi])->contains(fn ($v, $k) => $v === '1' && $cells[$k] !== $text);
                if ($correct && $q['answer'] === null) {
                    $q['answer'] = count($q['options']);
                }
                if (count($q['options']) < 8) {
                    $q['options'][] = mb_substr($text, 0, 1000);
                }
            }
        }
        if ($q) {
            $questions[] = $q;
        }
    }
}
