<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Menu prototipe yang dilengkapi (4 Nov 2026):
 *  - teacher_attendances / teacher_class_entries : absensi guru 2 langkah (hadir di sekolah → masuk kelas sesuai jadwal)
 *  - habit_submissions                           : Pembiasaan (CREO & Literasi)
 *  - library_items / library_activity            : MP-Library (perpustakaan digital) + log dibuka & favorit siswa
 *  - duty_rosters                                : jadwal piket kelas (Senin–Jumat)
 *  - class_notes                                 : jurnal kelas oleh pengurus kelas
 *  - assignment_files                            : berkas tugas siswa (Tugas-Ku per folder mapel)
 *  - leger_distributions / leger_entries         : Leger rapor per semester (wali kelas)
 *  - cbt_banks / cbt_questions                   : bank soal CBT
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('teacher_attendances', function (Blueprint $t) {
            $t->id();
            $t->foreignId('school_id')->constrained()->cascadeOnDelete();
            $t->foreignId('teacher_id')->constrained()->cascadeOnDelete();
            $t->date('date');
            $t->timestamp('checked_in_at');
            $t->string('method', 8);                 // qr | manual
            $t->string('ip', 45)->nullable();
            $t->foreignId('device_id')->nullable()->constrained('user_devices')->nullOnDelete();
            $t->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->unique(['teacher_id', 'date']);
            $t->index(['school_id', 'date']);
        });

        Schema::create('teacher_class_entries', function (Blueprint $t) {
            $t->id();
            $t->foreignId('school_id')->constrained()->cascadeOnDelete();
            $t->foreignId('teacher_id')->constrained()->cascadeOnDelete();
            // Jadwal bisa diimpor ulang (baris lama terhapus) → simpan cuplikan blok agar riwayat tetap utuh.
            $t->foreignId('schedule_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('classroom_id')->nullable()->constrained()->nullOnDelete();
            $t->string('subject');
            $t->unsignedTinyInteger('period_start');
            $t->unsignedTinyInteger('period_end');
            $t->date('date');
            $t->timestamp('entered_at');
            $t->boolean('is_late')->default(false);
            $t->string('method', 8);
            $t->string('ip', 45)->nullable();
            $t->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->unique(['teacher_id', 'schedule_id', 'date']);
            $t->index(['school_id', 'date']);
        });

        Schema::create('habit_submissions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('school_id')->constrained()->cascadeOnDelete();
            $t->string('kind', 10);                  // creo | literasi
            $t->string('title', 120);
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->foreignId('student_id')->nullable()->constrained()->cascadeOnDelete();
            $t->foreignId('classroom_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('coordinator_id')->nullable()->constrained('teachers')->nullOnDelete();
            $t->string('file_path');
            $t->string('file_name', 160);
            $t->string('file_type', 8);              // PDF | JPG
            $t->unsignedInteger('file_size');
            $t->timestamps();
            $t->index(['school_id', 'kind', 'created_at']);
        });

        Schema::create('library_items', function (Blueprint $t) {
            $t->id();
            $t->foreignId('school_id')->constrained()->cascadeOnDelete();
            $t->string('title', 120);
            $t->string('author', 80)->nullable();
            $t->string('category', 40);
            $t->string('subject', 60)->nullable();
            $t->foreignId('classroom_id')->nullable()->constrained()->cascadeOnDelete(); // null = semua kelas
            $t->string('description', 200)->nullable();
            $t->string('type', 8);                   // pdf | image | link
            $t->text('url')->nullable();
            $t->string('file_path')->nullable();
            $t->string('file_name', 160)->nullable();
            $t->unsignedInteger('file_size')->nullable();
            $t->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $t->string('uploader_name', 120);
            $t->timestamps();
            $t->index(['school_id', 'created_at']);
        });

        Schema::create('library_activity', function (Blueprint $t) {
            $t->id();
            $t->foreignId('school_id')->constrained()->cascadeOnDelete();
            $t->foreignId('library_item_id')->constrained()->cascadeOnDelete();
            $t->foreignId('student_id')->constrained()->cascadeOnDelete();
            $t->timestamp('opened_at')->nullable();
            $t->timestamp('favorited_at')->nullable();
            $t->unique(['library_item_id', 'student_id']);
        });

        Schema::create('duty_rosters', function (Blueprint $t) {
            $t->id();
            $t->foreignId('school_id')->constrained()->cascadeOnDelete();
            $t->foreignId('classroom_id')->unique()->constrained()->cascadeOnDelete();
            $t->json('days');                        // {"1":[student_id,...], ..., "5":[...]}
            $t->string('note')->nullable();
            $t->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
        });

        Schema::create('class_notes', function (Blueprint $t) {
            $t->id();
            $t->foreignId('school_id')->constrained()->cascadeOnDelete();
            $t->foreignId('classroom_id')->constrained()->cascadeOnDelete();
            $t->foreignId('student_id')->nullable()->constrained()->nullOnDelete();
            $t->string('author_name', 120);
            $t->string('author_role', 20);
            $t->date('date');
            $t->text('note');
            $t->timestamps();
            $t->index(['school_id', 'classroom_id', 'date']);
        });

        Schema::create('assignment_files', function (Blueprint $t) {
            $t->id();
            $t->foreignId('school_id')->constrained()->cascadeOnDelete();
            $t->foreignId('student_id')->constrained()->cascadeOnDelete();
            $t->foreignId('assignment_id')->nullable()->constrained()->nullOnDelete();
            $t->string('folder', 80);                // mata pelajaran
            $t->string('file_path');
            $t->string('file_name', 160);
            $t->string('file_type', 8);
            $t->unsignedInteger('file_size');
            $t->timestamps();
            $t->index(['school_id', 'student_id', 'folder']);
        });

        Schema::create('leger_distributions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('school_id')->constrained()->cascadeOnDelete();
            $t->foreignId('classroom_id')->constrained()->cascadeOnDelete();
            $t->foreignId('teacher_id')->constrained()->cascadeOnDelete();
            $t->string('term', 24);                  // "Gasal 2026/2027"
            $t->timestamp('distributed_at');
            $t->timestamps();
            $t->unique(['classroom_id', 'term']);
        });

        Schema::create('leger_entries', function (Blueprint $t) {
            $t->id();
            $t->foreignId('school_id')->constrained()->cascadeOnDelete();
            $t->foreignId('classroom_id')->constrained()->cascadeOnDelete();
            $t->foreignId('student_id')->constrained()->cascadeOnDelete();
            $t->string('term', 24);
            $t->json('scores')->nullable();          // [nilai mapel ke-0, ke-1, ...]
            $t->json('competencies')->nullable();    // [[sangat dipahami, belum dipahami], ...]
            $t->json('extras')->nullable();          // [keterangan ekstra ke-0, ...]
            $t->json('absences')->nullable();        // [sakit, izin, tanpa keterangan]
            $t->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->unique(['student_id', 'term']);
            $t->index(['school_id', 'classroom_id', 'term']);
        });

        Schema::create('cbt_banks', function (Blueprint $t) {
            $t->id();
            $t->foreignId('school_id')->constrained()->cascadeOnDelete();
            $t->string('name', 120);
            $t->string('subject', 80)->nullable();
            $t->string('file_name', 160);
            $t->unsignedSmallInteger('question_count');
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->string('creator_name', 120);
            $t->timestamps();
        });

        Schema::create('cbt_questions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('school_id')->constrained()->cascadeOnDelete();
            $t->foreignId('cbt_bank_id')->constrained()->cascadeOnDelete();
            $t->unsignedSmallInteger('number');
            $t->text('body');
            $t->json('options');                     // ["A ...", "B ...", ...]
            $t->unsignedTinyInteger('answer')->nullable(); // indeks opsi benar
            $t->index(['cbt_bank_id', 'number']);
        });
    }

    public function down(): void
    {
        foreach (['cbt_questions', 'cbt_banks', 'leger_entries', 'leger_distributions', 'assignment_files', 'class_notes', 'duty_rosters',
            'library_activity', 'library_items', 'habit_submissions', 'teacher_class_entries', 'teacher_attendances'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
