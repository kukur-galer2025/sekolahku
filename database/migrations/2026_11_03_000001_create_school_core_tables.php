<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tabel inti sekolah. Semuanya punya school_id, dan setiap kunci unik
 * memuat school_id supaya dua sekolah boleh memakai nama/NIS/NIK yang sama.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('teachers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('nik', 32);
            $table->string('name');
            $table->string('subject')->nullable();   // mata pelajaran utama
            $table->string('position')->nullable();  // jabatan
            $table->timestamps();

            $table->unique(['school_id', 'nik']);
        });

        Schema::create('classrooms', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->string('name', 64);                // contoh: X-FKK 1
            $table->string('grade', 8)->nullable();    // X, XI, XII
            $table->foreignId('homeroom_teacher_id')->nullable()->constrained('teachers')->nullOnDelete();
            $table->string('qr_secret', 64);           // diganti saat guru menekan "Perbarui QR"
            $table->timestamps();

            $table->unique(['school_id', 'name']);
        });

        Schema::create('students', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('classroom_id')->nullable()->constrained()->nullOnDelete();
            $table->string('nis', 32);
            $table->string('nisn', 32)->nullable();
            $table->string('name');
            $table->date('birth_date')->nullable();
            $table->string('class_role', 16)->nullable(); // ketua | wakil | sekretaris | bendahara
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['school_id', 'nis']);
            $table->index(['school_id', 'classroom_id']);
        });

        // Jam KBM
        Schema::create('lesson_periods', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->string('label', 32);    // "1", "2", "Istirahat"
            $table->time('starts_at');
            $table->time('ends_at');
            $table->boolean('is_break')->default(false);
            $table->unsignedSmallInteger('sort');
            $table->timestamps();

            $table->index(['school_id', 'sort']);
        });

        // Jadwal pelajaran per kelas (format aSc: hari, jam mulai, jumlah jam)
        Schema::create('schedules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->foreignId('classroom_id')->constrained()->cascadeOnDelete();
            $table->foreignId('teacher_id')->nullable()->constrained()->nullOnDelete();
            $table->string('subject');
            $table->unsignedTinyInteger('day');          // ISO: 1 = Senin
            $table->unsignedTinyInteger('period_start'); // jam ke- (tanpa istirahat)
            $table->unsignedTinyInteger('period_count')->default(1);
            $table->timestamps();

            $table->index(['school_id', 'classroom_id', 'day']);
            $table->index(['school_id', 'teacher_id', 'day']);
        });

        // Presensi harian siswa
        Schema::create('attendances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();
            $table->foreignId('classroom_id')->nullable()->constrained()->nullOnDelete();
            $table->date('date');                   // tanggal menurut zona waktu sekolah
            $table->string('status', 1);            // H | S | I | A
            $table->string('method', 8);            // qr | manual | auto
            $table->timestamp('scanned_at')->nullable();
            $table->boolean('is_late')->default(false);
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('note')->nullable();
            $table->timestamps();

            $table->unique(['student_id', 'date']);
            $table->index(['school_id', 'date', 'classroom_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendances');
        Schema::dropIfExists('schedules');
        Schema::dropIfExists('lesson_periods');
        Schema::dropIfExists('students');
        Schema::dropIfExists('classrooms');
        Schema::dropIfExists('teachers');
    }
};
