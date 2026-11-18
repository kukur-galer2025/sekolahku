<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Kas Kelas: dicatat bendahara kelas (siswa berjabatan bendahara_1/bendahara_2 yang dipilih wali kelas),
 * dilihat seluruh siswa sekelas; wali kelas & admin hanya memantau.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('class_cash_entries', function (Blueprint $t) {
            $t->id();
            $t->foreignId('school_id')->constrained()->cascadeOnDelete();
            $t->foreignId('classroom_id')->constrained()->cascadeOnDelete();
            $t->string('type', 6);                      // masuk | keluar
            $t->string('category', 20);                 // iuran, sumbangan, lainnya | kegiatan, perlengkapan, kebersihan, lainnya
            $t->unsignedBigInteger('amount');           // rupiah
            $t->date('date');
            $t->string('description', 190)->nullable();
            $t->foreignId('student_id')->nullable()->constrained()->nullOnDelete(); // pembayar iuran
            $t->string('proof_path')->nullable();       // foto nota / bukti
            $t->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $t->string('recorded_name', 120);
            $t->timestamps();
            $t->index(['school_id', 'classroom_id', 'date']);
        });

        Schema::create('class_cash_settings', function (Blueprint $t) {
            $t->id();
            $t->foreignId('school_id')->constrained()->cascadeOnDelete();
            $t->foreignId('classroom_id')->unique()->constrained()->cascadeOnDelete();
            $t->unsignedInteger('dues_amount')->default(0); // iuran per periode (0 = tidak ada iuran wajib)
            $t->string('dues_period', 8)->default('minggu'); // minggu | bulan
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('class_cash_settings');
        Schema::dropIfExists('class_cash_entries');
    }
};
