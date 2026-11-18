<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Denah interaktif sekolah (Informasi → Sarpras). Diadaptasi dari "ParamaEdu Smart School Map Editor v1",
 * dibuat per sekolah (school_id) dan bisa ditautkan ke Kelas & Rombel.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('school_rooms', function (Blueprint $t) {
            $t->id();
            $t->foreignId('school_id')->constrained()->cascadeOnDelete();
            $t->foreignId('classroom_id')->nullable()->constrained()->nullOnDelete();
            $t->string('building', 20);                      // "A", "Gedung Utama", ...
            $t->unsignedTinyInteger('floor')->default(1);    // 1–10
            $t->string('name', 100);
            $t->string('category', 16)->default('fasilitas'); // kelas | lab | kantor | fasilitas
            $t->string('grade', 8)->nullable();              // tingkat kelas (X, XI, 7, ...) untuk warna
            $t->unsignedSmallInteger('capacity')->default(0);
            $t->decimal('x', 8, 2)->default(20);
            $t->decimal('y', 8, 2)->default(20);
            $t->decimal('width', 8, 2)->default(150);
            $t->decimal('height', 8, 2)->default(100);
            $t->timestamps();
            $t->index(['school_id', 'building', 'floor']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('school_rooms');
    }
};
