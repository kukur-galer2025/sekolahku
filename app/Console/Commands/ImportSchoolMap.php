<?php

namespace App\Console\Commands;

use App\Models\Classroom;
use App\Models\School;
use App\Models\SchoolRoom;
use App\Tenancy\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Memasang denah dari berkas JSON (format "Smart School Map Editor v1") ke SATU sekolah.
 * Contoh: php artisan mypresence:denah smkcbmpwt database/data/denah-smk-citra-bangsa.json
 * Kategori lama x/xi/xii → ruang kelas tingkat X/XI/XII; ruang kelas otomatis ditautkan ke Kelas & Rombel bila namanya sama.
 */
class ImportSchoolMap extends Command
{
    protected $signature = 'mypresence:denah {subdomain} {file} {--force : Timpa denah yang sudah ada}';

    protected $description = 'Pasang denah interaktif dari berkas JSON ke satu sekolah';

    public function handle(TenantContext $tenant): int
    {
        $school = School::query()->where('subdomain', $this->argument('subdomain'))->first();
        if (! $school) {
            $this->error('Sekolah dengan link portal "'.$this->argument('subdomain').'" tidak ditemukan.');

            return self::FAILURE;
        }
        $path = $this->argument('file');
        $path = is_file($path) ? $path : base_path($path);
        $rows = is_file($path) ? json_decode((string) file_get_contents($path), true) : null;
        if (! is_array($rows)) {
            $this->error("Berkas JSON tidak terbaca: $path");

            return self::FAILURE;
        }

        return $tenant->run($school, function () use ($rows, $school) {
            if (SchoolRoom::query()->exists() && ! $this->option('force')) {
                $this->error("{$school->name} sudah punya denah (".SchoolRoom::query()->count().' ruang). Pakai --force untuk menimpa.');

                return self::FAILURE;
            }
            $norm = fn ($s) => strtoupper(preg_replace('/\s+/', ' ', trim((string) $s)));
            $classes = Classroom::query()->get(['id', 'name'])->keyBy(fn ($c) => $norm($c->name));
            $grades = $school->grades();
            $linked = 0;

            DB::transaction(function () use ($rows, $norm, $classes, $grades, &$linked) {
                SchoolRoom::query()->delete();
                foreach ($rows as $r) {
                    [$category, $grade] = $this->category((string) ($r['category'] ?? ''), (string) ($r['name'] ?? ''), $grades);
                    $classroom = $category === 'kelas' ? $classes->get($norm($r['name'] ?? '')) : null;
                    $linked += $classroom ? 1 : 0;
                    SchoolRoom::create([
                        'classroom_id' => $classroom?->id, 'building' => (string) ($r['building'] ?? 'A'), 'floor' => (int) ($r['floor'] ?? 1),
                        'name' => mb_substr((string) ($r['name'] ?? 'Ruang'), 0, 100), 'category' => $category, 'grade' => $grade,
                        'capacity' => (int) ($r['capacity'] ?? 0), 'x' => (float) $r['x'], 'y' => (float) $r['y'],
                        'width' => (float) $r['width'], 'height' => (float) $r['height'],
                    ]);
                }
            });
            $this->info("Denah {$school->name}: ".count($rows)." ruang dipasang, $linked ruang kelas tertaut ke Kelas & Rombel.");

            return self::SUCCESS;
        });
    }

    /** @return array{0:string,1:?string} */
    private function category(string $old, string $name, array $grades): array
    {
        $g = strtoupper($old);
        if (in_array($g, ['X', 'XI', 'XII'], true)) {
            return ['kelas', in_array($g, $grades, true) ? $g : null];
        }
        if (in_array($old, ['kelas', 'lab', 'kantor', 'fasilitas'], true)) {
            return [$old, null];
        }
        $n = strtoupper($name);

        return match (true) {
            str_starts_with($n, 'LAB') => ['lab', null],
            (bool) preg_match('/\b(GURU|WAKA|STAF|SERVER|BK|OSIS|SPMB|SARPRAS|TU|KEPALA|KAPRODI)\b/', $n) => ['kantor', null],
            (bool) preg_match('/^(X|XI|XII|VII|VIII|IX)\s/', $n) => ['kelas', in_array(strtok($n, ' '), $grades, true) ? strtok($n, ' ') : null],
            default => ['fasilitas', null],
        };
    }
}
