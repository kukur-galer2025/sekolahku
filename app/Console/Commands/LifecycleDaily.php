<?php

namespace App\Console\Commands;

use App\Enums\Role;
use App\Enums\SchoolStatus;
use App\Models\AcademicYearClosure;
use App\Models\PushSubscription;
use App\Models\School;
use App\Models\Student;
use App\Models\User;
use App\Models\UserDevice;
use App\Models\UserNotification;
use App\Services\Notify\Notifier;
use App\Services\Platform\AuditLogger;
use App\Tenancy\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Tahap 6 — tugas harian lifecycle (pagi WIB):
 *  - 1 & 15 Juni: ingatkan admin menutup tahun ajaran bila belum.
 *  - Alumni: pengingat H-7 sebelum akun nonaktif, lalu nonaktif otomatis setelah 90 hari.
 *  - Retensi: siswa lulus/mutasi/keluar dianonimkan setelah masa simpan (bawaan 365 hari), pemberitahuan 30 hari sebelumnya.
 */
class LifecycleDaily extends Command
{
    protected $signature = 'lifecycle:daily {--school= : Hanya untuk subdomain tertentu} {--dry-run : Tampilkan saja, tanpa mengubah data}';

    protected $description = 'Pengingat tutup tahun ajaran, akhir masa alumni, dan anonimisasi data siswa yang sudah lama keluar';

    public function handle(TenantContext $tenant, Notifier $notifier, AuditLogger $audit): int
    {
        $schools = School::query()
            ->where('status', '!=', SchoolStatus::Suspended->value)
            ->when($this->option('school'), fn ($q, $s) => $q->where('subdomain', $s))
            ->get();

        foreach ($schools as $school) {
            $tenant->run($school, fn (School $school) => rescue(fn () => $this->forSchool($school, $notifier, $audit), report: true));
        }

        return self::SUCCESS;
    }

    private function admins()
    {
        return User::query()->where('role', Role::Admin->value)->where('is_active', true)->get();
    }

    public static function retentionDays(School $school): int
    {
        return max(180, (int) $school->setting('lifecycle.retention_days', config('tenancy.alumni_retention_days', 365)));
    }

    private function forSchool(School $school, Notifier $notifier, AuditLogger $audit): void
    {
        $today = $school->localNow()->startOfDay();
        $dry = (bool) $this->option('dry-run');

        // 1) Pengingat tutup tahun ajaran
        if ($today->month === 6 && in_array($today->day, [1, 15], true)
            && ! AcademicYearClosure::query()->where('from_year', $school->currentAcademicYear())->whereNull('undone_at')->exists()) {
            $this->line("{$school->subdomain}: pengingat tutup tahun ajaran");
            $dry || $notifier->send($this->admins(), 'year_reminder', 'Saatnya Tutup Tahun Ajaran '.$school->currentAcademicYear(),
                'Naikkan kelas, catat tinggal kelas, dan luluskan tingkat akhir sekaligus di menu Tahun Ajaran.', '/tahun-ajaran');
        }

        // 2) Alumni: H-7 pengingat, lalu nonaktif
        $alumni = Student::query()->where('status', 'lulus')->whereNotNull('left_at')
            ->whereHas('user', fn ($q) => $q->where('is_active', true))->with('user')->get();
        foreach ($alumni as $s) {
            $until = Carbon::parse($s->alumniUntil()->toDateString(), $today->timezone)->startOfDay();
            $days = (int) round($today->diffInDays($until, false));
            if ($days === 7) {
                $dry || $notifier->send([$s->user], 'alumni_ending', 'Akun alumni nonaktif 7 hari lagi',
                    'Simpan rekap kehadiranmu sekarang. Surat Keterangan Kehadiran bisa diminta ke admin sekolah kapan saja.', '#/rekap');
            } elseif ($days <= 0) {
                $this->line("{$school->subdomain}: alumni {$s->id} dinonaktifkan");
                if (! $dry) {
                    $s->user->forceFill(['is_active' => false])->save();
                    UserDevice::query()->where('user_id', $s->user_id)->whereNull('revoked_at')->update(['revoked_at' => now()]);
                    PushSubscription::query()->where('user_id', $s->user_id)->delete();
                    if (config('session.driver') === 'database') {
                        DB::table(config('session.table', 'sessions'))->where('user_id', $s->user_id)->delete();
                    }
                }
            }
        }

        // 3) Retensi: anonimkan siswa yang sudah lama keluar
        $keep = self::retentionDays($school);
        $left = Student::query()->whereIn('status', ['lulus', 'mutasi_keluar', 'keluar'])->whereNotNull('left_at')
            ->whereDate('left_at', '<=', $today->copy()->subDays($keep - 30)->toDateString())->get();
        $warn = 0;
        $done = 0;
        foreach ($left as $s) {
            $due = Carbon::parse($s->left_at->toDateString(), $today->timezone)->addDays($keep)->startOfDay();
            if ($due->gt($today)) {
                $warn += (int) round($today->diffInDays($due)) === 30 ? 1 : 0;

                continue;
            }
            $dry || $this->anonymize($s);
            $done++;
        }
        if ($warn) {
            $dry || $notifier->send($this->admins(), 'retention_warning', "{$warn} data siswa akan dianonimkan 30 hari lagi",
                "Siswa yang keluar/lulus lebih dari {$keep} hari lalu. Unduh Surat Keterangan Kehadiran atau ekspor data dulu bila masih diperlukan.", '/data-siswa?status=semua');
        }
        if ($done) {
            $this->line("{$school->subdomain}: {$done} siswa dianonimkan");
            $dry || $audit->log('student.anonymized', "{$done} data siswa yang keluar/lulus lebih dari {$keep} hari dianonimkan otomatis", $school, null, ['count' => $done], 'Sistem');
        }
    }

    /** Hapus identitas, simpan statistik: kehadiran & riwayat kelas tetap, nama/NIS/NISN/tanggal lahir/akun dihapus. */
    public function anonymize(Student $s): void
    {
        DB::transaction(function () use ($s) {
            $s->forceFill([
                'name' => 'Alumni #'.$s->id, 'nis' => 'X'.$s->id, 'nisn' => null, 'birth_date' => null, 'class_role' => null,
                'status' => 'arsip', 'left_reason' => null,
                'parent_consent_ref' => null, 'parent_consent_by' => null,
            ])->saveQuietly();
            if ($u = $s->user) {
                $u->forceFill([
                    'username' => 'x-'.$s->id, 'name' => 'Alumni #'.$s->id, 'email' => null, 'phone' => null, 'photo_path' => null,
                    'password' => Str::random(40), 'is_active' => false, 'must_change_password' => false,
                ])->save();
                UserDevice::query()->where('user_id', $u->id)->delete();
                PushSubscription::query()->where('user_id', $u->id)->delete();
                UserNotification::query()->where('user_id', $u->id)->delete();
            }
        });
    }
}
