<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('students', function (Blueprint $table) {
            $table->string('gender', 1)->nullable()->after('name'); // L | P
        });

        // Riwayat impor Excel per sekolah: pratinjau → antrean → selesai/gagal.
        Schema::create('data_imports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type', 24);              // teachers | students | lesson_periods
            $table->string('mode', 16)->default('add'); // add | replace
            $table->string('file_name');
            $table->string('file_path');             // imports/school_{id}/{uuid}.xlsx (disk local, privat)
            $table->unsignedInteger('file_size');
            $table->string('status', 16)->default('preview'); // preview | queued | processing | done | failed
            $table->json('preview')->nullable();     // ringkasan hasil baca file
            $table->json('result')->nullable();      // ringkasan hasil simpan
            $table->text('credentials')->nullable(); // TERENKRIPSI: akun baru + password awal, dihapus otomatis setelah 7 hari
            $table->text('error')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['school_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('data_imports');
        Schema::table('students', fn (Blueprint $t) => $t->dropColumn('gender'));
    }
};
