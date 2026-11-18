<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tahap 4b — notifikasi.
 *  - user_notifications : isi lonceng di aplikasi (tetap tersimpan walau push tidak aktif).
 *  - push_subscriptions : HP/browser yang mengizinkan push. Push dikirim TANPA isi; HP mengambil isinya
 *                         dari server MyPresence, jadi data sekolah tidak lewat server Google/Apple/Mozilla.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_notifications', function (Blueprint $t) {
            $t->id();
            $t->foreignId('school_id')->constrained()->cascadeOnDelete();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->string('type', 32);                 // assignment | announcement | absent_recap | import | system
            $t->string('title', 160);
            $t->string('body', 500)->nullable();
            $t->string('url', 255)->nullable();     // tujuan saat diketuk, mis. /siswa#/tugas
            $t->timestamp('read_at')->nullable();
            $t->timestamps();

            $t->index(['user_id', 'read_at']);
            $t->index(['school_id', 'created_at']);
        });

        Schema::create('push_subscriptions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('school_id')->constrained()->cascadeOnDelete();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->string('endpoint_hash', 64)->unique();
            $t->text('endpoint');
            $t->string('user_agent', 160)->nullable();
            $t->timestamp('last_success_at')->nullable();
            $t->timestamps();

            $t->index(['school_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('push_subscriptions');
        Schema::dropIfExists('user_notifications');
    }
};
