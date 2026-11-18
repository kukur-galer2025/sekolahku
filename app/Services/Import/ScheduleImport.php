<?php

namespace App\Services\Import;

use App\Models\Classroom;
use App\Models\Schedule;
use App\Models\Teacher;

/**
 * Jadwal pelajaran dari Excel — satu baris per blok pelajaran:
 *   HARI | JAM KE | (JUMLAH JAM) | KELAS | MATA PELAJARAN | GURU (NIK atau nama)
 *
 * - JAM KE boleh tunggal ("3") atau rentang ("3-4"); JUMLAH JAM (bila ada) mengalahkan rentang.
 * - Jam ke- dihitung tanpa istirahat, sama dengan Jam KBM.
 * - Mengganti seluruh jadwal sekolah. Kelas yang belum ada dilewati (impor siswa dulu);
 *   guru yang tidak dikenali tetap disimpan tanpa guru.
 */
class ScheduleImport
{
    use ImportHelpers;

    public const DAYS = ['SENIN' => 1, 'SELASA' => 2, 'RABU' => 3, 'KAMIS' => 4, 'JUMAT' => 5, "JUM'AT" => 5, 'SABTU' => 6];

    private const REQUIRED = ['day' => '/^HARI$/', 'period' => '/^(JAM KE-?|JAM|JAM PELAJARAN|JP)$/', 'class' => '/^(KELAS|ROMBEL)/', 'subject' => '/^(MATA PELAJARAN|MAPEL)/'];

    private const OPTIONAL = ['count' => '/^(JUMLAH JAM|JML\.? JAM|DURASI)$/', 'teacher' => '/^(GURU|NIK GURU|NAMA GURU|PENGAJAR|NIK)$/'];

    public function rows(array $sheets): array
    {
        $out = ['rows' => [], 'errors' => [], 'warnings' => [], 'unknown_classes' => [], 'unknown_teachers' => [], 'new_classes' => []];
        // PDF aSc memuat daftar kelas lengkap: kelas yang belum ada dibuat otomatis (Excel tetap mensyaratkan impor siswa dulu).
        $autoClasses = TimetablePdfReader::isPdf($sheets);
        $classes = Classroom::query()->get()->keyBy(fn ($c) => $this->classKey($c->name));
        $teachers = Teacher::query()->get(['id', 'nik', 'name']);
        $bad = 0;

        foreach ($sheets as $rows) {
            if (! $h = $this->findHeader($rows, self::REQUIRED, self::OPTIONAL)) {
                continue;
            }
            [$hi, $c] = $h;

            foreach (array_slice($rows, $hi + 1) as $row) {
                $dayRaw = strtoupper($this->str($row, $c['day']));
                $periodRaw = $this->str($row, $c['period']);
                $className = $this->className($this->str($row, $c['class']));
                $subject = $this->str($row, $c['subject']);
                if ($dayRaw === '' && $periodRaw === '' && $className === '') {
                    continue;
                }

                $day = self::DAYS[$dayRaw] ?? (ctype_digit($dayRaw) && (int) $dayRaw >= 1 && (int) $dayRaw <= 6 ? (int) $dayRaw : null);
                if (! preg_match('/^(\d{1,2})(?:\s*[-–]\s*(\d{1,2}))?$/', $periodRaw, $m) || ! $day || $className === '' || $subject === '') {
                    $bad++;

                    continue;
                }
                $start = (int) $m[1];
                $count = isset($m[2]) ? max(1, (int) $m[2] - $start + 1) : 1;
                if (isset($c['count']) && ctype_digit($this->str($row, $c['count']))) {
                    $count = max(1, (int) $this->str($row, $c['count']));
                }

                $classroom = $classes->get($this->classKey($className));
                if (! $classroom && $autoClasses) {
                    $out['new_classes'][$this->classKey($className)] ??= $className;
                    $classroom = (object) ['id' => null, 'name' => $out['new_classes'][$this->classKey($className)]];
                }
                if (! $classroom) {
                    $out['unknown_classes'][$className] = true;

                    continue;
                }

                $teacherRaw = $this->str($row, $c['teacher'] ?? null);
                $teacher = $teacherRaw !== '' ? $this->matchTeacher($teachers, $teacherRaw) : null;
                if ($teacherRaw !== '' && ! $teacher) {
                    $out['unknown_teachers'][$teacherRaw] = true;
                }

                $out['rows'][] = [
                    'classroom_id' => $classroom->id, 'class_key' => $this->classKey($classroom->name), 'class' => $classroom->name, 'day' => $day, 'period_start' => $start,
                    'period_count' => $count, 'subject' => $subject, 'teacher_id' => $teacher?->id, 'teacher' => $teacher?->name ?? ($teacherRaw ?: null),
                ];
            }
            break;
        }

        if ($bad) {
            $out['warnings'][] = "$bad baris dilewati karena HARI, JAM KE, KELAS, atau MATA PELAJARAN kosong/tidak dikenali (contoh benar: Senin | 3-4 | X - FKK 1 | Matematika).";
        }
        if ($out['unknown_classes']) {
            $out['warnings'][] = 'Kelas belum ada sehingga barisnya dilewati: '.implode(', ', array_keys($out['unknown_classes'])).'. Impor data siswa dulu agar kelasnya terbentuk.';
        }
        if ($out['unknown_teachers']) {
            $out['warnings'][] = 'Guru tidak dikenali, jadwal tetap disimpan tanpa guru: '.implode(', ', array_slice(array_keys($out['unknown_teachers']), 0, 10)).(count($out['unknown_teachers']) > 10 ? ', ...' : '').'.';
        }
        foreach ($this->clashes($out['rows']) as $w) {
            $out['warnings'][] = $w;
        }
        if (! $out['rows'] && ! $out['errors']) {
            $out['errors'][] = 'Tidak ada jadwal terbaca. Gunakan format jadwal (kolom HARI, JAM KE, KELAS, MATA PELAJARAN, GURU).';
        }

        return $out;
    }

    /** NIK persis, nama persis (abaikan gelar/tanda baca), atau awalan nama ≥ 8 huruf (sama dengan prototipe). */
    private function matchTeacher($teachers, string $raw): ?Teacher
    {
        $nik = preg_replace('/\s+/', '', $raw);
        $key = fn ($v) => preg_replace('/[^a-z0-9]/', '', strtolower($v));
        $k = $key($raw);

        $core = fn ($v) => $this->personKey($v);
        $c = $core($raw);

        return $teachers->firstWhere('nik', $nik)
            ?? $teachers->first(fn ($t) => $key($t->name) === $k)
            ?? $teachers->first(function ($t) use ($key, $k) {
                $a = $key($t->name);

                return strlen($a) >= 8 && strlen($k) >= 8 && (str_starts_with($a, $k) || str_starts_with($k, $a));
            })
            // Tanpa gelar: "Apt.Erlin Nur Azizah, S.Farm., Gr." = "Erlin Nur Azizah" (cetakan aSc sering memuat gelar lengkap).
            ?? ($c !== '' ? $teachers->first(fn ($t) => $core($t->name) === $c) : null)
            ?? (strlen($c) >= 8 ? $teachers->first(function ($t) use ($core, $c) {
                $a = $core($t->name);

                return strlen($a) >= 8 && (str_starts_with($a, $c) || str_starts_with($c, $a));
            }) : null);
    }

    /** Jadwal bentrok: kelas yang sama atau guru yang sama di jam yang sama. */
    private function clashes(array $rows): array
    {
        $byClass = $byTeacher = [];
        foreach ($rows as $r) {
            for ($p = $r['period_start']; $p < $r['period_start'] + $r['period_count']; $p++) {
                $byClass["{$r['class']}|{$r['day']}|$p"][] = $r['subject'];
                if ($r['teacher_id']) {
                    $byTeacher["{$r['teacher']}|{$r['day']}|$p"][] = $r['class'];
                }
            }
        }
        $days = array_flip(array_filter(self::DAYS, fn ($k) => $k !== "JUM'AT", ARRAY_FILTER_USE_KEY));
        $fmt = fn ($k) => (fn ($p) => $p[0].' · '.ucfirst(strtolower($days[(int) $p[1]] ?? $p[1])).' jam ke-'.$p[2])(explode('|', $k));
        $w = [];
        $c = array_keys(array_filter($byClass, fn ($v) => count($v) > 1));
        $t = array_keys(array_filter($byTeacher, fn ($v) => count(array_unique($v)) > 1));
        if ($c) {
            $w[] = 'Kelas bentrok ('.count($c).'): '.implode('; ', array_map($fmt, array_slice($c, 0, 5))).(count($c) > 5 ? '; ...' : '').'.';
        }
        if ($t) {
            $w[] = 'Guru mengajar dua kelas di jam yang sama ('.count($t).'): '.implode('; ', array_map($fmt, array_slice($t, 0, 5))).(count($t) > 5 ? '; ...' : '').'.';
        }

        return $w;
    }

    public function preview(array $sheets): array
    {
        $r = $this->rows($sheets);
        $rows = collect($r['rows']);
        $names = array_flip(array_filter(self::DAYS, fn ($k) => $k !== "JUM'AT", ARRAY_FILTER_USE_KEY));

        return [
            'ok' => $r['errors'] === [],
            'errors' => $r['errors'],
            'warnings' => array_merge($r['warnings'], ['Seluruh jadwal yang aktif sekarang ('.Schedule::count().' blok) akan diganti. Jendela scan QR siswa mengikuti jadwal baru.']),
            'stats' => [
                'Blok jadwal' => $rows->count(), 'Kelas' => $rows->pluck('class_key')->unique()->count(), 'Kelas baru' => count($r['new_classes']),
                'Jam pelajaran / minggu' => $rows->sum('period_count'), 'Tanpa guru' => $rows->whereNull('teacher_id')->count(),
            ],
            'new_classes' => array_values($r['new_classes']),
            'columns' => ['Hari', 'Jam ke', 'Kelas', 'Mata pelajaran', 'Guru'],
            'sample' => $rows->take(15)->map(fn ($x) => [ucfirst(strtolower($names[$x['day']] ?? '')), $x['period_start'].($x['period_count'] > 1 ? '–'.($x['period_start'] + $x['period_count'] - 1) : ''), $x['class'], $x['subject'], $x['teacher']])->all(),
        ];
    }

    public function apply(array $sheets): array
    {
        $out = $this->rows($sheets);
        $rows = $out['rows'];
        $made = [];
        foreach ($out['new_classes'] as $key => $name) {
            $made[$key] = Classroom::create(['name' => $name])->id; // tingkat diisi otomatis sesuai jenjang
        }
        Schedule::query()->delete();
        foreach ($rows as $r) {
            $r['classroom_id'] ??= $made[$r['class_key']];
            Schedule::create(collect($r)->only(['classroom_id', 'teacher_id', 'subject', 'day', 'period_start', 'period_count'])->all());
        }

        return ['result' => ['Blok jadwal' => count($rows), 'Kelas' => count(array_unique(array_column($rows, 'class_key'))), 'Kelas baru' => count($made)], 'credentials' => []];
    }
}
