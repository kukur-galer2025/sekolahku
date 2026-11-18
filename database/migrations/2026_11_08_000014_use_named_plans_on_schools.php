<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Kolom schools.plan sebelumnya selalu 'per_student'. Sekarang berisi paket: starter | pro | all_service.
 * Sekolah lama dipindah ke Starter; super-admin bisa menggantinya di panel.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('schools')->whereNotIn('plan', ['starter', 'pro', 'all_service'])->update(['plan' => 'starter']);
        Schema::table('schools', function (Blueprint $t) {
            $t->string('plan', 32)->default('starter')->change();
        });
    }

    public function down(): void
    {
        Schema::table('schools', function (Blueprint $t) {
            $t->string('plan', 32)->default('per_student')->change();
        });
        DB::table('schools')->update(['plan' => 'per_student']);
    }
};
