<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Harga paket langganan yang diisi super-admin di panel (Paket & harga).
 * Tabel tingkat platform (tanpa school_id). Harga kosong → memakai PLAN_*_PRICE di .env → harga umum.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plan_prices', function (Blueprint $t) {
            $t->string('plan', 32)->primary();             // starter | pro | all_service
            $t->unsignedInteger('price')->nullable();      // rupiah per siswa per bulan
            $t->string('updated_by_name', 150)->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plan_prices');
    }
};
