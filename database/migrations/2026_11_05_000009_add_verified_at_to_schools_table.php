<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Status verifikasi sekolah oleh Parama Tech (cek NPSN).
 * Sekolah yang mendaftar mandiri mulai belum terverifikasi: kuota dibatasi dan guru/siswa belum bisa login.
 * Sekolah yang sudah ada saat migrasi ini dijalankan dianggap terverifikasi agar tidak terganggu.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('schools', function (Blueprint $t) {
            $t->timestamp('verified_at')->nullable()->after('registered_via');
        });

        DB::table('schools')->whereNull('verified_at')->update(['verified_at' => now()]);
    }

    public function down(): void
    {
        Schema::table('schools', fn (Blueprint $t) => $t->dropColumn('verified_at'));
    }
};
