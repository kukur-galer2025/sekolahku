<?php

namespace App\Services;

use App\Models\Classroom;
use App\Models\LegerDistribution;
use App\Models\LegerEntry;
use App\Models\School;
use App\Models\Student;
use App\Services\Import\SpreadsheetReader;
use App\Tenancy\TenantFiles;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Leger rapor (alur prototipe, master LEGER_RAPOR.xlsx 4 sheet: DATA → CAPAIAN MATERI → nilai → RAPORT):
 * - Admin: semester & tahun ajaran, kepala sekolah, master struktur (bawaan atau unggah .xlsx), distribusi ke wali kelas.
 * - Wali kelas: isi nilai akhir per mapel, capaian (sangat/belum dipahami), ekstrakurikuler, ketidakhadiran.
 * - Rerata, jumlah, ranking dihitung otomatis; RAPORT per siswa siap cetak.
 */
class LegerService
{
    public const REQUIRED_SHEETS = ['data', 'capaian materi', 'nilai', 'raport'];

    public function __construct(private TenantFiles $files) {}

    // ── Pengaturan & struktur ────────────────────────────────────────

    public function settings(School $school): array
    {
        $s = (array) $school->setting('leger', []);
        $now = $school->localNow();
        $defYear = $now->month >= 7 ? $now->year.'/'.($now->year + 1) : ($now->year - 1).'/'.$now->year;

        return [
            'semester' => in_array($s['semester'] ?? null, ['Gasal', 'Genap'], true) ? $s['semester'] : ($now->month >= 7 ? 'Gasal' : 'Genap'),
            'year' => $s['year'] ?? $defYear,
            'principal_name' => $s['principal_name'] ?? '',
            'principal_nip' => $s['principal_nip'] ?? '',
            'city' => $s['city'] ?? '',
            'master_name' => $s['master_name'] ?? null,
            'master_uploaded_at' => $s['master_uploaded_at'] ?? null,
        ];
    }

    public function term(School $school): string
    {
        $s = $this->settings($school);

        return $s['semester'].' '.$s['year'];
    }

    public function saveSettings(School $school, array $d): void
    {
        $settings = $school->settings ?? [];
        foreach (['semester', 'year', 'principal_name', 'principal_nip', 'city'] as $k) {
            if (array_key_exists($k, $d)) {
                data_set($settings, 'leger.'.$k, trim((string) $d[$k]));
            }
        }
        $school->forceFill(['settings' => $settings])->save();
    }

    /** @return array{subjects:list<array{name:string,mastered:string,needs:string}>,extracurriculars:list<string>,source:string} */
    public function structure(School $school): array
    {
        $custom = $school->setting('leger.structure');
        if (is_array($custom) && ! empty($custom['subjects'])) {
            return $custom + ['source' => 'master sekolah ('.($school->setting('leger.master_name') ?? 'xlsx').')'];
        }
        $d = json_decode((string) file_get_contents(resource_path('leger/default.json')), true);

        return ['subjects' => $d['subjects'], 'extracurriculars' => $d['extracurriculars'], 'source' => 'bawaan MyPresence'];
    }

    /** Ganti master: baca sheet nilai (judul mapel & ekstra) dan CAPAIAN MATERI (teks capaian bawaan). */
    public function uploadMaster(School $school, UploadedFile $file): array
    {
        if (strtolower($file->getClientOriginalExtension()) !== 'xlsx') {
            throw ValidationException::withMessages(['file' => 'Master harus berformat .xlsx.']);
        }
        $sheets = collect(app(SpreadsheetReader::class)->read($file->getRealPath(), 'xlsx'))->mapWithKeys(fn ($rows, $name) => [mb_strtolower(trim($name)) => $rows]);
        $miss = array_values(array_diff(self::REQUIRED_SHEETS, $sheets->keys()->all()));
        if ($miss) {
            throw ValidationException::withMessages(['file' => 'Sheet wajib tidak ada: '.implode(', ', array_map('strtoupper', $miss)).'.']);
        }

        $head = array_map(fn ($v) => is_string($v) ? trim(preg_replace('/\s+/', ' ', $v)) : '', $sheets['nilai'][0] ?? []);
        $low = array_map('mb_strtolower', $head);
        $a = array_search('nisn', $low, true);
        $b = $this->firstStarting($low, 'rata');
        $e = $this->firstStarting($low, 'ektra') ?? $this->firstStarting($low, 'ekstra');
        $f = $this->firstStarting($low, 'kehadiran');
        if ($a === false || $b === null || $b <= $a + 1) {
            throw ValidationException::withMessages(['file' => 'Sheet nilai harus berisi kolom NISN, lalu nama mapel, lalu "rata rata".']);
        }
        $subjects = array_values(array_slice($head, $a + 1, $b - $a - 1));
        $extras = ($e !== null && $f !== null && $f > $e) ? array_values(array_filter(array_slice($head, $e + 1, $f - $e - 1))) : [];
        $cap = array_map(fn ($v) => is_string($v) ? trim($v) : '', $sheets['capaian materi'][2] ?? []);

        $structure = [
            'subjects' => array_map(fn ($name, $j) => ['name' => $name, 'mastered' => $cap[3 + 2 * $j] ?? '', 'needs' => $cap[4 + 2 * $j] ?? ''], $subjects, array_keys($subjects)),
            'extracurriculars' => array_values(array_unique($extras)),
        ];
        $settings = $school->settings ?? [];
        data_set($settings, 'leger.structure', $structure);
        data_set($settings, 'leger.master_name', mb_substr($file->getClientOriginalName(), 0, 120));
        data_set($settings, 'leger.master_uploaded_at', now()->toIso8601String());
        $school->forceFill(['settings' => $settings])->save();

        return $structure;
    }

    public function resetMaster(School $school): void
    {
        $settings = $school->settings ?? [];
        foreach (['structure', 'master_name', 'master_uploaded_at'] as $k) {
            unset($settings['leger'][$k]);
        }
        $school->forceFill(['settings' => $settings])->save();
    }

    private function firstStarting(array $low, string $prefix): ?int
    {
        foreach ($low as $i => $v) {
            if (str_starts_with($v, $prefix)) {
                return $i;
            }
        }

        return null;
    }

    // ── Distribusi ───────────────────────────────────────────────────

    public function distribution(School $school, Classroom $c): ?LegerDistribution
    {
        return LegerDistribution::query()->where('classroom_id', $c->id)->where('term', $this->term($school))->first();
    }

    /** Terdistribusi DAN wali masih sama → wali boleh mengisi. */
    public function isActive(School $school, Classroom $c): bool
    {
        $d = $this->distribution($school, $c);

        return $d && $c->homeroom_teacher_id && $d->teacher_id === $c->homeroom_teacher_id;
    }

    public function distribute(School $school, Classroom $c): void
    {
        if (! $c->homeroom_teacher_id) {
            throw ValidationException::withMessages(['classroom_id' => 'Kelas '.$c->name.' belum punya wali kelas.']);
        }
        LegerDistribution::query()->updateOrCreate(['classroom_id' => $c->id, 'term' => $this->term($school)], ['teacher_id' => $c->homeroom_teacher_id, 'distributed_at' => now()]);
    }

    public function withdraw(School $school, Classroom $c): void
    {
        LegerDistribution::query()->where('classroom_id', $c->id)->where('term', $this->term($school))->delete();
    }

    // ── Lembar kelas ─────────────────────────────────────────────────

    /** Rerata, jumlah, ranking (pengurai seri seperti prototipe: + (B.Indonesia + Matematika + B.Inggris)/300). */
    public function sheet(School $school, Classroom $c): array
    {
        $st = $this->structure($school);
        $term = $this->term($school);
        $students = Student::query()->where('classroom_id', $c->id)->where('is_active', true)->orderBy('name')->get(['id', 'nis', 'nisn', 'name']);
        $entries = LegerEntry::query()->where('classroom_id', $c->id)->where('term', $term)->get()->keyBy('student_id');
        $tie = $this->tieIndexes($st['subjects']);

        $rows = $students->map(function (Student $s) use ($entries, $st, $tie) {
            $e = $entries->get($s->id);
            $scores = array_map(fn ($v) => $v === null || $v === '' ? null : (float) $v, array_pad(array_slice((array) ($e?->scores ?? []), 0, count($st['subjects'])), count($st['subjects']), null));
            $filled = array_values(array_filter($scores, fn ($v) => $v !== null));
            $sum = $filled ? array_sum($filled) : null;
            $key = $sum === null ? null : $sum + array_sum(array_map(fn ($i) => (float) ($scores[$i] ?? 0), $tie)) / 300;

            return [
                'student' => $s->only(['id', 'nis', 'nisn', 'name']),
                'scores' => $scores, 'average' => $filled ? round($sum / count($filled), 2) : null, 'sum' => $sum === null ? null : round($sum, 2), '_key' => $key,
                'competencies' => array_map(fn ($sub, $j) => [
                    (string) ($e?->competencies[$j][0] ?? $sub['mastered']), (string) ($e?->competencies[$j][1] ?? $sub['needs']),
                ], $st['subjects'], array_keys($st['subjects'])),
                'extras' => array_map(fn ($j) => (string) ($e?->extras[$j] ?? ''), array_keys($st['extracurriculars'])),
                'absences' => array_map(fn ($j) => (int) ($e?->absences[$j] ?? 0), [0, 1, 2]),
            ];
        });
        $keys = $rows->pluck('_key')->filter(fn ($k) => $k !== null);
        $rows = $rows->map(function ($r) use ($keys) {
            $r['rank'] = $r['_key'] === null ? null : 1 + $keys->filter(fn ($k) => $k > $r['_key'])->count();
            unset($r['_key']);

            return $r;
        });

        return ['structure' => $st, 'term' => $term, 'settings' => $this->settings($school), 'rows' => $rows->values()];
    }

    /** Indeks mapel pengurai seri: Bahasa Indonesia, Matematika, Bahasa Inggris (bila ada). */
    private function tieIndexes(array $subjects): array
    {
        $out = [];
        foreach ($subjects as $i => $s) {
            $n = mb_strtolower($s['name']);
            if (str_contains($n, 'bahasa indonesia') || str_contains($n, 'matematika') || str_contains($n, 'bahasa inggris')) {
                $out[] = $i;
            }
        }

        return $out;
    }

    public function saveEntry(School $school, Classroom $c, Student $s, array $d, int $by): void
    {
        abort_unless($s->classroom_id === $c->id, 404);
        $st = $this->structure($school);
        $e = LegerEntry::query()->firstOrNew(['student_id' => $s->id, 'term' => $this->term($school)], ['classroom_id' => $c->id]);
        $e->classroom_id = $c->id;

        if (array_key_exists('scores', $d)) {
            $e->scores = array_map(function ($v) {
                if ($v === null || $v === '') {
                    return null;
                }
                $n = (float) str_replace(',', '.', (string) $v);
                if (! is_numeric(str_replace(',', '.', (string) $v)) || $n < 0 || $n > 100) {
                    throw ValidationException::withMessages(['scores' => 'Nilai harus 0 sampai 100.']);
                }

                return $n;
            }, array_slice(array_pad((array) $d['scores'], count($st['subjects']), null), 0, count($st['subjects'])));
        }
        if (array_key_exists('competencies', $d)) {
            $e->competencies = array_map(fn ($p) => [mb_substr((string) ($p[0] ?? ''), 0, 1000), mb_substr((string) ($p[1] ?? ''), 0, 1000)],
                array_slice(array_pad((array) $d['competencies'], count($st['subjects']), ['', '']), 0, count($st['subjects'])));
        }
        if (array_key_exists('extras', $d)) {
            $e->extras = array_map(fn ($v) => mb_substr(trim((string) $v), 0, 20), array_slice(array_pad((array) $d['extras'], count($st['extracurriculars']), ''), 0, count($st['extracurriculars'])));
        }
        if (array_key_exists('absences', $d)) {
            $e->absences = array_map(function ($v) {
                $n = (int) $v;
                if ($n < 0 || $n > 366) {
                    throw ValidationException::withMessages(['absences' => 'Isi angka hari 0 sampai 366.']);
                }

                return $n;
            }, array_slice(array_pad((array) $d['absences'], 3, 0), 0, 3));
        }
        $e->updated_by = $by;
        $e->save();
    }

    /** "Terapkan teks siswa pertama ke semua" untuk satu mapel. */
    public function applyCompetency(School $school, Classroom $c, int $subject, int $by): int
    {
        $sheet = $this->sheet($school, $c);
        $first = $sheet['rows'][0]['competencies'][$subject] ?? null;
        abort_if($first === null, 422, 'Kelas belum punya siswa atau mapel tidak ada.');
        foreach ($sheet['rows'] as $r) {
            $comp = $r['competencies'];
            $comp[$subject] = $first;
            $this->saveEntry($school, $c, Student::findOrFail($r['student']['id']), ['competencies' => $comp], $by);
        }

        return count($sheet['rows']);
    }

    public function phase(Classroom $c): string
    {
        $g = strtoupper(strtok(trim($c->grade ?: $c->name), ' -'));

        return in_array($g, ['X', '10'], true) ? 'E' : 'F';
    }

    public function classesForAdmin(School $school): Collection
    {
        $term = $this->term($school);
        $dist = LegerDistribution::query()->where('term', $term)->get()->keyBy('classroom_id');

        return Classroom::query()->with('homeroomTeacher:id,name')->withCount(['students' => fn ($q) => $q->where('is_active', true)])->orderBy('name')->get()
            ->map(function (Classroom $c) use ($dist) {
                $d = $dist->get($c->id);
                $state = ! $c->homeroom_teacher_id ? 'no_homeroom' : (! $d ? 'pending' : ($d->teacher_id === $c->homeroom_teacher_id ? 'distributed' : 'redistribute'));

                return ['id' => $c->id, 'name' => $c->name, 'students' => $c->students_count, 'homeroom' => $c->homeroomTeacher?->only(['id', 'name']),
                    'state' => $state, 'distributed_at' => $d?->distributed_at?->toIso8601String()];
            });
    }
}
