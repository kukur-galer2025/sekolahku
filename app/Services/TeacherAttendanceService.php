<?php

namespace App\Services;

use App\Models\Classroom;
use App\Models\Schedule;
use App\Models\School;
use App\Models\Teacher;
use App\Models\TeacherAttendance;
use App\Models\TeacherClassEntry;
use App\Models\User;
use App\Models\UserDevice;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Absensi guru dua langkah (alur prototipe):
 *   ① Hadir di sekolah  — scan QR kehadiran guru (dicetak admin, ditempel di ruang guru/gerbang) dari WiFi sekolah.
 *   ② Masuk kelas       — per blok jadwal mengajar hari ini, scan QR kelas tersebut. Terbuka setelah langkah ①.
 * Terlambat bila masuk lebih dari LATE_MINUTES setelah jam mulai. Blok yang tidak di-scan sampai jam selesai = tidak masuk.
 */
class TeacherAttendanceService
{
    public const LATE_MINUTES = 10;

    public const EARLY_MINUTES = 15;

    private const ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    public const STATUS = [
        'hadir' => ['Hadir', 'ok'], 'telat' => ['Terlambat', 'wr'], 'belum' => ['Belum masuk', 'wr'],
        'tidak' => ['Tidak masuk', 'er'], 'jadwal' => ['Terjadwal', 'mu'],
    ];

    public function __construct(private ClassQrCode $qr, private SchoolNetwork $network, private Timetable $tt) {}

    // ── QR kehadiran guru (per sekolah) ──────────────────────────────

    private function gateSecret(School $school): string
    {
        $secret = (string) $school->setting('attendance.gate_secret');
        if ($secret === '') {
            $secret = Str::random(40);
            $settings = $school->settings ?? [];
            data_set($settings, 'attendance.gate_secret', $secret);
            $school->forceFill(['settings' => $settings])->save();
        }

        return $secret;
    }

    public function gateCode(School $school): string
    {
        $bytes = hex2bin(substr(hash_hmac('sha256', $school->id.'|gate', $this->gateSecret($school).'|'.config('app.key')), 0, 16));
        $out = '';
        foreach (str_split($bytes) as $b) {
            $out .= self::ALPHABET[ord($b) % 32];
        }

        return $out;
    }

    public function gatePayload(School $school): string
    {
        return 'MPG-'.$school->id.'-'.$this->gateCode($school);
    }

    public function rotateGate(School $school): void
    {
        $settings = $school->settings ?? [];
        data_set($settings, 'attendance.gate_secret', Str::random(40));
        $school->forceFill(['settings' => $settings])->save();
    }

    // ── Langkah ① ────────────────────────────────────────────────────

    public function checkIn(School $school, Teacher $teacher, string $payload, ?string $ip, ?UserDevice $device = null, ?CarbonImmutable $at = null): TeacherAttendance
    {
        $now = ($at ?? CarbonImmutable::now())->setTimezone($school->timezone);
        $this->assertOnSite($school, $now, $ip);

        if (! preg_match('/^MPG-(\d{1,10})-([A-Z0-9]{6,10})$/', strtoupper(trim($payload)), $m)) {
            throw ValidationException::withMessages(['code' => 'Ini bukan QR kehadiran guru. Scan QR yang ditempel di ruang guru.']);
        }
        if ((int) $m[1] !== $school->id || ! hash_equals($this->gateCode($school), $m[2])) {
            throw ValidationException::withMessages(['code' => 'QR kehadiran guru tidak berlaku. Minta admin mencetak QR terbaru.']);
        }

        return $this->recordCheckIn($teacher, $now, 'qr', $ip, $device?->id, $teacher->user_id);
    }

    public function manualCheckIn(School $school, Teacher $teacher, string $date, User $by): TeacherAttendance
    {
        $day = CarbonImmutable::parse($date, $school->timezone);
        $at = $day->isSameDay($school->localNow()) ? $school->localNow() : $day->setTime(7, 0);

        return $this->recordCheckIn($teacher, $at, 'manual', request()?->ip(), null, $by->id);
    }

    private function recordCheckIn(Teacher $teacher, CarbonImmutable $at, string $method, ?string $ip, ?int $deviceId, ?int $by): TeacherAttendance
    {
        $date = $at->toDateString();
        if ($old = TeacherAttendance::query()->where('teacher_id', $teacher->id)->whereDate('date', $date)->first()) {
            throw ValidationException::withMessages(['code' => 'Kehadiran hari ini sudah tercatat ('.$old->checked_in_at->setTimezone($at->getTimezone())->format('H:i').').']);
        }
        try {
            return TeacherAttendance::create([
                'teacher_id' => $teacher->id, 'date' => $date, 'checked_in_at' => $at->utc(), 'method' => $method,
                'ip' => $ip, 'device_id' => $deviceId, 'recorded_by' => $by,
            ]);
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['code' => 'Kehadiran hari ini sudah tercatat.']);
        }
    }

    // ── Langkah ② ────────────────────────────────────────────────────

    public function enterClass(School $school, Teacher $teacher, Schedule $schedule, string $payload, ?string $ip, ?CarbonImmutable $at = null): TeacherClassEntry
    {
        $now = ($at ?? CarbonImmutable::now())->setTimezone($school->timezone);
        $this->assertOnSite($school, $now, $ip);

        if ($schedule->teacher_id !== $teacher->id || $schedule->day !== $now->dayOfWeekIso) {
            throw ValidationException::withMessages(['code' => 'Ini bukan jadwal mengajarmu hari ini.']);
        }
        if (! $this->checkedIn($teacher, $now->toDateString())) {
            throw ValidationException::withMessages(['code' => 'Absen kehadiran di sekolah dulu, baru bisa masuk kelas.']);
        }

        $parsed = $this->qr->parse($payload);
        $classroom = $parsed ? Classroom::find($parsed[0]) : null;
        if (! $classroom || $classroom->id !== $schedule->classroom_id) {
            throw ValidationException::withMessages(['code' => 'QR ini bukan untuk kelas '.($schedule->classroom?->name ?? 'jadwal ini').'.']);
        }
        if (! $this->qr->verify($classroom, $parsed[1], $school->qrMode(), $now)) {
            throw ValidationException::withMessages(['code' => 'QR kelas tidak berlaku. Scan QR terbaru di kelas '.$classroom->name.'.']);
        }

        [$start, $end] = $this->window($schedule, $now);
        if ($start && $now->lt($start->subMinutes(self::EARLY_MINUTES))) {
            throw ValidationException::withMessages(['code' => 'Belum waktunya. Scan bisa dilakukan mulai '.$start->subMinutes(self::EARLY_MINUTES)->format('H:i').'.']);
        }
        if ($end && $now->gt($end)) {
            throw ValidationException::withMessages(['code' => 'Jam pelajaran ini sudah selesai ('.$end->format('H:i').'). Hubungi admin bila perlu dicatat manual.']);
        }

        return $this->recordEntry($teacher, $schedule, $now, $start && $now->gt($start->addMinutes(self::LATE_MINUTES)), 'qr', $ip, $teacher->user_id);
    }

    public function manualEnter(School $school, Schedule $schedule, string $date, User $by): TeacherClassEntry
    {
        $teacher = $schedule->teacher ?? throw ValidationException::withMessages(['schedule_id' => 'Jadwal ini belum punya guru.']);
        $day = CarbonImmutable::parse($date, $school->timezone);
        if ($schedule->day !== $day->dayOfWeekIso) {
            throw ValidationException::withMessages(['schedule_id' => 'Jadwal ini bukan untuk hari tersebut.']);
        }
        if (! $this->checkedIn($teacher, $day->toDateString())) {
            throw ValidationException::withMessages(['schedule_id' => 'Catat hadir sekolah guru ini dulu, baru bisa catat masuk kelas.']);
        }
        [$start] = $this->window($schedule, $day);
        $at = $day->isSameDay($school->localNow()) ? $school->localNow() : ($start ?? $day->setTime(7, 0));

        return $this->recordEntry($teacher, $schedule, $at, false, 'manual', request()?->ip(), $by->id);
    }

    private function recordEntry(Teacher $teacher, Schedule $schedule, CarbonImmutable $at, bool $late, string $method, ?string $ip, ?int $by): TeacherClassEntry
    {
        $date = $at->toDateString();
        if (TeacherClassEntry::query()->where('teacher_id', $teacher->id)->where('schedule_id', $schedule->id)->whereDate('date', $date)->exists()) {
            throw ValidationException::withMessages(['code' => 'Masuk kelas ini sudah tercatat.']);
        }
        try {
            return TeacherClassEntry::create([
                'teacher_id' => $teacher->id, 'schedule_id' => $schedule->id, 'classroom_id' => $schedule->classroom_id,
                'subject' => $schedule->subject, 'period_start' => $schedule->period_start,
                'period_end' => $schedule->period_start + max(1, $schedule->period_count) - 1,
                'date' => $date, 'entered_at' => $at->utc(), 'is_late' => $late, 'method' => $method, 'ip' => $ip, 'recorded_by' => $by,
            ]);
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['code' => 'Masuk kelas ini sudah tercatat.']);
        }
    }

    private function assertOnSite(School $school, CarbonImmutable $now, ?string $ip): void
    {
        if (! $school->isSchoolDay($now)) {
            throw ValidationException::withMessages(['code' => 'Hari ini bukan hari sekolah.']);
        }
        if (! $this->network->isConfigured($school)) {
            throw ValidationException::withMessages(['code' => 'Jaringan WiFi sekolah belum didaftarkan admin.']);
        }
        if (! $this->network->allows($school, $ip)) {
            throw ValidationException::withMessages(['code' => 'Sambungkan HP ke WiFi sekolah dan matikan data seluler, lalu scan lagi.']);
        }
    }

    public function checkedIn(Teacher $teacher, string $date): bool
    {
        return TeacherAttendance::query()->where('teacher_id', $teacher->id)->whereDate('date', $date)->exists();
    }

    // ── Status & rekap ───────────────────────────────────────────────

    /** @return array{0:?CarbonImmutable,1:?CarbonImmutable} jam mulai & selesai blok pada tanggal itu (zona sekolah) */
    public function window(Schedule $s, CarbonImmutable $date): array
    {
        [$from, $to] = $this->tt->span($s->period_start, $s->period_count);
        $at = fn (?string $t) => $t ? $date->startOfDay()->setTimeFromTimeString($t) : null;

        return [$at($from), $at($to)];
    }

    public function status(Schedule $s, ?TeacherClassEntry $entry, CarbonImmutable $date, CarbonImmutable $now): string
    {
        if ($entry) {
            return $entry->is_late ? 'telat' : 'hadir';
        }
        if ($date->toDateString() > $now->toDateString()) {
            return 'jadwal';
        }
        if ($date->toDateString() < $now->toDateString()) {
            return 'tidak';
        }
        [$start, $end] = $this->window($s, $date);

        return ($start && $now->lt($start)) ? 'jadwal' : (($end && $now->lt($end)) ? 'belum' : 'tidak');
    }

    /** Blok jadwal semua guru (atau satu guru) pada tanggal tertentu beserta status masuk kelas. */
    public function blocks(School $school, CarbonImmutable $date, ?Teacher $teacher = null): Collection
    {
        if (! $school->isSchoolDay($date)) {
            return collect();
        }
        $now = $school->localNow();
        $schedules = Schedule::query()->with(['classroom:id,name', 'teacher:id,name,subject'])->where('day', $date->dayOfWeekIso)->whereNotNull('teacher_id')
            ->when($teacher, fn ($q) => $q->where('teacher_id', $teacher->id))->orderBy('period_start')->get();
        $entries = TeacherClassEntry::query()->whereDate('date', $date->toDateString())->whereIn('schedule_id', $schedules->pluck('id'))->get()->keyBy('schedule_id');

        return $schedules->map(function (Schedule $s) use ($entries, $date, $now, $school) {
            $e = $entries->get($s->id);
            [$start, $end] = $this->window($s, $date);
            $st = $this->status($s, $e, $date, $now);

            return [
                'schedule_id' => $s->id, 'teacher' => $s->teacher?->only(['id', 'name', 'subject']), 'classroom' => $s->classroom?->only(['id', 'name']),
                'subject' => $s->subject, 'period_start' => $s->period_start, 'period_end' => $s->period_start + max(1, $s->period_count) - 1,
                'period_count' => $s->period_count, 'from' => $start?->format('H:i'), 'to' => $end?->format('H:i'),
                'entry' => $e ? ['time' => $e->entered_at->setTimezone($school->timezone)->format('H:i'), 'method' => $e->method, 'is_late' => $e->is_late] : null,
                'status' => $st, 'status_label' => self::STATUS[$st][0], 'status_tone' => self::STATUS[$st][1],
            ];
        })->sortBy(fn ($b) => sprintf('%02d %s', $b['period_start'], $b['classroom']['name'] ?? ''))->values();
    }

    /** Rekap per guru (alur "Rekap per guru" prototipe). */
    public function report(School $school, CarbonImmutable $date): array
    {
        $blocks = $this->blocks($school, $date);
        $checks = TeacherAttendance::query()->whereDate('date', $date->toDateString())->get()->keyBy('teacher_id');
        $byTeacher = $blocks->groupBy(fn ($b) => $b['teacher']['id']);

        $list = Teacher::query()->orderBy('name')->get(['id', 'name', 'subject', 'nik', 'user_id']);
        $av = \App\Support\Avatar::map($list->pluck('user_id'));
        $rows = $list->map(function (Teacher $t) use ($byTeacher, $checks, $school, $av) {
            $L = $byTeacher->get($t->id, collect());
            $c = fn ($k) => $L->where('status', $k)->count();
            $ci = $checks->get($t->id);
            $row = [
                'teacher' => $t->only(['id', 'name', 'subject', 'nik']) + ['photo_url' => $av[$t->user_id] ?? null],
                'check_in' => $ci ? ['time' => $ci->checked_in_at->setTimezone($school->timezone)->format('H:i'), 'method' => $ci->method] : null,
                'blocks' => $L->count(), 'hours' => (int) $L->sum('period_count'),
                'hadir' => $c('hadir'), 'telat' => $c('telat'), 'belum' => $c('belum'), 'tidak' => $c('tidak'),
            ];
            $row['state'] = match (true) {
                ! $L->count() => ['Tidak ada jadwal', 'mu'],
                ! $ci && $L->contains(fn ($b) => $b['status'] !== 'jadwal') => ['Belum absen sekolah', 'er'],
                $row['tidak'] > 0 => ['Ada yang tidak masuk', 'er'],
                $row['belum'] > 0 => ['Sedang berjalan', 'wr'],
                $row['hadir'] + $row['telat'] === $L->count() => ['Lengkap', 'ok'],
                default => ['Terjadwal', 'mu'],
            };

            return $row;
        });

        $count = fn ($k) => $blocks->where('status', $k)->count();

        return [
            'date' => $date->toDateString(), 'is_school_day' => $school->isSchoolDay($date),
            'summary' => [
                'teachers' => $rows->count(), 'checked_in' => $checks->count(), 'blocks' => $blocks->count(),
                'entered' => $count('hadir') + $count('telat'), 'telat' => $count('telat'), 'belum' => $count('belum'), 'tidak' => $count('tidak'),
            ],
            'teachers' => $rows->values(), 'blocks' => $blocks,
        ];
    }

    /** Halaman "Kehadiran saya" guru. */
    public function mine(School $school, Teacher $teacher): array
    {
        $now = $school->localNow();
        $ci = TeacherAttendance::query()->where('teacher_id', $teacher->id)->whereDate('date', $now->toDateString())->first();
        $history = collect()
            ->merge(TeacherAttendance::query()->where('teacher_id', $teacher->id)->latest('date')->limit(60)->get()
                ->map(fn ($a) => ['date' => $a->date->format('Y-m-d'), 'time' => $a->checked_in_at->setTimezone($school->timezone)->format('H:i'),
                    'kind' => 'Hadir di sekolah', 'status' => 'hadir', 'method' => $a->method]))
            ->merge(TeacherClassEntry::query()->with('classroom:id,name')->where('teacher_id', $teacher->id)->latest('date')->limit(120)->get()
                ->map(fn ($e) => ['date' => $e->date->format('Y-m-d'), 'time' => $e->entered_at->setTimezone($school->timezone)->format('H:i'),
                    'kind' => 'Masuk kelas '.($e->classroom?->name ?? '-').' · jam ke-'.$e->period_start.($e->period_end > $e->period_start ? '–'.$e->period_end : ''),
                    'status' => $e->is_late ? 'telat' : 'hadir', 'method' => $e->method]))
            ->sortByDesc(fn ($x) => $x['date'].' '.$x['time'])->take(120)->values();

        return [
            'date' => $now->toDateString(), 'is_school_day' => $school->isSchoolDay($now), 'late_minutes' => self::LATE_MINUTES,
            'check_in' => $ci ? ['time' => $ci->checked_in_at->setTimezone($school->timezone)->format('H:i'), 'method' => $ci->method] : null,
            'blocks' => $this->blocks($school, $now, $teacher),
            'history' => $history,
        ];
    }
}
