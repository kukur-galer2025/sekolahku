<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Tahap 6 — lifecycle siswa & guru.
 *  - students.status : aktif | nonaktif | lulus | mutasi_keluar | keluar | arsip
 *  - teachers.status : aktif | mutasi | pensiun | nonaktif
 *  - academic_year_closures : setiap "Tutup Tahun Ajaran" beserta salinan sebelum diproses (bisa dibatalkan 7 hari).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('students', function (Blueprint $t) {
            $t->string('status', 16)->default('aktif')->after('is_active');
            $t->date('left_at')->nullable()->after('status');
            $t->string('left_reason', 255)->nullable()->after('left_at');
        });
        Schema::table('teachers', function (Blueprint $t) {
            $t->string('status', 16)->default('aktif')->after('position');
            $t->date('left_at')->nullable()->after('status');
            $t->string('left_reason', 255)->nullable()->after('left_at');
        });
        Schema::create('academic_year_closures', function (Blueprint $t) {
            $t->id();
            $t->foreignId('school_id')->constrained()->cascadeOnDelete();
            $t->string('from_year', 9);
            $t->string('to_year', 9);
            $t->json('summary');                   // {naik, tinggal, lulus}
            $t->longText('snapshot');              // keadaan siswa sebelum diproses (untuk Batalkan)
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('undone_at')->nullable();
            $t->timestamps();

            $t->index(['school_id', 'created_at']);
        });

        DB::table('students')->where('is_active', false)->update(['status' => 'nonaktif']);
    }

    public function down(): void
    {
        Schema::dropIfExists('academic_year_closures');
        Schema::table('teachers', fn (Blueprint $t) => $t->dropColumn(['status', 'left_at', 'left_reason']));
        Schema::table('students', fn (Blueprint $t) => $t->dropColumn(['status', 'left_at', 'left_reason']));
    }
};
