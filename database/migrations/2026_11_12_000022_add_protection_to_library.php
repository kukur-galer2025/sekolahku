<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Proteksi MP-Library:
 *  - library_items: kebijakan unduh per koleksi (default: hanya baca di aplikasi).
 *  - library_access_logs: jejak audit buka / baca / unduh / percobaan yang diblokir, lengkap dengan kode jejak watermark.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('library_items', function (Blueprint $t) {
            $t->boolean('allow_download')->default(false)->after('file_size');
            $t->unsignedTinyInteger('download_limit')->default(3)->after('allow_download'); // per pengguna per hari
        });

        Schema::create('library_access_logs', function (Blueprint $t) {
            $t->id();
            $t->foreignId('school_id')->constrained()->cascadeOnDelete();
            $t->foreignId('library_item_id')->constrained()->cascadeOnDelete();
            $t->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $t->string('user_name', 120);
            $t->string('role', 10);
            $t->string('event', 24);          // open, view, download, blocked_download, copy, print, screenshot, devtools, hidden, …
            $t->string('trace', 12)->nullable(); // kode jejak yang tercetak di watermark
            $t->string('detail', 190)->nullable();
            $t->string('ip', 45)->nullable();
            $t->string('device', 120)->nullable();
            $t->timestamp('created_at')->useCurrent();
            $t->index(['school_id', 'library_item_id', 'created_at']);
            $t->index(['school_id', 'trace']);
            $t->index(['school_id', 'user_id', 'event', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('library_access_logs');
        Schema::table('library_items', function (Blueprint $t) {
            $t->dropColumn(['allow_download', 'download_limit']);
        });
    }
};
