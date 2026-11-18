<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Penugasan: tenggat kini hari + tanggal + JAM. Tugas lama (jam kosong) dianggap berakhir pukul 23:59. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('assignments', function (Blueprint $t) {
            $t->time('due_time')->nullable()->after('due_date');
        });
    }

    public function down(): void
    {
        Schema::table('assignments', function (Blueprint $t) {
            $t->dropColumn('due_time');
        });
    }
};
