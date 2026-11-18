<?php

namespace App\Services\Platform;

use App\Models\School;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Pemakaian per sekolah untuk panel super-admin. Memakai query builder dengan filter
 * school_id eksplisit (lintas sekolah, khusus panel), bukan model ber-scope.
 */
class SchoolUsage
{
    /** Tabel yang dihitung untuk "ukuran data" (jumlah baris). */
    private const TABLES = ['users', 'students', 'student_profiles', 'student_enrollments', 'teachers', 'classrooms', 'schedules', 'attendances', 'lesson_periods', 'journals', 'assignments', 'agenda_pages', 'announcements', 'data_imports', 'user_devices', 'audit_logs', 'data_exports', 'legal_acceptances', 'teacher_attendances', 'teacher_class_entries', 'habit_submissions', 'library_items', 'library_access_logs', 'duty_rosters', 'class_notes', 'assignment_files', 'leger_entries', 'grade_entries', 'class_cash_entries', 'cbt_questions', 'cbt_answers', 'school_rooms'];

    /** @param list<int> $ids @return array<int, array{students:int,teachers:int,last_login:?string,attendance_30d:int}> */
    public function summary(array $ids): array
    {
        if (! $ids) {
            return [];
        }
        $count = fn (string $table, ?callable $f = null) => DB::table($table)->whereIn('school_id', $ids)
            ->when($f, $f)->selectRaw('school_id, count(*) n')->groupBy('school_id')->pluck('n', 'school_id');

        $students = $count('students', fn ($q) => $q->where('is_active', true));
        $teachers = $count('teachers');
        $att = $count('attendances', fn ($q) => $q->where('date', '>=', now()->subDays(30)->toDateString()));
        $last = DB::table('users')->whereIn('school_id', $ids)->selectRaw('school_id, max(last_login_at) t')->groupBy('school_id')->pluck('t', 'school_id');

        return collect($ids)->mapWithKeys(fn ($id) => [$id => [
            'students' => (int) ($students[$id] ?? 0), 'teachers' => (int) ($teachers[$id] ?? 0),
            'attendance_30d' => (int) ($att[$id] ?? 0), 'last_login' => $last[$id] ?? null,
        ]])->all();
    }

    public function totalActiveStudents(): int
    {
        return DB::table('students')->where('is_active', true)->count();
    }

    public function detail(School $school): array
    {
        $rows = collect(self::TABLES)->mapWithKeys(fn ($t) => [$t => DB::table($t)->where('school_id', $school->id)->count()]);
        ['files' => $fileCount, 'bytes' => $bytes] = \App\Tenancy\TenantFiles::usage($school->id);

        $s = $this->summary([$school->id])[$school->id];

        return $s + [
            'rows' => $rows->all(),
            'total_rows' => $rows->sum(),
            'files' => $fileCount,
            'storage_bytes' => $bytes,
            'quota_pct' => $school->student_quota ? min(100, (int) round($s['students'] / $school->student_quota * 100)) : null,
            'classrooms' => $rows['classrooms'],
            'devices' => DB::table('user_devices')->where('school_id', $school->id)->whereNull('revoked_at')->count(),
        ];
    }

    public static function bytes(int $b): string
    {
        return $b >= 1048576 ? number_format($b / 1048576, 1, ',', '.').' MB' : number_format(max(0, $b) / 1024, 0, ',', '.').' KB';
    }
}
