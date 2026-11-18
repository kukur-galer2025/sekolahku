<?php

namespace App\Services\Import;

use RuntimeException;
use Throwable;

/**
 * Membaca PDF jadwal hasil aSc Timetables (cetak "Print to PDF") menjadi baris seperti file Excel,
 * sehingga importer Jadwal Pelajaran dan Jam KBM yang sudah ada bisa langsung memakainya.
 *
 * Dua jenis halaman didukung (boleh campur dalam satu file):
 *  - Jadwal kelas  : judul = nama kelas; tiap sel berisi MATA PELAJARAN (besar) + nama guru (kecil, rata kanan bawah).
 *  - Jadwal guru   : judul = "Teacher/Guru <nama>"; tiap sel berisi mapel (kecil, kiri atas) + KELAS (besar).
 * Kolom = jam ke- (1, 2, ...) dengan jam mulai–selesai di bawahnya; baris = hari (Se, Se, Ra, Ka, Ju, ...).
 *
 * Lebar sel tidak tersimpan di PDF, jadi dihitung dari posisi teks: teks besar selalu di tengah sel,
 * teks kecil menempel di tepi kanan (jadwal kelas) atau tepi kiri (jadwal guru).
 * PDF hasil scan/foto (tanpa teks) tidak bisa dibaca.
 *
 * Hasil: ['Jadwal (PDF)' => baris HARI|JAM KE|JUMLAH JAM|KELAS|MATA PELAJARAN|GURU, 'Jam KBM (PDF)' => baris JAM KE|MULAI|SELESAI|KETERANGAN].
 */
class TimetablePdfReader
{
    public const MAX_PAGES = 300;

    private const DAYS = [1 => 'SENIN', 2 => 'SELASA', 3 => 'RABU', 4 => 'KAMIS', 5 => 'JUMAT', 6 => 'SABTU', 7 => 'MINGGU'];

    private const DAYS_EN = [1 => 'MONDAY', 2 => 'TUESDAY', 3 => 'WEDNESDAY', 4 => 'THURSDAY', 5 => 'FRIDAY', 6 => 'SATURDAY', 7 => 'SUNDAY'];

    /** Lebar huruf Helvetica/Arial per 1000 satuan (ASCII 32–126); huruf lain dianggap 556. */
    private const WIDTHS = [278, 278, 355, 556, 556, 889, 667, 191, 333, 333, 389, 584, 278, 333, 278, 278, 556, 556, 556, 556, 556, 556, 556, 556,
        556, 556, 278, 278, 584, 584, 584, 556, 1015, 667, 667, 722, 722, 667, 611, 778, 722, 278, 500, 667, 556, 833, 722, 778, 667, 778, 722, 667,
        611, 722, 667, 944, 667, 667, 611, 278, 278, 278, 469, 556, 333, 556, 556, 500, 556, 556, 278, 556, 556, 222, 222, 500, 222, 833, 556, 556,
        556, 556, 333, 500, 278, 556, 500, 722, 500, 500, 500, 334, 260, 334, 584];

    public const SHEET = 'Jadwal (PDF)';          // semua halaman (kelas + guru), tanpa duplikat

    public const SHEET_CLASS = 'Jadwal kelas (PDF)';  // hanya halaman jadwal per kelas

    public const SHEET_TEACHER = 'Jadwal guru (PDF)'; // hanya halaman jadwal per guru

    public static function isPdf(array $sheets): bool
    {
        return isset($sheets[self::SHEET]);
    }

    /** Kata umum nama mapel, membantu membedakan "BIMBINGAN" + "KARIR" (dua kata) dari "BIMBI" + "NGAN" (dipenggal). */
    private const WORDS = ['PENDIDIKAN', 'AGAMA', 'BUDI', 'PEKERTI', 'PANCASILA', 'BAHASA', 'INDONESIA', 'INGGRIS', 'JAWA', 'SUNDA', 'ARAB',
        'JEPANG', 'MANDARIN', 'JERMAN', 'MATEMATIKA', 'FISIKA', 'KIMIA', 'BIOLOGI', 'SEJARAH', 'GEOGRAFI', 'EKONOMI', 'SOSIOLOGI',
        'SENI', 'BUDAYA', 'PRAKARYA', 'JASMANI', 'OLAHRAGA', 'KESEHATAN', 'BIMBINGAN', 'KONSELING', 'KARIR', 'INFORMATIKA',
        'MUATAN', 'LOKAL', 'KEWIRAUSAHAAN', 'PROJEK', 'PROYEK', 'KREATIF', 'PILIHAN', 'DASAR', 'KEJURUAN', 'ILMU', 'PENGETAHUAN',
        'ALAM', 'SOSIAL', 'TERAPAN', 'UPACARA', 'LITERASI', 'TAHFIDZ', 'WALI', 'KELAS', 'ENGLISH', 'TOURISM'];

    /** @return array<string, list<list<string>>> */
    public function read(string $path): array
    {
        self::registerLibrary();
        $config = new \Smalot\PdfParser\Config;
        $config->setDataTmFontInfoHasToBeIncluded(true);

        try {
            $pages = (new \Smalot\PdfParser\Parser([], $config))->parseFile($path)->getPages();
        } catch (Throwable $e) {
            throw new RuntimeException('PDF tidak bisa dibuka. Pastikan file tidak rusak dan tidak dikunci kata sandi.', 0, $e);
        }
        if (count($pages) > self::MAX_PAGES) {
            throw new RuntimeException('PDF terlalu banyak halaman (lebih dari '.self::MAX_PAGES.').');
        }

        $grids = [];
        foreach ($pages as $page) {
            $items = $this->items($page);
            if ($grid = $this->grid($items)) {
                $grids[] = $grid;
            }
        }
        if (! $grids) {
            throw new RuntimeException('Tidak ada tabel jadwal yang terbaca di PDF ini. Gunakan PDF hasil cetak aSc Timetables (bukan hasil scan/foto), atau format Excel.');
        }

        $corpus = $this->subjectCorpus($grids);
        $rows = $byKind = [];
        foreach ($grids as $g) {
            foreach ($g['cells'] as $cell) {
                $r = $this->row($g, $cell, $corpus);
                $rows[] = $r;
                $byKind[$g['teacher'] ? 'teacher' : 'class'][] = $r;
            }
        }
        $head = [['HARI', 'JAM KE', 'JUMLAH JAM', 'KELAS', 'MATA PELAJARAN', 'GURU']];
        $table = fn (array $rs) => array_merge($head, $rs ? $this->dedupe($this->canonical(array_values(array_filter($rs)))) : []);

        return [
            self::SHEET => $table($rows),
            self::SHEET_CLASS => $table($byKind['class'] ?? []),
            self::SHEET_TEACHER => $table($byKind['teacher'] ?? []),
            'Jam KBM (PDF)' => array_merge([['JAM KE', 'MULAI', 'SELESAI', 'KETERANGAN']], $this->periods($grids)),
        ];
    }

    /** Pustaka smalot/pdfparser (LGPL-3.0) dibundel di lib/ agar tidak perlu composer di hosting. */
    public static function registerLibrary(): void
    {
        if (class_exists(\Smalot\PdfParser\Parser::class)) {
            return;
        }
        $base = base_path('lib/smalot-pdfparser/src/');
        spl_autoload_register(function (string $class) use ($base) {
            if (str_starts_with($class, 'Smalot\\PdfParser\\')) {
                $file = $base.str_replace('\\', '/', $class).'.php';
                if (is_file($file)) {
                    require $file;
                }
            }
        });
    }

    /** Teks di halaman: x (kiri), y (baseline), ukuran efektif, lebar perkiraan. */
    private function items($page): array
    {
        $out = [];
        foreach ($page->getDataTm() as $d) {
            $text = trim(preg_replace('/\s+/u', ' ', (string) ($d[1] ?? '')));
            if ($text === '') {
                continue;
            }
            $size = abs((float) ($d[3] ?? 1) * (float) ($d[0][0] ?? 1)) ?: 1.0;
            $x = (float) $d[0][4];
            $w = $this->width($text, $size);
            $out[] = ['x' => $x, 'y' => (float) $d[0][5], 'size' => $size, 'text' => $text, 'w' => $w, 'c' => $x + $w / 2, 'r' => $x + $w];
        }

        return $out;
    }

    private function width(string $text, float $size): float
    {
        $sum = 0;
        foreach (mb_str_split($text) as $ch) {
            $o = mb_ord($ch);
            $sum += ($o >= 32 && $o <= 126) ? self::WIDTHS[$o - 32] : 556;
        }

        return $sum / 1000 * $size;
    }

    /** Kenali kerangka tabel: kolom jam ke-, baris hari, judul, lalu sel-selnya. */
    private function grid(array $items): ?array
    {
        $labels = array_values(array_filter($items, fn ($i) => preg_match('/^\d{1,2}$/', $i['text'])));
        if (count($labels) < 2) {
            return null;
        }
        // Baris nomor jam ke-: y yang paling banyak memuat angka 1..n berurutan.
        $byY = [];
        foreach ($labels as $l) {
            $byY[(string) round($l['y'])][] = $l;
        }
        uasort($byY, fn ($a, $b) => count($b) <=> count($a));
        $head = array_values(reset($byY));
        usort($head, fn ($a, $b) => $a['x'] <=> $b['x']);
        $nums = array_map(fn ($l) => (int) $l['text'], $head);
        if (count($head) < 2 || $nums !== range($nums[0], $nums[0] + count($nums) - 1)) {
            return null;
        }
        $headY = $head[0]['y'];

        $centers = array_map(fn ($l) => $l['c'], $head);
        $n = count($centers);
        $left = $right = [];
        for ($i = 0; $i < $n; $i++) {
            $prev = $i > 0 ? ($centers[$i] + $centers[$i - 1]) / 2 : $centers[0] - ($centers[1] - $centers[0]) / 2;
            $next = $i < $n - 1 ? ($centers[$i] + $centers[$i + 1]) / 2 : $centers[$n - 1] + ($centers[$n - 1] - $centers[$n - 2]) / 2;
            [$left[$i], $right[$i]] = [$prev, $next];
        }
        $colW = ($right[$n - 1] - $left[0]) / $n;

        // Jam mulai–selesai di bawah tiap nomor.
        $times = [];
        foreach ($items as $it) {
            if ($it['y'] < $headY && $it['y'] > $headY - 40 && preg_match('/^(\d{1,2}[:.]\d{2})\s*[-–]\s*(\d{1,2}[:.]\d{2})$/', $it['text'], $m)) {
                $col = $this->nearest($centers, $it['c']);
                $times[$nums[$col]] = [str_replace('.', ':', $m[1]), str_replace('.', ':', $m[2])];
            }
        }

        // Label hari di kiri tabel, urut dari atas.
        $dayItems = array_values(array_filter($items, fn ($i) => $i['r'] <= $left[0] + 2 && $i['y'] < $headY - 10 && preg_match('/^[\p{L}\']{2,10}$/u', $i['text'])));
        usort($dayItems, fn ($a, $b) => $b['y'] <=> $a['y']);
        $days = $this->days(array_map(fn ($d) => $d['text'], $dayItems));
        if (! $days) {
            return null;
        }
        $ys = array_map(fn ($d) => $d['y'], $dayItems);
        $gap = count($ys) > 1 ? ($ys[0] - $ys[count($ys) - 1]) / (count($ys) - 1) : 90;
        $bands = [];
        foreach ($ys as $i => $y) {
            $bands[] = ['day' => $days[$i], 'top' => $i > 0 ? ($ys[$i - 1] + $y) / 2 : $y + $gap / 2, 'bottom' => $i < count($ys) - 1 ? ($y + $ys[$i + 1]) / 2 : $y - $gap / 2];
        }

        // Judul: teks terbesar di atas baris nomor.
        $top = array_filter($items, fn ($i) => $i['y'] > $headY + 20);
        usort($top, fn ($a, $b) => $b['size'] <=> $a['size']);
        $title = $top ? $top[0]['text'] : '';
        $isTeacher = (bool) preg_match('/^(teacher|guru|pengajar)\s+/i', $title);
        $owner = $isTeacher ? trim(preg_replace('/^(teacher|guru|pengajar)\s+/i', '', $title)) : $title;

        // Isi sel: hanya di dalam tabel dan di dalam pita hari.
        $content = array_values(array_filter($items, fn ($i) => $i['x'] >= $left[0] - 2 && $i['y'] < $headY - 10
            && ! preg_match('/aSc Timetables|^Menghasilkan jadwal|^Generated/i', $i['text'])));
        if (! $content) {
            return null;
        }
        $small = min(array_column($content, 'size'));

        $cells = [];
        foreach ($bands as $band) {
            $in = array_values(array_filter($content, fn ($i) => $i['y'] <= $band['top'] && $i['y'] > $band['bottom']));
            $big = array_values(array_filter($in, fn ($i) => $i['size'] > $small * 1.5));
            $tiny = array_values(array_filter($in, fn ($i) => $i['size'] <= $small * 1.5));
            foreach ($this->groups($big, $colW) as $g) {
                $cell = $isTeacher ? $this->teacherCell($g, $tiny, $left, $right) : $this->classCell($g, $tiny, $left, $right);
                if ($cell) {
                    $cells[] = $cell + ['day' => $band['day']];
                }
            }
        }

        return ['teacher' => $isTeacher, 'owner' => $owner, 'nums' => $nums, 'times' => $times, 'cells' => $cells, 'left' => $left, 'right' => $right];
    }

    /** "Se, Se, Ra, Ka, Ju" → [1, 2, 3, 4, 5] (urut, Se pertama Senin, kedua Selasa). */
    private function days(array $labels): ?array
    {
        foreach ([self::DAYS, self::DAYS_EN] as $names) {
            $out = [];
            $next = 1;
            foreach ($labels as $l) {
                $l = strtoupper(preg_replace('/[^\p{L}]/u', '', $l));
                $found = null;
                for ($d = $next; $d <= 7; $d++) {
                    if ($l !== '' && str_starts_with(str_replace("'", '', $names[$d]), $l)) {
                        $found = $d;
                        break;
                    }
                }
                if (! $found) {
                    continue 2;
                }
                $out[] = $found;
                $next = $found + 1;
            }
            if ($out) {
                return $out;
            }
        }

        return null;
    }

    /** Baris-baris teks besar yang sumbu tengahnya sama = satu sel. */
    private function groups(array $big, float $colW): array
    {
        usort($big, fn ($a, $b) => $b['y'] <=> $a['y']);
        $groups = [];
        foreach ($big as $it) {
            foreach ($groups as &$g) {
                if (abs($g['c'] - $it['c']) < $colW * 0.35) {
                    $g['lines'][] = $it;
                    $g['c'] = array_sum(array_column($g['lines'], 'c')) / count($g['lines']);
                    continue 2;
                }
            }
            unset($g);
            $groups[] = ['c' => $it['c'], 'lines' => [$it]];
        }

        return $groups;
    }

    /** Jadwal kelas: nama guru rata kanan → tepi kanan sel; mapel di tengah → tepi kiri. */
    private function classCell(array $g, array $tiny, array $left, array $right): ?array
    {
        $c = $g['c'];
        $lowest = min(array_column($g['lines'], 'y'));
        $cands = array_filter($tiny, fn ($t) => $t['r'] >= $c && $t['y'] < $lowest);
        usort($cands, fn ($a, $b) => ($a['r'] - $c) <=> ($b['r'] - $c));
        $teacher = $cands[0] ?? null;
        if ($teacher && ($teacher['r'] - $c) > (end($right) - $left[0]) / 2) {
            $teacher = null;
        }
        if ($teacher) {
            // Nama guru panjang dipecah beberapa baris rata kanan ("Aditia Bagaskara, S.Pd.," + "Gr.").
            $same = array_filter($tiny, fn ($t) => abs($t['r'] - $teacher['r']) < 2.5 && abs($t['size'] - $teacher['size']) < 0.5);
            usort($same, fn ($a, $b) => $b['y'] <=> $a['y']);
            $teacher['text'] = trim(preg_replace('/\s+/', ' ', implode(' ', array_column($same, 'text'))));
            $teacher['text'] = preg_replace('/\s+,/', ',', $teacher['text']);
        }

        [$s, $e] = $teacher ? $this->spanFromRight($c, $teacher['r'] + 3, $left, $right) : $this->spanFromCenter($g, $left, $right);
        if ($s === null) {
            return null;
        }

        return ['start' => $s, 'end' => $e, 'lines' => array_column($g['lines'], 'text'), 'widths' => array_column($g['lines'], 'w'), 'sizes' => array_column($g['lines'], 'size'),
            'teacher' => $teacher['text'] ?? '', 'subject' => null, 'inner' => $right[$e] - $left[$s] - 4];
    }

    /** Jadwal guru: mapel kecil di kiri atas → tepi kiri sel; kelas di tengah → tepi kanan. */
    private function teacherCell(array $g, array $tiny, array $left, array $right): ?array
    {
        $c = $g['c'];
        $highest = max(array_column($g['lines'], 'y'));
        $cands = array_filter($tiny, fn ($t) => $t['x'] <= $c && $t['y'] > $highest);
        usort($cands, fn ($a, $b) => ($c - $a['x']) <=> ($c - $b['x']));
        $subject = $cands[0] ?? null;

        if ($subject) {
            $s = $this->nearest($left, $subject['x'] - 3);
            $e = $this->nearest($right, 2 * $c - $left[$s]);
        } else {
            [$s, $e] = $this->spanFromCenter($g, $left, $right);
        }
        if ($s === null || $e < $s) {
            return null;
        }

        return ['start' => $s, 'end' => $e, 'lines' => array_column($g['lines'], 'text'), 'widths' => array_column($g['lines'], 'w'), 'sizes' => array_column($g['lines'], 'size'),
            'teacher' => null, 'subject' => $subject['text'] ?? '', 'inner' => $right[$e] - $left[$s] - 4];
    }

    private function spanFromRight(float $c, float $edge, array $left, array $right): array
    {
        $e = $this->nearest($right, $edge);
        $s = $this->nearest($left, 2 * $c - $right[$e]);

        return $s <= $e ? [$s, $e] : [null, null];
    }

    /** Tanpa teks kecil: sel terkecil yang tengahnya pas dengan teks dan cukup lebar. */
    private function spanFromCenter(array $g, array $left, array $right): array
    {
        $c = $g['c'];
        $need = max(array_column($g['lines'], 'w'));
        $best = [null, null];
        $bestW = INF;
        foreach ($left as $s => $l) {
            foreach ($right as $e => $r) {
                if ($e < $s || abs(($l + $r) / 2 - $c) > ($right[0] - $left[0]) * 0.25 || $r - $l < $need) {
                    continue;
                }
                if ($r - $l < $bestW) {
                    [$best, $bestW] = [[$s, $e], $r - $l];
                }
            }
        }

        return $best;
    }

    private function nearest(array $values, float $x): int
    {
        $best = 0;
        foreach ($values as $i => $v) {
            if (abs($v - $x) < abs($values[$best] - $x)) {
                $best = $i;
            }
        }

        return $best;
    }

    /** Nama mapel satu baris dari semua halaman, untuk menebak pemenggalan kata ("INFORMATIK" + "A"). */
    private function subjectCorpus(array $grids): array
    {
        $set = [];
        foreach (self::WORDS as $w) {
            $set['#'.$w] = true;
        }
        foreach ($grids as $g) {
            foreach ($g['cells'] as $cell) {
                $one = $g['teacher'] ? $cell['subject'] : (count($cell['lines']) === 1 ? $cell['lines'][0] : null);
                if ($one) {
                    $set[$this->key($one)] = true;
                    foreach (preg_split('/[\s,&.]+/', $this->key($one)) as $w) {
                        if (mb_strlen($w) >= 3) {
                            $set['#'.$w] = true; // kosakata: kata utuh yang pernah tampil
                        }
                    }
                }
            }
        }

        return $set;
    }

    /**
     * Gabungkan baris teks satu sel. aSc memenggal kata panjang di tengah ("INFORMATIK" + "A") tanpa tanda hubung:
     * bila huruf pertama baris berikut tidak muat lagi di baris sebelumnya, berarti kata dipenggal (sambung tanpa spasi).
     */
    private function joinLines(array $cell, array $corpus, bool $isClass): string
    {
        $lines = $cell['lines']; 
        $size = $cell['sizes'][0] ?? 12.0;
        $text = array_shift($lines);
        $prevW = $cell['widths'][0] ?? 0;
        foreach ($lines as $i => $next) {
            $nextW = $cell['widths'][$i + 1] ?? 0;
            if ($isClass) {
                $text .= ctype_digit($next) ? ' '.$next : $next;
            } else {
                $glued = $text.$next; 
                $spaced = $text.' '.$next;
                $broken = $prevW + $this->width(mb_substr($next, 0, 1), $size) > $cell['inner'] * 0.95;
                if (isset($corpus[$this->key($glued)])) {
                    $text = $glued;
                } elseif (isset($corpus[$this->key($spaced)]) || ctype_digit($next) || ! $broken
                    || preg_match('/[\d,&.]$/', $text) || isset($corpus['#'.$this->lastWord($text)])) {
                    $text = $spaced;
                } else {
                    $text = $glued;
                }
            }
            $prevW = $nextW;
        }

        return trim($text);
    }

    private function lastWord(string $v): string
    {
        $w = preg_split('/[\s,&.]+/', $this->key($v));

        return (string) end($w);
    }

    /** Ejaan yang sama tanpa spasi ("PENDIDIKANPANCASILA") diseragamkan ke ejaan terbanyak ("PENDIDIKAN PANCASILA"). */
    private function canonical(array $rows): array
    {
        $count = [];
        foreach ($rows as $r) {
            $k = preg_replace('/[^A-Z0-9]/', '', strtoupper($r[4]));
            $count[$k][$r[4]] = ($count[$k][$r[4]] ?? 0) + 1;
        }
        $best = [];
        foreach ($count as $k => $spellings) {
            uksort($spellings, fn ($a, $b) => [$spellings[$b], substr_count($b, ' ')] <=> [$spellings[$a], substr_count($a, ' ')]);
            $best[$k] = array_key_first($spellings);
        }
        foreach ($rows as &$r) {
            $r[4] = $best[preg_replace('/[^A-Z0-9]/', '', strtoupper($r[4]))];
        }

        return $rows;
    }

    private function key(string $v): string
    {
        return strtoupper(preg_replace('/\s+/', ' ', trim($v)));
    }

    private function row(array $g, array $cell, array $corpus): ?array
    {
        $start = $g['nums'][$cell['start']];
        $count = $cell['end'] - $cell['start'] + 1;
        $day = self::DAYS[$cell['day']] ?? null;
        if (! $day || $cell['day'] > 6) {
            return null;
        }
        if ($g['teacher']) {
            $class = $this->joinLines($cell, $corpus, true);
            $subject = $cell['subject'];
            $teacher = $g['owner'];
        } else {
            $class = $g['owner'];
            $subject = $this->joinLines($cell, $corpus, false);
            $teacher = $cell['teacher'];
        }
        if ($class === '' || $subject === '') {
            return null;
        }

        return [$day, (string) $start, (string) $count, $class, $subject, (string) $teacher];
    }

    /** File berisi jadwal kelas sekaligus jadwal guru: blok yang sama cukup sekali (utamakan yang ada gurunya). */
    private function dedupe(array $rows): array
    {
        $key = fn ($r) => implode('|', [$r[0], $r[1], $r[2], preg_replace('/[\s-]+/', '', strtoupper($r[3])), $this->key($r[4])]);
        $out = [];
        foreach ($rows as $r) {
            $k = $key($r);
            if (! isset($out[$k]) || ($out[$k][5] === '' && $r[5] !== '')) {
                $out[$k] = $r;
            }
        }

        return array_values($out);
    }

    /** Jam KBM dari kepala tabel; jeda antar jam menjadi istirahat. */
    private function periods(array $grids): array
    {
        $times = [];
        foreach ($grids as $g) {
            $times += $g['times'];
        }
        ksort($times);
        $rows = [];
        $prevEnd = null;
        foreach ($times as $no => [$start, $end]) {
            if ($prevEnd && strcmp($prevEnd, $this->hhmm($start)) < 0) {
                $rows[] = ['Istirahat', $prevEnd, $this->hhmm($start), 'Istirahat'];
            }
            $rows[] = [(string) $no, $this->hhmm($start), $this->hhmm($end), ''];
            $prevEnd = $this->hhmm($end);
        }

        return $rows;
    }

    private function hhmm(string $t): string
    {
        [$h, $m] = explode(':', $t);

        return sprintf('%02d:%02d', $h, $m);
    }
}
