<?php

namespace App\Console\Commands;

use App\Enums\SchoolStatus;
use App\Models\School;
use App\Services\AttendanceService;
use App\Tenancy\TenantContext;
use Illuminate\Console\Command;

/**
 * Menggantikan "rollover" di prototipe. Dijalankan scheduler tiap 5 menit:
 * untuk setiap sekolah, bila sekarang hari sekolah dan sudah lewat batas presensi
 * menurut ZONA WAKTU SEKOLAH ITU, siswa yang belum scan dicatat Alpa otomatis.
 */
class MarkAbsentStudents extends Command
{
    protected $signature = 'attendance:mark-absent {--school= : Hanya untuk subdomain tertentu}';

    protected $description = 'Catat alpa otomatis untuk siswa yang belum scan QR setelah batas presensi';

    public function handle(TenantContext $tenant, AttendanceService $attendance): int
    {
        $schools = School::query()
            ->where('status', '!=', SchoolStatus::Suspended->value)
            ->when($this->option('school'), fn ($q, $s) => $q->where('subdomain', $s))
            ->get();

        foreach ($schools as $school) {
            $tenant->run($school, function (School $school) use ($attendance) {
                $now = $school->localNow();

                if (! $school->isSchoolDay($now) || $now->lessThan($attendance->cutoff($school, $now->startOfDay()))) {
                    return;
                }

                $n = $attendance->markAbsentees($school, $now->startOfDay());
                if ($n > 0) {
                    $this->line("{$school->subdomain}: {$n} siswa dicatat alpa otomatis");
                    rescue(fn () => $this->notifyRecap($school, $now->toDateString(), $n));
                }
            });
        }

        return self::SUCCESS;
    }

    /** Rekap alpa ke wali kelas (per kelas) dan admin (total). Tanpa nama siswa: cukup jumlah. */
    private function notifyRecap(School $school, string $date, int $total): void
    {
        $notifier = app(\App\Services\Notify\Notifier::class);
        $tgl = \Illuminate\Support\Carbon::parse($date)->translatedFormat('j M');
        $perClass = \App\Models\Attendance::query()->whereDate('date', $date)->where('status', 'A')->where('method', 'auto')
            ->whereNotNull('classroom_id')->selectRaw('classroom_id, count(*) n')->groupBy('classroom_id')->pluck('n', 'classroom_id');

        foreach (\App\Models\Classroom::query()->whereIn('id', $perClass->keys())->whereNotNull('homeroom_teacher_id')->with('homeroomTeacher.user')->get() as $c) {
            $notifier->send([$c->homeroomTeacher?->user], 'absent_recap', "Rekap alpa {$tgl} · {$c->name}",
                $perClass[$c->id].' siswa kelas '.$c->name.' tidak presensi dan dicatat alpa otomatis.', '#/wali');
        }
        $notifier->send(\App\Models\User::query()->where('role', 'admin')->where('is_active', true)->get(), 'absent_recap',
            "Rekap alpa {$tgl}", "{$total} siswa tidak presensi hari ini dan dicatat alpa otomatis.", '#/dash');
    }
}
