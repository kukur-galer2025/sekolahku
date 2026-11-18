<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * CBT baru (kajian 10 Nov 2026):
 *  - cbt_banks      → paket soal guru (status draf/dikirim/diterima/dikembalikan); paket yang diterima = bank soal pusat admin
 *  - cbt_questions  → + jenis (pg/essay), gambar soal, opsi {text,image}, bobot, pedoman essay
 *  - cbt_exams, cbt_exam_questions (salinan beku), cbt_exam_classes (token per kelas + guru penilai essay),
 *    cbt_attempts, cbt_answers, cbt_exam_logs
 * Bank soal Excel lama otomatis menjadi paket "diterima" (soal PG teks).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cbt_banks', function (Blueprint $t) {
            $t->foreignId('teacher_id')->nullable()->after('school_id')->constrained()->nullOnDelete();
            $t->string('grade', 8)->nullable()->after('subject');
            $t->string('status', 16)->default('draf')->after('grade');
            $t->text('review_note')->nullable()->after('status');
            $t->foreignId('reviewed_by')->nullable()->after('review_note')->constrained('users')->nullOnDelete();
            $t->timestamp('reviewed_at')->nullable()->after('reviewed_by');
            $t->timestamp('submitted_at')->nullable()->after('reviewed_at');
            $t->index(['school_id', 'status']);
        });
        Schema::table('cbt_banks', function (Blueprint $t) {
            $t->string('file_name', 160)->nullable()->change();
        });

        Schema::table('cbt_questions', function (Blueprint $t) {
            $t->string('type', 8)->default('pg')->after('number');      // pg | essay
            $t->string('image_path')->nullable()->after('body');
            $t->decimal('score', 6, 2)->default(1)->after('answer');
            $t->text('essay_guide')->nullable()->after('score');
        });

        // Data lama: bank Excel → paket diterima; opsi ["teks", ...] → [{"text": "teks", "image": null}, ...]
        DB::table('cbt_banks')->update(['status' => 'diterima', 'reviewed_at' => now(), 'submitted_at' => DB::raw('created_at')]);
        DB::table('cbt_questions')->orderBy('id')->chunkById(500, function ($rows) {
            foreach ($rows as $r) {
                $opts = json_decode((string) $r->options, true) ?: [];
                if ($opts && is_string($opts[0] ?? null)) {
                    DB::table('cbt_questions')->where('id', $r->id)->update(['options' => json_encode(
                        array_map(fn ($o) => ['text' => (string) $o, 'image' => null], $opts), JSON_UNESCAPED_UNICODE)]);
                }
            }
        });
        // Isi teacher_id dari pembuat bila pembuatnya guru.
        foreach (DB::table('cbt_banks')->whereNotNull('created_by')->get(['id', 'created_by']) as $b) {
            $tid = DB::table('teachers')->where('user_id', $b->created_by)->value('id');
            if ($tid) {
                DB::table('cbt_banks')->where('id', $b->id)->update(['teacher_id' => $tid]);
            }
        }

        Schema::create('cbt_exams', function (Blueprint $t) {
            $t->id();
            $t->foreignId('school_id')->constrained()->cascadeOnDelete();
            $t->string('title', 150);
            $t->string('subject', 80);
            $t->string('grade', 8)->nullable();
            $t->string('code', 30);
            $t->timestamp('starts_at');
            $t->timestamp('ends_at');
            $t->unsignedSmallInteger('duration_minutes');
            $t->boolean('shuffle_questions')->default(true);
            $t->boolean('shuffle_options')->default(true);
            $t->unsignedTinyInteger('max_exits')->default(3);
            $t->string('status', 12)->default('draf');            // draf | terbit
            $t->json('draft')->nullable();                         // pilihan soal (id bank) selama draf
            $t->timestamp('published_at')->nullable();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->unique(['school_id', 'code']);
            $t->index(['school_id', 'starts_at']);
        });

        Schema::create('cbt_exam_questions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('school_id')->constrained()->cascadeOnDelete();
            $t->foreignId('cbt_exam_id')->constrained()->cascadeOnDelete();
            $t->unsignedBigInteger('source_question_id')->nullable();
            $t->unsignedSmallInteger('number');
            $t->string('type', 8);
            $t->text('body');
            $t->string('image_path')->nullable();
            $t->json('options');
            $t->unsignedTinyInteger('answer')->nullable();
            $t->decimal('score', 6, 2)->default(1);
            $t->text('essay_guide')->nullable();
            $t->index(['cbt_exam_id', 'number']);
        });

        Schema::create('cbt_exam_classes', function (Blueprint $t) {
            $t->id();
            $t->foreignId('school_id')->constrained()->cascadeOnDelete();
            $t->foreignId('cbt_exam_id')->constrained()->cascadeOnDelete();
            $t->foreignId('classroom_id')->constrained()->cascadeOnDelete();
            $t->foreignId('essay_grader_id')->nullable()->constrained('teachers')->nullOnDelete();
            $t->text('token')->nullable();                          // terenkripsi (APP_KEY), beda tiap kelas
            $t->timestamp('token_at')->nullable();
            $t->unique(['cbt_exam_id', 'classroom_id']);
        });

        Schema::create('cbt_attempts', function (Blueprint $t) {
            $t->id();
            $t->foreignId('school_id')->constrained()->cascadeOnDelete();
            $t->foreignId('cbt_exam_id')->constrained()->cascadeOnDelete();
            $t->foreignId('student_id')->constrained()->cascadeOnDelete();
            $t->foreignId('classroom_id')->nullable()->constrained()->nullOnDelete();
            $t->string('status', 12)->default('mengerjakan');      // mengerjakan | selesai
            $t->timestamp('started_at');
            $t->timestamp('deadline_at');
            $t->timestamp('finished_at')->nullable();
            $t->string('finish_reason', 12)->nullable();            // siswa | waktu | admin
            $t->json('question_order');
            $t->json('option_orders');
            $t->decimal('pg_score', 7, 2)->default(0);
            $t->decimal('pg_max', 7, 2)->default(0);
            $t->decimal('essay_score', 7, 2)->nullable();
            $t->decimal('essay_max', 7, 2)->default(0);
            $t->decimal('final_score', 5, 2)->nullable();          // 0–100, terisi setelah semua essay dinilai
            $t->unsignedSmallInteger('correct')->default(0);
            $t->unsignedSmallInteger('wrong')->default(0);
            $t->unsignedSmallInteger('blank')->default(0);
            $t->unsignedSmallInteger('answered')->default(0);
            $t->unsignedSmallInteger('exits')->default(0);
            $t->timestamp('last_seen_at')->nullable();
            $t->string('ip', 45)->nullable();
            $t->timestamps();
            $t->unique(['cbt_exam_id', 'student_id']);
            $t->index(['school_id', 'student_id']);
        });

        Schema::create('cbt_answers', function (Blueprint $t) {
            $t->id();
            $t->foreignId('school_id')->constrained()->cascadeOnDelete();
            $t->foreignId('cbt_attempt_id')->constrained()->cascadeOnDelete();
            $t->foreignId('cbt_exam_question_id')->constrained()->cascadeOnDelete();
            $t->unsignedTinyInteger('choice')->nullable();          // indeks opsi asli (sebelum diacak)
            $t->text('text')->nullable();                           // jawaban essay
            $t->boolean('flagged')->default(false);
            $t->unsignedInteger('rev')->default(0);
            $t->boolean('is_correct')->nullable();
            $t->decimal('score', 6, 2)->nullable();
            $t->foreignId('graded_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('graded_at')->nullable();
            $t->string('note', 300)->nullable();
            $t->timestamps();
            $t->unique(['cbt_attempt_id', 'cbt_exam_question_id']);
        });

        Schema::create('cbt_exam_logs', function (Blueprint $t) {
            $t->id();
            $t->foreignId('school_id')->constrained()->cascadeOnDelete();
            $t->foreignId('cbt_exam_id')->constrained()->cascadeOnDelete();
            $t->foreignId('student_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $t->string('event', 24);
            $t->string('detail', 300)->nullable();
            $t->timestamp('created_at')->nullable();
            $t->index(['cbt_exam_id', 'student_id']);
        });
    }

    public function down(): void
    {
        foreach (['cbt_exam_logs', 'cbt_answers', 'cbt_attempts', 'cbt_exam_classes', 'cbt_exam_questions', 'cbt_exams'] as $t) {
            Schema::dropIfExists($t);
        }
        Schema::table('cbt_questions', fn (Blueprint $t) => $t->dropColumn(['type', 'image_path', 'score', 'essay_guide']));
        Schema::table('cbt_banks', function (Blueprint $t) {
            // MySQL memakai indeks (school_id, status) untuk foreign key school_id (indeks FK bawaannya
            // dibuang saat indeks ini dibuat), jadi siapkan indeks pengganti sebelum menghapusnya.
            $t->index('school_id');
            $t->dropIndex(['school_id', 'status']);
            $t->dropConstrainedForeignId('teacher_id');
            $t->dropConstrainedForeignId('reviewed_by');
            $t->dropColumn(['grade', 'status', 'review_note', 'reviewed_at', 'submitted_at']);
        });
    }
};
