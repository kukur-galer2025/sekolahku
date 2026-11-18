<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Menu & fitur per paket yang diatur super-admin di panel (Paket & harga).
 * Satu baris per kunci fitur; 'plans' = daftar paket yang memuatnya. Tanpa baris → bawaan config/billing.php.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plan_features', function (Blueprint $t) {
            $t->string('feature', 40)->primary();
            $t->json('plans');
            $t->string('updated_by_name', 120)->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plan_features');
    }
};
