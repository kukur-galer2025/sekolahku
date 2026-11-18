<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Daftar Nilai Sumatif (guru mapel, per kelas yang diampu, per semester):
 *  - grade_books   : satu buku nilai = guru + kelas + mapel + semester (term "Gasal 2026/2027").
 *  - grade_entries : nilai per siswa: Sumatif Lingkup Materi (LM 1–10), STS (non tes, tes), SAS (non tes, tes).
 * NA & Nilai Rapor dihitung (tidak disimpan) agar selalu konsisten dengan rumus.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('grade_books', function (Blueprint $t) {
            $t->id();
            $t->foreignId('school_id')->constrained()->cascadeOnDelete();
            $t->foreignId('teacher_id')->constrained()->cascadeOnDelete();
            $t->foreignId('classroom_id')->constrained()->cascadeOnDelete();
            $t->string('subject', 120);
            $t->string('term', 24);                       // "Gasal 2026/2027"
            $t->string('phase', 8)->nullable();           // Fase A–F
            $t->unsignedTinyInteger('lm_count')->default(5);
            $t->json('lm_labels')->nullable();            // nama lingkup materi (opsional)
            $t->timestamps();
            $t->unique(['school_id', 'teacher_id', 'classroom_id', 'subject', 'term'], 'grade_books_unique');
            $t->index(['school_id', 'classroom_id', 'term']);
        });

        Schema::create('grade_entries', function (Blueprint $t) {
            $t->id();
            $t->foreignId('school_id')->constrained()->cascadeOnDelete();
            $t->foreignId('grade_book_id')->constrained()->cascadeOnDelete();
            $t->foreignId('student_id')->constrained()->cascadeOnDelete();
            $t->json('lm')->nullable();                   // [nilai LM1, LM2, …] (null = kosong)
            $t->decimal('sts_nontes', 5, 2)->nullable();
            $t->decimal('sts_tes', 5, 2)->nullable();
            $t->decimal('sas_nontes', 5, 2)->nullable();
            $t->decimal('sas_tes', 5, 2)->nullable();
            $t->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->unique(['grade_book_id', 'student_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('grade_entries');
        Schema::dropIfExists('grade_books');
    }
};
