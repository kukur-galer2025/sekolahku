<?php

namespace App\Services;

use App\Models\Classroom;
use App\Models\LessonPeriod;
use App\Models\Schedule;
use App\Models\Teacher;
use Illuminate\Support\Collection;

/**
 * Jadwal untuk PWA: jam ke-N (tanpa istirahat) → jam mulai/selesai menurut Jam KBM sekolah.
 */
class Timetable
{
    public const DAYS = [1 => 'Senin', 2 => 'Selasa', 3 => 'Rabu', 4 => 'Kamis', 5 => 'Jumat', 6 => 'Sabtu', 7 => 'Minggu'];

    private ?Collection $periods = null;

    /** Semua baris Jam KBM (termasuk istirahat), dengan nomor jam ke-N untuk baris pelajaran. */
    public function periods(): array
    {
        $n = 0;

        return $this->all()->map(function (LessonPeriod $p) use (&$n) {
            return ['n' => $p->is_break ? null : ++$n, 'label' => $p->label, 'from' => substr($p->starts_at, 0, 5), 'to' => substr($p->ends_at, 0, 5), 'is_break' => (bool) $p->is_break];
        })->values()->all();
    }

    private function all(): Collection
    {
        return $this->periods ??= LessonPeriod::query()->orderBy('sort')->get();
    }

    /** [from, to] untuk jam ke-a s.d. jam ke-b. */
    public function span(int $start, int $count): array
    {
        $lessons = $this->all()->where('is_break', false)->values();
        $a = $lessons->get($start - 1);
        $b = $lessons->get($start + max(1, $count) - 2);

        return [$a ? substr($a->starts_at, 0, 5) : null, $b ? substr($b->ends_at, 0, 5) : null];
    }

    public function blocks(?Classroom $classroom = null, ?Teacher $teacher = null, ?int $day = null): Collection
    {
        return Schedule::query()->with(['classroom:id,name', 'teacher:id,name'])
            ->when($classroom, fn ($q) => $q->where('classroom_id', $classroom->id))
            ->when($teacher, fn ($q) => $q->where('teacher_id', $teacher->id))
            ->when($day, fn ($q) => $q->where('day', $day))
            ->orderBy('day')->orderBy('period_start')->get()
            ->map(fn (Schedule $s) => $this->present($s));
    }

    public function present(Schedule $s): array
    {
        [$from, $to] = $this->span($s->period_start, $s->period_count);

        return [
            'id' => $s->id, 'day' => $s->day, 'day_name' => self::DAYS[$s->day] ?? (string) $s->day,
            'period_start' => $s->period_start, 'period_end' => $s->period_start + max(1, $s->period_count) - 1, 'period_count' => $s->period_count,
            'from' => $from, 'to' => $to, 'subject' => $s->subject,
            'classroom' => $s->classroom?->only(['id', 'name']), 'teacher' => $s->teacher?->only(['id', 'name']),
        ];
    }
}
