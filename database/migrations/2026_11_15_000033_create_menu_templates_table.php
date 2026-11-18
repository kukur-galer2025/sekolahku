<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Template menu & istilah (dibuat super-admin): kumpulan nama menu per portal + menu yang disembunyikan,
 * untuk diterapkan cepat ke sekolah (mis. "Pesantren", "SMP"). Data pusat, bukan data sekolah.
 * Pengaturan per sekolah sendiri disimpan di schools.settings['menu'].
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('menu_templates', function (Blueprint $table) {
            $table->id();
            $table->string('name', 60)->unique();
            $table->string('description', 200)->nullable();
            $table->json('data');                 // {"names": {rute: {peran: nama}}, "hidden": [rute]}
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('menu_templates');
    }
};
