<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Paket soal: pts (dikirim ke admin → bank soal PTS/PAS) atau uh (soal Ulangan Harian guru, tidak pernah dikirim ke admin). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cbt_banks', function (Blueprint $t) {
            $t->string('kind', 8)->default('pts')->after('teacher_id');
        });
    }

    public function down(): void
    {
        Schema::table('cbt_banks', function (Blueprint $t) {
            $t->dropColumn('kind');
        });
    }
};
