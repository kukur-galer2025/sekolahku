<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Perangkat terdaftar per akun. Token acak disimpan di cookie perangkat (httpOnly);
        // di database hanya hash-nya.
        Schema::create('user_devices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('token_hash', 64)->unique();
            $table->string('name')->nullable();       // ringkasan user agent, mis. "Android · Chrome"
            $table->string('ip', 45)->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->foreignId('revoked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['school_id', 'user_id', 'revoked_at']);
        });

        // Jejak audit presensi: dari perangkat dan IP mana scan dilakukan.
        Schema::table('attendances', function (Blueprint $table) {
            $table->foreignId('device_id')->nullable()->after('recorded_by')->constrained('user_devices')->nullOnDelete();
            $table->string('ip', 45)->nullable()->after('device_id');
        });
    }

    public function down(): void
    {
        Schema::table('attendances', function (Blueprint $table) {
            $table->dropConstrainedForeignId('device_id');
            $table->dropColumn('ip');
        });
        Schema::dropIfExists('user_devices');
    }
};
