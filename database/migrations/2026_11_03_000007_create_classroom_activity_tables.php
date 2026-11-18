<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tahap 7 (PWA): fitur prototipe yang sebelumnya hanya di localStorage.
 *  - journals:        jurnal mengajar guru (materi per pertemuan + snapshot presensi kelas)
 *  - assignments:     penugasan guru ke satu kelas
 *  - assignment_done: tanda "sudah dikerjakan" per siswa
 *  - agenda_pages:    laman agenda sekolah & agenda briefing (satu laman per jenis)
 *  - announcements:   informasi terbaru di dashboard guru/siswa
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('journals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->foreignId('teacher_id')->constrained()->cascadeOnDelete();
            $table->foreignId('classroom_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->unsignedTinyInteger('period_start');
            $table->unsignedTinyInteger('period_end');
            $table->string('subject');
            $table->string('topic', 500);                       // materi
            $table->text('notes')->nullable();
            $table->unsignedSmallInteger('present')->default(0); // snapshot presensi saat jurnal disimpan
            $table->unsignedSmallInteger('sick')->default(0);
            $table->unsignedSmallInteger('permit')->default(0);
            $table->unsignedSmallInteger('absent')->default(0);
            $table->unsignedSmallInteger('total')->default(0);
            $table->timestamps();

            $table->index(['school_id', 'classroom_id', 'date']);
            $table->index(['school_id', 'teacher_id', 'date']);
        });

        Schema::create('assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->foreignId('teacher_id')->constrained()->cascadeOnDelete();
            $table->foreignId('classroom_id')->constrained()->cascadeOnDelete();
            $table->foreignId('journal_id')->nullable()->constrained()->nullOnDelete();
            $table->string('subject');
            $table->string('title', 160);
            $table->text('description')->nullable();
            $table->date('due_date');
            $table->timestamps();

            $table->index(['school_id', 'classroom_id', 'due_date']);
        });

        Schema::create('assignment_done', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->foreignId('assignment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();
            $table->timestamp('done_at');

            $table->unique(['assignment_id', 'student_id']);
        });

        Schema::create('agenda_pages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 16);                        // sekolah | briefing
            $table->string('title', 160);
            $table->foreignId('coordinator_id')->nullable()->constrained('teachers')->nullOnDelete();
            $table->text('body');
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['school_id', 'kind']);
        });

        Schema::create('announcements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->string('title', 120);
            $table->text('body');
            $table->string('audience', 8)->default('semua');   // semua | guru | siswa
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['school_id', 'created_at']);
        });
    }

    public function down(): void
    {
        foreach (['announcements', 'agenda_pages', 'assignment_done', 'assignments', 'journals'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
