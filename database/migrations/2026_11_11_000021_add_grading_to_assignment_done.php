<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Pengumpulan tugas: guru memberi nilai & catatan per siswa. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('assignment_done', function (Blueprint $t) {
            $t->decimal('score', 5, 2)->nullable()->after('done_at');
            $t->string('feedback', 500)->nullable()->after('score');
            $t->foreignId('graded_by')->nullable()->after('feedback')->constrained('users')->nullOnDelete();
            $t->timestamp('graded_at')->nullable()->after('graded_by');
        });
    }

    public function down(): void
    {
        Schema::table('assignment_done', function (Blueprint $t) {
            $t->dropConstrainedForeignId('graded_by');
            $t->dropColumn(['score', 'feedback', 'graded_at']);
        });
    }
};
