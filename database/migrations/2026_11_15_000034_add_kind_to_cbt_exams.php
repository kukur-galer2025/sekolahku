<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Jenis ujian CBT: pts | pas | ujian (lainnya, dibuat admin) dan uh (ulangan harian, dibuat guru sendiri).
 * UH dimiliki satu guru (teacher_id) dan nilainya disalin ke kolom S (lingkup materi) Daftar Nilai.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cbt_exams', function (Blueprint $t) {
            $t->string('kind', 8)->default('ujian')->after('title');
            $t->foreignId('teacher_id')->nullable()->after('kind')->constrained('teachers')->nullOnDelete();
            $t->unsignedTinyInteger('grade_lm')->nullable()->after('teacher_id');
            $t->index(['school_id', 'kind']);
        });
    }

    public function down(): void
    {
        Schema::table('cbt_exams', function (Blueprint $t) {
            $t->dropIndex(['school_id', 'kind']);
            $t->dropConstrainedForeignId('teacher_id');
            $t->dropColumn(['kind', 'grade_lm']);
        });
    }
};
