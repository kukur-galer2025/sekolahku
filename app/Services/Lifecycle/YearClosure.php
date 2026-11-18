<?php

namespace App\Services\Lifecycle;

use App\Enums\Role;
use App\Models\AcademicYearClosure;
use App\Models\Classroom;
use App\Models\School;
use App\Models\Student;
use App\Models\StudentEnrollment;
use App\Models\User;
use App\Services\Notify\Notifier;
use App\Services\Platform\AuditLogger;
use App\Support\SchoolLevel;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Tutup Tahun Ajaran: naik kelas, tinggal kelas, dan kelulusan dalam satu langkah.
 * Kelas tidak dipindah atau diganti nama; yang pindah adalah siswanya (7A → 8A, 9A → lulus).
 * Riwayat tahun lama dikunci di student_enrollments; salinan disimpan agar bisa dibatalkan 7 hari.
 */
class YearClosure
{
    public const LULUS = 'lulus';

    public const TETAP = 'tetap';

    public function __construct(private AuditLogger $audit, private Notifier $notifier) {}

    public static function nextYear(string $year): string
    {
        [$a] = explode('/', $year) + [0 => (int) date('Y')];

        return ((int) $a + 1).'/'.((int) $a + 2);
    }

    /** @return Collection<int, Classroom> kelas yang diurutkan per tingkat lalu nama */
    private function classrooms(School $school): Collection
    {
        $order = array_flip($school->grades());

        return Classroom::query()->get()
            ->sortBy([fn ($a, $b) => ($order[$a->grade] ?? 99) <=> ($order[$b->grade] ?? 99), ['name', 'asc']])->values();
    }

    /** Tujuan bawaan per kelas: tingkat berikutnya dengan penanda rombel yang sama; tingkat akhir → lulus. */
    public function defaultMapping(School $school): array
    {
        $classes = $this->classrooms($school);
        $map = [];
        foreach ($classes as $c) {
            if (! $c->grade) {
                $map[$c->id] = self::TETAP;

                continue;
            }
            $next = SchoolLevel::nextGrade($c->grade, $school->schoolLevel());
            if ($next === null) {
                $map[$c->id] = self::LULUS;

                continue;
            }
            $suffix = SchoolLevel::suffixOf($c->name);
            $target = $classes->first(fn ($t) => $t->grade === $next && SchoolLevel::suffixOf($t->name) === $suffix)
                ?? ($classes->where('grade', $next)->count() === 1 ? $classes->firstWhere('grade', $next) : null);
            $map[$c->id] = $target ? $target->id : self::TETAP;
        }

        return $map;
    }

    /** Data untuk halaman: kelas, jumlah siswa aktif, tujuan bawaan, daftar siswa (untuk centang tinggal kelas). */
    public function preview(School $school): array
    {
        $students = $this->activeStudents()->groupBy('classroom_id');
        $map = $this->defaultMapping($school);
        $from = $school->currentAcademicYear();

        $blocked = $this->blockedReason($from);

        return [
            'from' => $from,
            'to' => self::nextYear($from),
            'already' => $blocked !== null,
            'already_message' => $blocked,
            'classes' => $this->classrooms($school)->map(fn (Classroom $c) => [
                'id' => $c->id, 'name' => $c->name, 'grade' => $c->grade,
                'target' => $map[$c->id] ?? self::TETAP,
                'students' => ($students[$c->id] ?? collect())->map(fn ($s) => ['id' => $s->id, 'name' => $s->name, 'nis' => $s->nis])->values()->all(),
            ])->all(),
            'unassigned' => ($students[''] ?? collect())->count(),
        ];
    }

    /** Jarak minimal antara dua penutupan berturut-turut (cegah siswa naik dua tingkat karena tutup ganda). */
    public const MIN_MONTHS_BETWEEN = 6;

    /** Alasan tahun ajaran $from belum boleh ditutup, atau null bila boleh. */
    public function blockedReason(string $from): ?string
    {
        if (AcademicYearClosure::query()->where('from_year', $from)->whereNull('undone_at')->exists()) {
            return "Tahun ajaran {$from} sudah ditutup.";
        }

        // Tahun ajaran yang baru saja dibuka oleh penutupan sebelumnya: jangan langsung ditutup lagi.
        $opened = AcademicYearClosure::query()->where('to_year', $from)->whereNull('undone_at')->latest('id')->first();
        if ($opened && $opened->created_at->gt(now()->subMonths(self::MIN_MONTHS_BETWEEN))) {
            return "Tahun ajaran {$opened->from_year} sudah ditutup pada ".$opened->created_at->timezone('Asia/Jakarta')->format('d/m/Y')
                ." dan tahun ajaran {$from} baru dimulai. Penutupan berikutnya bisa dilakukan menjelang akhir tahun ajaran {$from}"
                .' (paling cepat '.$opened->created_at->copy()->addMonths(self::MIN_MONTHS_BETWEEN)->timezone('Asia/Jakarta')->format('d/m/Y').').';
        }

        return null;
    }

    private function activeStudents(): Collection
    {
        return Student::query()->where('is_active', true)->where('status', 'aktif')->orderBy('name')->get(['id', 'user_id', 'classroom_id', 'name', 'nis', 'status', 'is_active', 'left_at', 'left_reason']);
    }

    /**
     * @param  array<int|string, int|string>  $mapping  classroom_id → classroom_id | 'lulus' | 'tetap'
     * @param  list<int>  $repeaters  siswa tinggal kelas (tetap di kelasnya, tidak lulus)
     */
    public function execute(School $school, array $mapping, array $repeaters, User $by): AcademicYearClosure
    {
        $from = $school->currentAcademicYear();
        $to = self::nextYear($from);
        if ($reason = $this->blockedReason($from)) {
            throw ValidationException::withMessages(['mapping' => $reason]);
        }

        $classes = Classroom::query()->get()->keyBy('id');
        $targets = [];
        foreach ($classes as $id => $c) {
            $t = $mapping[$id] ?? self::TETAP;
            if ($t !== self::LULUS && $t !== self::TETAP) {
                $t = (int) $t;
                if (! $classes->has($t)) {
                    throw ValidationException::withMessages(['mapping' => "Kelas tujuan untuk {$c->name} tidak ditemukan."]);
                }
            }
            $targets[$id] = $t;
        }
        $repeaters = array_flip(array_map('intval', $repeaters));

        $closure = DB::transaction(function () use ($school, $from, $to, $classes, $targets, $repeaters, $by) {
            $snapshot = ['academic_year' => $school->setting('academic_year'), 'students' => [], 'from_status' => []];
            $sum = ['naik' => 0, 'tinggal' => 0, 'lulus' => 0, 'tetap' => 0];
            $graduates = [];

            $fromRows = StudentEnrollment::query()->where('academic_year', $from)->get()->keyBy('student_id');

            foreach ($this->activeStudents() as $s) {
                $snapshot['students'][] = $s->only(['id', 'classroom_id', 'status', 'is_active']) + ['left_at' => $s->left_at?->toDateString(), 'left_reason' => $s->left_reason];
                $snapshot['from_status'][$s->id] = $fromRows[$s->id]->status ?? null;
                $cur = $s->classroom_id ? $classes[$s->classroom_id] ?? null : null;
                $t = $cur ? $targets[$cur->id] : self::TETAP;

                if ($cur && isset($repeaters[$s->id])) {
                    [$kind, $dest] = ['tinggal', $cur];
                } elseif ($t === self::LULUS) {
                    [$kind, $dest] = ['lulus', null];
                } elseif ($t === self::TETAP) {
                    [$kind, $dest] = ['tetap', $cur];
                } else {
                    [$kind, $dest] = ['naik', $classes[$t]];
                }
                $sum[$kind]++;

                StudentEnrollment::query()->updateOrCreate(['student_id' => $s->id, 'academic_year' => $from], [
                    'classroom_id' => $cur?->id, 'classroom_name' => $cur?->name, 'grade' => $cur?->grade,
                    'status' => $kind === 'tetap' ? 'aktif' : $kind,
                ]);

                if ($kind === 'lulus') {
                    $s->forceFill(['status' => 'lulus', 'is_active' => false, 'left_at' => now()->toDateString(), 'left_reason' => "Lulus tahun ajaran {$from}", 'classroom_id' => null])->saveQuietly();
                    $graduates[] = $s->user_id;

                    continue;
                }

                $s->forceFill(['classroom_id' => $dest?->id])->saveQuietly();
                StudentEnrollment::query()->updateOrCreate(['student_id' => $s->id, 'academic_year' => $to], [
                    'classroom_id' => $dest?->id, 'classroom_name' => $dest?->name, 'grade' => $dest?->grade, 'status' => 'aktif',
                ]);
            }

            $school->forceFill(['settings' => array_replace($school->settings ?? [], ['academic_year' => $to])])->save();

            return [AcademicYearClosure::query()->create([
                'from_year' => $from, 'to_year' => $to, 'summary' => $sum, 'snapshot' => $snapshot, 'created_by' => $by->id,
            ]), $graduates];
        });
        [$record, $graduates] = $closure;

        $s = $record->summary;
        $this->audit->log('year.closed', "Tutup tahun ajaran {$from} → {$to}: {$s['naik']} naik, {$s['tinggal']} tinggal kelas, {$s['lulus']} lulus", $school, $by, $s);
        if ($graduates) {
            $this->notifier->send(User::query()->whereIn('id', $graduates)->get(), 'graduated', 'Selamat atas kelulusanmu! 🎓',
                'Akunmu tetap bisa dibuka '.Student::ALUMNI_DAYS.' hari untuk melihat rekap kehadiran. Minta Surat Keterangan Kehadiran ke admin sekolah bila perlu.', '#/rekap');
        }
        $this->notifier->send(User::query()->where('role', Role::Admin->value)->where('is_active', true)->get(), 'year_closed',
            "Tahun ajaran {$to} dimulai", "{$s['naik']} siswa naik kelas, {$s['tinggal']} tinggal kelas, {$s['lulus']} lulus. Bisa dibatalkan sampai ".now()->addDays(AcademicYearClosure::UNDO_DAYS)->translatedFormat('j F').'.', '/tahun-ajaran');

        return $record;
    }

    /** Batalkan penutupan terakhir (maks. 7 hari). Kehadiran yang tercatat setelahnya tidak dihapus. */
    public function undo(School $school, AcademicYearClosure $c, User $by): void
    {
        $latest = AcademicYearClosure::query()->whereNull('undone_at')->latest('id')->first();
        if (! $c->canUndo() || ! $latest || $latest->id !== $c->id) {
            throw ValidationException::withMessages(['undo' => 'Penutupan ini tidak bisa dibatalkan lagi (lebih dari '.AcademicYearClosure::UNDO_DAYS.' hari atau bukan yang terakhir).']);
        }

        DB::transaction(function () use ($school, $c) {
            $snap = $c->snapshot;
            $ids = array_column($snap['students'], 'id');
            $students = Student::query()->whereIn('id', $ids)->get()->keyBy('id');
            foreach ($snap['students'] as $row) {
                $s = $students[$row['id']] ?? null;
                if (! $s) {
                    continue;
                }
                // Siswa yang setelah penutupan dikeluarkan/mutasi tidak dikembalikan otomatis.
                if (! in_array($s->status, ['aktif', 'lulus'], true)) {
                    continue;
                }
                $s->forceFill(['classroom_id' => $row['classroom_id'], 'status' => $row['status'], 'is_active' => $row['is_active'], 'left_at' => $row['left_at'], 'left_reason' => $row['left_reason']])->saveQuietly();
                $prev = $snap['from_status'][$row['id']] ?? $snap['from_status'][(string) $row['id']] ?? null;
                StudentEnrollment::query()->where('student_id', $s->id)->where('academic_year', $c->from_year)->update(['status' => $prev ?? 'aktif']);
            }
            StudentEnrollment::query()->whereIn('student_id', $ids)->where('academic_year', $c->to_year)->delete();

            $settings = $school->settings ?? [];
            if ($snap['academic_year'] === null || $snap['academic_year'] === '') {
                unset($settings['academic_year']);
            } else {
                $settings['academic_year'] = $snap['academic_year'];
            }
            $school->forceFill(['settings' => $settings])->save();
            $c->forceFill(['undone_at' => now()])->save();
        });

        $this->audit->log('year.undone', "Membatalkan tutup tahun ajaran {$c->from_year} → {$c->to_year}", $school, $by);
    }
}
