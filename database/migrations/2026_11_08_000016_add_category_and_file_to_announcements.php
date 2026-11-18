<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Informasi per bidang: umum, kesiswaan, kurikulum, humas, sarpras.
 * Sarpras boleh melampirkan berkas JPG/PDF (mis. denah ruang kelas).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('announcements', function (Blueprint $t) {
            $t->string('category', 16)->default('umum')->after('audience');
            $t->string('file_path')->nullable()->after('category');
            $t->string('file_name', 160)->nullable()->after('file_path');
            $t->string('file_type', 8)->nullable()->after('file_name'); // PDF | JPG
            $t->index(['school_id', 'category']);
        });
    }

    public function down(): void
    {
        Schema::table('announcements', function (Blueprint $t) {
            $t->dropIndex(['school_id', 'category']);
            $t->dropColumn(['category', 'file_path', 'file_name', 'file_type']);
        });
    }
};
