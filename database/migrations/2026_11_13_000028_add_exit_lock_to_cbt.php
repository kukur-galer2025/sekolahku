<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CBT terkunci: sanksi bila siswa keluar / meminimalkan aplikasi saat ujian.
 *  exit_action: catat (hanya dicatat) | kunci (ujian terkunci, pengawas membuka) | ulang (jawaban dihapus, mulai dari soal 1; waktu tetap berjalan)
 *  exit_allow : jumlah keluar yang dimaafkan sebelum sanksi (0 = langsung).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cbt_exams', function (Blueprint $t) {
            $t->string('exit_action', 8)->default('ulang')->after('max_exits');
            $t->unsignedTinyInteger('exit_allow')->default(0)->after('exit_action');
        });
        Schema::table('cbt_attempts', function (Blueprint $t) {
            $t->timestamp('locked_at')->nullable()->after('exits');
            $t->unsignedSmallInteger('restarts')->default(0)->after('locked_at');
        });
    }

    public function down(): void
    {
        Schema::table('cbt_attempts', fn (Blueprint $t) => $t->dropColumn(['locked_at', 'restarts']));
        Schema::table('cbt_exams', fn (Blueprint $t) => $t->dropColumn(['exit_action', 'exit_allow']));
    }
};
