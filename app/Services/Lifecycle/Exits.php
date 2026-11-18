<?php

namespace App\Services\Lifecycle;

use App\Enums\Role;
use App\Models\Classroom;
use App\Models\PushSubscription;
use App\Models\School;
use App\Models\Student;
use App\Models\Teacher;
use App\Models\User;
use App\Services\DeviceBinding;
use App\Services\Notify\Notifier;
use App\Services\Platform\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Siswa mutasi/keluar dan guru mutasi/pensiun: akun dimatikan, riwayat kehadiran tetap tersimpan. */
class Exits
{
    public const STUDENT_TYPES = ['mutasi_keluar' => 'Mutasi keluar (pindah sekolah)', 'keluar' => 'Keluar / mengundurkan diri', 'nonaktif' => 'Nonaktif sementara'];

    public const TEACHER_TYPES = ['mutasi' => 'Mutasi (pindah tugas)', 'pensiun' => 'Pensiun', 'nonaktif' => 'Nonaktif'];

    public function __construct(private DeviceBinding $devices, private Notifier $notifier, private AuditLogger $audit) {}

    private function lockAccount(?User $user, User $by): void
    {
        if (! $user) {
            return;
        }
        $user->forceFill(['is_active' => false])->save();
        $this->devices->revokeAll($user, $by);
        PushSubscription::query()->where('user_id', $user->id)->delete();
    }

    public function student(School $school, Student $s, string $type, string $date, ?string $reason, User $by): void
    {
        abort_unless(array_key_exists($type, self::STUDENT_TYPES), 422);
        $class = $s->classroom;
        DB::transaction(function () use ($s, $type, $date, $reason, $by) {
            $s->forceFill(['status' => $type, 'is_active' => false, 'left_at' => $date, 'left_reason' => $reason])->save();
            $this->lockAccount($s->user, $by);
        });

        $label = self::STUDENT_TYPES[$type];
        $this->audit->log('student.exit', "Siswa {$s->nis} ({$class?->name}) dicatat: {$label}", $school, $by, ['student_id' => $s->id, 'type' => $type]);
        // Tanpa nama siswa di notifikasi (bisa tampil di layar kunci HP).
        $to = User::query()->where('role', Role::Admin->value)->where('is_active', true)->where('id', '!=', $by->id)->get();
        if ($class?->homeroomTeacher?->user) {
            $to->push($class->homeroomTeacher->user);
        }
        if ($to->isNotEmpty()) {
            $this->notifier->send($to, 'student_exit', 'Perubahan data siswa'.($class ? " kelas {$class->name}" : ''), "1 siswa dicatat {$label}. Buka Data Siswa untuk detail.", '/data-siswa?status=semua');
        }
    }

    public function reactivateStudent(School $school, Student $s, User $by, ?int $classroomId = null): void
    {
        $quota = $school->effectiveStudentQuota();
        if ($quota !== null && Student::query()->where('is_active', true)->count() >= $quota) {
            throw ValidationException::withMessages(['student' => "Kuota paket penuh ({$quota} siswa). Nonaktifkan siswa lain atau tingkatkan paket."]);
        }
        if ($classroomId !== null && ! Classroom::query()->find($classroomId)) {
            throw ValidationException::withMessages(['classroom_id' => 'Kelas tidak ditemukan.']);
        }
        $s->forceFill(['status' => 'aktif', 'is_active' => true, 'left_at' => null, 'left_reason' => null] + ($classroomId !== null ? ['classroom_id' => $classroomId] : []))->save();
        $s->user?->forceFill(['is_active' => true])->save();
        $this->audit->log('student.reactivated', "Siswa {$s->nis} diaktifkan kembali", $school, $by, ['student_id' => $s->id]);
    }

    public function teacher(School $school, Teacher $t, string $type, string $date, ?string $reason, User $by): void
    {
        abort_unless(array_key_exists($type, self::TEACHER_TYPES), 422);
        if ($t->user_id === $by->id) {
            throw ValidationException::withMessages(['teacher' => 'Tidak bisa menonaktifkan akun sendiri.']);
        }
        $classes = $t->homeroomClassrooms()->get();
        DB::transaction(function () use ($t, $type, $date, $reason, $by) {
            $t->forceFill(['status' => $type, 'left_at' => $date, 'left_reason' => $reason])->save();
            Classroom::query()->where('homeroom_teacher_id', $t->id)->update(['homeroom_teacher_id' => null]);
            $this->lockAccount($t->user, $by);
        });

        $this->audit->log('teacher.exit', "Guru {$t->name} dicatat: ".self::TEACHER_TYPES[$type], $school, $by, ['teacher_id' => $t->id, 'type' => $type]);
        if ($classes->isNotEmpty()) {
            $this->notifier->send(User::query()->where('role', Role::Admin->value)->where('is_active', true)->get(), 'homeroom_vacant',
                'Kelas '.$classes->pluck('name')->join(', ').' perlu wali kelas baru', 'Atur di menu Kelas & Wali.', '/kelas');
        }
    }

    public function reactivateTeacher(School $school, Teacher $t, User $by): void
    {
        $t->forceFill(['status' => 'aktif', 'left_at' => null, 'left_reason' => null])->save();
        $t->user?->forceFill(['is_active' => true])->save();
        $this->audit->log('teacher.reactivated', "Guru {$t->name} diaktifkan kembali", $school, $by, ['teacher_id' => $t->id]);
    }
}
