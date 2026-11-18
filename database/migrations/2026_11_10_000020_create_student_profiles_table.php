<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Biodata peserta didik untuk halaman "Keterangan tentang diri peserta didik" di buku rapor. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('student_profiles', function (Blueprint $t) {
            $t->id();
            $t->foreignId('school_id')->constrained()->cascadeOnDelete();
            $t->foreignId('student_id')->unique()->constrained()->cascadeOnDelete();
            $t->string('birth_place', 60)->nullable();
            $t->string('religion', 30)->nullable();
            $t->string('family_status', 30)->nullable();
            $t->string('child_order', 5)->nullable();
            $t->string('address', 300)->nullable();
            $t->string('phone', 30)->nullable();
            $t->string('origin_school', 120)->nullable();
            $t->string('accepted_class', 30)->nullable();
            $t->date('accepted_date')->nullable();
            $t->string('father_name', 120)->nullable();
            $t->string('mother_name', 120)->nullable();
            $t->string('parents_address', 300)->nullable();
            $t->string('parents_phone', 30)->nullable();
            $t->string('father_job', 60)->nullable();
            $t->string('mother_job', 60)->nullable();
            $t->string('guardian_name', 120)->nullable();
            $t->string('guardian_address', 300)->nullable();
            $t->string('guardian_phone', 30)->nullable();
            $t->string('guardian_job', 60)->nullable();
            $t->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('student_profiles');
    }
};
