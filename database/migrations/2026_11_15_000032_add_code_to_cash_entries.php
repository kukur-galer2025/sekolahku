<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Kode referensi buku kas: KM/2026/11/0001 (kas masuk) dan KK/2026/11/0001 (kas keluar),
 * nomor urut per arah per bulan. Catatan yang sudah ada diberi kode sesuai urutan tanggal.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cash_entries', function (Blueprint $table) {
            $table->string('code', 24)->nullable()->unique()->after('id');
        });

        $seq = [];
        foreach (DB::table('cash_entries')->orderBy('entry_date')->orderBy('id')->get(['id', 'entry_date', 'direction']) as $e) {
            $prefix = ($e->direction === 'in' ? 'KM' : 'KK').'/'.date('Y/m', strtotime((string) $e->entry_date)).'/';
            $seq[$prefix] = ($seq[$prefix] ?? 0) + 1;
            DB::table('cash_entries')->where('id', $e->id)->update(['code' => $prefix.str_pad((string) $seq[$prefix], 4, '0', STR_PAD_LEFT)]);
        }
    }

    public function down(): void
    {
        Schema::table('cash_entries', function (Blueprint $table) {
            $table->dropUnique(['code']);
            $table->dropColumn('code');
        });
    }
};
