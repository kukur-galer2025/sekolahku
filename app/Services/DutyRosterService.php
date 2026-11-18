<?php

namespace App\Services;

use App\Models\Classroom;
use App\Models\DutyRoster;
use App\Models\School;
use App\Models\Student;
use App\Services\Import\SpreadsheetReader;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Writer;

/**
 * Jadwal piket kelas (format FORMAT_JADWAL_PIKET.xlsx di prototipe):
 * judul "JADWAL PIKET", kolom SENIN … JUMAT, minimal 8 baris petugas per hari.
 * - Susun acak: siswa dibagi rata ke hari sekolah.
 * - Impor: kolom hari berisi nama → dipakai; format kosong → diisi acak.
 */
class DutyRosterService
{
    public const MIN_ROWS = 8;

    private const NAMES = [1 => 'Senin', 2 => 'Selasa', 3 => 'Rabu', 4 => 'Kamis', 5 => 'Jumat', 6 => 'Sabtu'];

    /** Hari piket = hari sekolah Senin–Sabtu. @return array<int,string> */
    public function days(School $school): array
    {
        $d = array_values(array_filter($school->schoolDays(), fn ($x) => $x >= 1 && $x <= 6)) ?: [1, 2, 3, 4, 5];
        sort($d);

        return array_combine($d, array_map(fn ($x) => self::NAMES[$x], $d));
    }

    public function students(Classroom $c): Collection
    {
        return Student::query()->where('classroom_id', $c->id)->where('is_active', true)->orderBy('name')->get(['id', 'nis', 'name']);
    }

    /** @return array{days:array,rows:int,grid:array<int,list<array{id:int,name:string}>>,updated_at:?string,note:?string} */
    public function view(School $school, Classroom $c): array
    {
        $roster = DutyRoster::query()->where('classroom_id', $c->id)->first();
        $students = $this->students($c)->keyBy('id');
        $days = $this->days($school);
        $grid = [];
        foreach ($days as $d => $name) {
            $grid[$d] = collect($roster?->days[(string) $d] ?? $roster?->days[$d] ?? [])
                ->filter(fn ($id) => $students->has($id))->unique()->values()
                ->map(fn ($id) => ['id' => (int) $id, 'name' => $students[$id]->name])->all();
        }

        return [
            'days' => $days, 'rows' => max(self::MIN_ROWS, (int) ceil($students->count() / max(1, count($days))), ...array_map('count', $grid ?: [[]])),
            'grid' => $grid, 'note' => $roster?->note, 'updated_at' => $roster?->updated_at?->toIso8601String(),
        ];
    }

    public function save(School $school, Classroom $c, array $grid, ?string $note, ?int $by): void
    {
        $valid = $this->students($c)->pluck('id')->flip();
        $clean = [];
        foreach (array_keys($this->days($school)) as $d) {
            $clean[(string) $d] = collect($grid[$d] ?? $grid[(string) $d] ?? [])->map(fn ($id) => (int) $id)->filter(fn ($id) => $valid->has($id))->unique()->values()->all();
        }
        DutyRoster::query()->updateOrCreate(['classroom_id' => $c->id], ['days' => $clean, 'note' => $note ? mb_substr($note, 0, 250) : null, 'updated_by' => $by]);
    }

    public function random(School $school, Classroom $c, ?int $by): int
    {
        $ids = $this->students($c)->pluck('id')->shuffle()->values();
        if ($ids->isEmpty()) {
            throw ValidationException::withMessages(['classroom' => 'Kelas ini belum punya siswa.']);
        }
        $days = array_keys($this->days($school));
        $grid = array_fill_keys($days, []);
        foreach ($ids as $i => $id) {
            $grid[$days[$i % count($days)]][] = $id;
        }
        $this->save($school, $c, $grid, 'Disusun acak dari '.$ids->count().' siswa.', $by);

        return $ids->count();
    }

    /** @return array{placed:int,missing:list<string>,random:bool} */
    public function import(School $school, Classroom $c, string $path, string $ext, ?int $by): array
    {
        $sheets = app(SpreadsheetReader::class)->read($path, $ext);
        $rows = collect($sheets)->sortByDesc(fn ($r) => count($r))->first() ?? [];
        $days = $this->days($school);
        $norm = fn ($s) => trim(preg_replace('/\s+/', ' ', preg_replace('/[^a-z0-9 ]/', ' ', mb_strtolower((string) $s))));

        $headerRow = -1;
        $cols = [];
        foreach (array_slice($rows, 0, 15, true) as $i => $row) {
            $m = [];
            foreach ($row as $j => $v) {
                foreach ($days as $d => $name) {
                    if ($norm($v) === mb_strtolower($name)) {
                        $m[$d] = $j;
                    }
                }
            }
            if (count($m) >= 3) {
                [$headerRow, $cols] = [$i, $m];
                break;
            }
        }
        if ($headerRow < 0) {
            throw ValidationException::withMessages(['file' => 'Judul hari SENIN–JUMAT tidak ditemukan. Gunakan format FORMAT_JADWAL_PIKET.xlsx (unduh dari tombol Unduh Excel).']);
        }

        $students = $this->students($c);
        $match = function (string $raw) use ($students, $norm) {
            $t = $norm($raw);
            if ($t === '') {
                return null;
            }
            $hit = $students->first(fn ($s) => $norm($s->name) === $t || $s->nis === trim($raw));
            if ($hit) {
                return $hit;
            }
            $c = $students->filter(fn ($s) => str_starts_with($norm($s->name), $t) || str_starts_with($t, $norm($s->name)));
            if ($c->count() === 1) {
                return $c->first();
            }
            $w2 = fn ($x) => implode(' ', array_slice(explode(' ', $x), 0, 2));
            $c = $students->filter(fn ($s) => $w2($norm($s->name)) === $w2($t));

            return $c->count() === 1 ? $c->first() : null;
        };

        $grid = array_fill_keys(array_keys($days), []);
        $missing = [];
        $found = 0;
        foreach (array_slice($rows, $headerRow + 1) as $row) {
            foreach ($cols as $d => $j) {
                $raw = is_string($row[$j] ?? null) ? trim($row[$j]) : '';
                if ($raw === '') {
                    continue;
                }
                $found++;
                if ($s = $match($raw)) {
                    $grid[$d][] = $s->id;
                } else {
                    $missing[] = $raw.' ('.$days[$d].')';
                }
            }
        }

        if ($found === 0) {
            $n = $this->random($school, $c, $by);

            return ['placed' => $n, 'missing' => [], 'random' => true];
        }
        $placed = array_sum(array_map(fn ($x) => count(array_unique($x)), $grid));
        if ($placed === 0) {
            throw ValidationException::withMessages(['file' => 'Tidak ada nama yang cocok dengan siswa kelas '.$c->name.': '.implode(', ', array_slice($missing, 0, 5)).'. Jadwal lama tidak diubah.']);
        }
        $this->save($school, $c, $grid, 'Diimpor dari Excel: '.$placed.' petugas'.($missing ? ', '.count($missing).' nama tidak cocok' : '').'.', $by);

        return ['placed' => $placed, 'missing' => array_slice($missing, 0, 20), 'random' => false];
    }

    /** File .xlsx sesuai format piket (dipakai juga sebagai format kosong untuk diisi). */
    public function export(School $school, Classroom $c): string
    {
        $v = $this->view($school, $c);
        $tmp = tempnam(sys_get_temp_dir(), 'pk').'.xlsx';
        $w = new Writer;
        $w->openToFile($tmp);
        $bold = (new Style)->setFontBold();
        $w->addRow(Row::fromValues([]));
        $w->addRow(Row::fromValues(['', 'Kelas '.$c->name.' · '.$school->name]));
        $w->addRow(Row::fromValues(['', 'JADWAL PIKET'], $bold));
        $w->addRow(Row::fromValues(['', ...array_map('mb_strtoupper', array_values($v['days']))], $bold));
        for ($i = 0; $i < $v['rows']; $i++) {
            $w->addRow(Row::fromValues(['', ...array_map(fn ($d) => $v['grid'][$d][$i]['name'] ?? '', array_keys($v['days']))]));
        }
        $w->close();

        return $tmp;
    }
}
