<?php

use App\Support\SchoolLevel;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Tahap 3 — struktur kelas dan tahun ajaran.
 *  - schools.level            : sd | smp | sma (tingkat 1–6, 7–9, X–XII). Diisi dari nama sekolah untuk data lama.
 *  - classrooms.grade         : dirapikan sesuai jenjang (mis. "VII-A" → 7, "X-FKK 1" → X).
 *  - student_enrollments      : riwayat kelas siswa per tahun ajaran (dasar naik kelas & kelulusan).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('schools', function (Blueprint $t) {
            $t->string('level', 8)->nullable()->after('npsn');
        });

        Schema::create('student_enrollments', function (Blueprint $t) {
            $t->id();
            $t->foreignId('school_id')->constrained()->cascadeOnDelete();
            $t->foreignId('student_id')->constrained()->cascadeOnDelete();
            $t->string('academic_year', 9);                      // 2026/2027
            $t->foreignId('classroom_id')->nullable()->constrained()->nullOnDelete();
            $t->string('classroom_name', 64)->nullable();        // salinan nama kelas saat itu (tetap ada walau kelas dihapus/diganti nama)
            $t->string('grade', 8)->nullable();                  // 7 | 8 | 9 | X | XI | XII
            $t->string('status', 16)->default('aktif');          // aktif | nonaktif | naik | tinggal | lulus | mutasi_keluar | keluar
            $t->timestamps();

            $t->unique(['student_id', 'academic_year']);
            $t->index(['school_id', 'academic_year']);
        });

        $year = SchoolLevel::academicYear(now('Asia/Jakarta'));

        foreach (DB::table('schools')->get(['id', 'name']) as $school) {
            $level = SchoolLevel::guess($school->name);
            DB::table('schools')->where('id', $school->id)->update(['level' => $level]);

            $classes = DB::table('classrooms')->where('school_id', $school->id)->get(['id', 'name']);
            $grades = [];
            foreach ($classes as $c) {
                $grades[$c->id] = SchoolLevel::gradeOf($c->name, $level);
                DB::table('classrooms')->where('id', $c->id)->update(['grade' => $grades[$c->id]]);
            }
            $names = $classes->pluck('name', 'id');

            DB::table('students')->where('school_id', $school->id)->orderBy('id')
                ->chunk(500, function ($students) use ($school, $year, $grades, $names) {
                    DB::table('student_enrollments')->insertOrIgnore($students->map(fn ($s) => [
                        'school_id' => $school->id, 'student_id' => $s->id, 'academic_year' => $year,
                        'classroom_id' => $s->classroom_id, 'classroom_name' => $names[$s->classroom_id] ?? null,
                        'grade' => $grades[$s->classroom_id] ?? null, 'status' => $s->is_active ? 'aktif' : 'nonaktif',
                        'created_at' => now(), 'updated_at' => now(),
                    ])->all());
                });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('student_enrollments');
        Schema::table('schools', fn (Blueprint $t) => $t->dropColumn('level'));
    }
};
