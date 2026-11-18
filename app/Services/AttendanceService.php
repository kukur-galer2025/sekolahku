<?php

namespace App\Services;

use App\Enums\AttendanceMethod;
use App\Enums\AttendanceStatus;
use App\Models\Attendance;
use App\Models\Classroom;
use App\Models\LessonPeriod;
use App\Models\Schedule;
use App\Models\School;
use App\Models\Student;
use App\Models\User;
use App\Models\UserDevice;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Aturan presensi MyPresence, dipindah dari prototipe (browser) ke server:
 * - Siswa scan QR kelasnya sendiri pada hari sekolah, dari WiFi sekolah, dari HP terdaftar,
 *   dan hanya saat jam pelajaran kelasnya berlangsung (bukan istirahat).
 * - Lewat batas (akhir jam ke-N), scan tetap diterima tetapi ditandai terlambat.
 * - Siswa yang tidak scan sampai batas dicatat Alpa otomatis oleh sistem.
 * - Guru/admin hanya bisa mengoreksi ke Hadir, Sakit, atau Izin.
 */
class AttendanceService
{
    public function __construct(private ClassQrCode $qr, private SchoolNetwork $network) {}

    /** Batas presensi hari itu dalam zona waktu sekolah. */
    public function cutoff(School $school, CarbonImmutable $localDate): CarbonImmutable
    {
        $period = LessonPeriod::query()
            ->where('is_break', false)
            ->orderBy('sort')
            ->skip($school->attendanceCutoffPeriod() - 1)
            ->first()
            ?? LessonPeriod::query()->where('is_break', false)->orderByDesc('sort')->first();

        $time = $period ? substr($period->ends_at, 0, 5) : '08:30';

        return $localDate->setTimeFromTimeString($time);
    }

    /**
     * Rentang waktu scan yang sah hari itu untuk satu kelas.
     *
     * - Jam pelajaran kelas itu hari ini menurut Jadwal (istirahat tidak termasuk).
     * - Ditambah toleransi datang awal sebelum jam pelajaran pertama (bawaan 30 menit).
     * - Kelas yang jadwalnya belum diisi sama sekali memakai seluruh Jam KBM sekolah.
     *
     * @return list<array{0:CarbonImmutable,1:CarbonImmutable}>
     */
    public function lessonWindows(School $school, Classroom $classroom, CarbonImmutable $localDate): array
    {
        $periods = LessonPeriod::query()->where('is_break', false)->orderBy('sort')->get()->values();
        if ($periods->isEmpty()) {
            return [];
        }

        $hasAnySchedule = Schedule::query()->where('classroom_id', $classroom->id)->exists();
        $covered = [];

        if (! $hasAnySchedule) {
            $covered = range(1, $periods->count());
        } else {
            $today = Schedule::query()->where('classroom_id', $classroom->id)->where('day', $localDate->dayOfWeekIso)->get();
            foreach ($today as $b) {
                foreach (range($b->period_start, $b->period_start + max(1, $b->period_count) - 1) as $i) {
                    $covered[$i] = $i;
                }
            }
            sort($covered);
        }

        $at = fn (string $t) => $localDate->startOfDay()->setTimeFromTimeString(substr($t, 0, 5));
        $windows = [];
        foreach ($covered as $i) {
            if ($p = $periods->get($i - 1)) {
                $windows[] = [$at($p->starts_at), $at($p->ends_at)];
            }
        }

        if ($windows) {
            usort($windows, fn ($a, $b) => $a[0] <=> $b[0]);
            $early = max(0, (int) $school->setting('attendance.early_minutes', 30));
            array_unshift($windows, [$windows[0][0]->subMinutes($early), $windows[0][0]]);
        }

        return $windows;
    }

    private function assertWithinLessons(School $school, Classroom $classroom, CarbonImmutable $now): void
    {
        if (! $school->setting('attendance.restrict_to_lessons', true)) {
            return;
        }

        $windows = $this->lessonWindows($school, $classroom, $now);
        if (! $windows) {
            throw ValidationException::withMessages(['code' => 'Tidak ada jam pelajaran untuk kelasmu hari ini.']);
        }

        foreach ($windows as [$from, $to]) {
            if ($now->greaterThanOrEqualTo($from) && $now->lessThanOrEqualTo($to)) {
                return;
            }
        }

        $first = $windows[0][0];
        $last = end($windows)[1];

        throw ValidationException::withMessages(['code' => match (true) {
            $now->lessThan($first) => 'Scan dibuka mulai pukul '.$first->format('H:i').'.',
            $now->greaterThan($last) => 'Jam pelajaran kelasmu hari ini sudah selesai (pukul '.$last->format('H:i').').',
            default => 'Sekarang bukan jam pelajaran kelasmu (istirahat atau jam kosong). Scan saat pelajaran berlangsung di kelas.',
        }]);
    }

    /**
     * @param  string|null  $ip  IP klien menurut server (Request::ip()), BUKAN dari input klien.
     */
    public function scan(School $school, Student $student, string $payload, ?string $ip, ?UserDevice $device = null, ?CarbonImmutable $at = null): Attendance
    {
        $now = ($at ?? CarbonImmutable::now())->setTimezone($school->timezone);

        if (! $school->isSchoolDay($now)) {
            throw ValidationException::withMessages(['code' => 'Hari ini bukan hari sekolah.']);
        }

        // Wajib tersambung ke WiFi sekolah. Untuk QR statis inilah pengaman utamanya:
        // foto QR yang dikirim ke teman di rumah tidak bisa dipakai.
        if (! $this->network->isConfigured($school)) {
            throw ValidationException::withMessages(['code' => 'Jaringan WiFi sekolah belum didaftarkan admin. Presensi QR belum bisa dipakai.']);
        }
        if (! $this->network->allows($school, $ip)) {
            throw ValidationException::withMessages(['code' => 'Sambungkan HP ke WiFi sekolah dan matikan data seluler, lalu scan lagi.']);
        }

        $parsed = $this->qr->parse($payload);
        if (! $parsed) {
            throw ValidationException::withMessages(['code' => 'Kode QR tidak dikenali.']);
        }

        [$classroomId, $code] = $parsed;

        // Classroom::find di-scope ke sekolah aktif: QR sekolah lain tidak akan ditemukan.
        $classroom = Classroom::find($classroomId);
        if (! $classroom || $classroom->id !== $student->classroom_id) {
            throw ValidationException::withMessages(['code' => 'QR ini bukan untuk kelasmu.']);
        }

        $mode = $school->qrMode();
        if (! $this->qr->verify($classroom, $code, $mode, $now)) {
            throw ValidationException::withMessages(['code' => $mode === ClassQrCode::STATIC
                ? 'QR kelas ini sudah diganti. Scan QR terbaru yang ditempel di kelas.'
                : 'QR sudah kedaluwarsa. Scan QR yang sedang tampil di kelas.']);
        }

        $this->assertWithinLessons($school, $classroom, $now);

        return DB::transaction(function () use ($school, $student, $now, $ip, $device) {
            $date = $now->toDateString();
            $existing = Attendance::query()->where('student_id', $student->id)->whereDate('date', $date)->lockForUpdate()->first();

            if ($existing && $existing->status !== AttendanceStatus::Absent) {
                throw ValidationException::withMessages(['code' => 'Presensimu hari ini sudah tercatat ('.$existing->status->label().').']);
            }

            $late = $now->greaterThan($this->cutoff($school, $now->startOfDay()));
            $attendance = $existing ?? new Attendance(['student_id' => $student->id, 'date' => $date]);
            $attendance->fill([
                'classroom_id' => $student->classroom_id,
                'status' => AttendanceStatus::Present,
                'method' => AttendanceMethod::Qr,
                'scanned_at' => $now->utc(),
                'is_late' => $late,
                'recorded_by' => $student->user_id,
                'device_id' => $device?->id,
                'ip' => $ip,
            ])->save();

            return $attendance;
        });
    }

    public function correct(School $school, Student $student, AttendanceStatus $status, User $by, ?string $note = null, ?CarbonImmutable $date = null): Attendance
    {
        if (! in_array($status->value, AttendanceStatus::correctable(), true)) {
            throw ValidationException::withMessages(['status' => 'Koreksi hanya bisa ke Hadir, Sakit, atau Izin.']);
        }

        $date = ($date ?? $school->localNow())->toDateString();

        $attendance = Attendance::query()->where('student_id', $student->id)->whereDate('date', $date)->first()
            ?? new Attendance(['student_id' => $student->id, 'date' => $date]);

        $attendance->fill([
            'classroom_id' => $student->classroom_id,
            'status' => $status,
            'method' => AttendanceMethod::Manual,
            'recorded_by' => $by->id,
            'note' => $note,
        ])->save();

        return $attendance;
    }

    /**
     * Catat Alpa untuk semua siswa aktif yang belum punya presensi pada tanggal itu.
     * Idempoten: aman dipanggil berulang kali. Harus dijalankan di dalam konteks tenant.
     */
    public function markAbsentees(School $school, CarbonImmutable $localDate): int
    {
        $date = $localDate->toDateString();
        $now = now();

        $rows = Student::query()
            ->where('is_active', true)
            ->whereDoesntHave('attendances', fn ($q) => $q->whereDate('date', $date))
            ->get(['id', 'classroom_id'])
            ->map(fn (Student $s) => [
                'school_id' => $school->id,
                'student_id' => $s->id,
                'classroom_id' => $s->classroom_id,
                'date' => $date,
                'status' => AttendanceStatus::Absent->value,
                'method' => AttendanceMethod::Auto->value,
                'is_late' => false,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

        $inserted = 0;
        foreach ($rows->chunk(500) as $chunk) {
            $inserted += DB::table('attendances')->insertOrIgnore($chunk->values()->all());
        }

        return $inserted;
    }
}
