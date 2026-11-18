<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tahap 4: panel super-admin & onboarding.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('schools', function (Blueprint $table) {
            $table->string('contact_name')->nullable()->after('address');
            $table->string('contact_phone', 20)->nullable()->after('contact_name');
            $table->string('registered_via', 16)->default('panel')->after('settings'); // self | panel | seed
            $table->timestamp('suspended_at')->nullable()->after('active_until');
            $table->string('suspended_reason')->nullable()->after('suspended_at');
        });

        // Jejak akses & tindakan penting. school_id NULL = tindakan di level platform.
        // Sekolah bisa melihat log miliknya sendiri, termasuk kapan Parama Tech masuk ke sekolahnya.
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('actor_name')->nullable();
            $table->string('action', 48);          // school.created, school.suspended, impersonation.started, ...
            $table->string('description');
            $table->string('ip', 45)->nullable();
            $table->json('meta')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['school_id', 'created_at']);
            $table->index('action');
        });

        // Token sekali pakai untuk "Masuk sebagai sekolah" (sesi tidak bisa menyeberang subdomain).
        Schema::create('impersonation_tokens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();       // akun sekolah yang dimasuki
            $table->foreignId('superadmin_id')->constrained('users')->cascadeOnDelete();
            $table->string('token_hash', 64)->unique();
            $table->string('reason');
            $table->timestamp('expires_at');
            $table->timestamp('used_at')->nullable();
            $table->timestamps();
        });

        // Lupa password. Tabel bawaan Laravel memakai email sebagai kunci unik, padahal email
        // yang sama boleh ada di dua sekolah; tabel ini memakai user_id dan school_id.
        Schema::create('password_reset_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('token_hash', 64)->unique();
            $table->timestamp('expires_at');
            $table->timestamp('used_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('password_reset_requests');
        Schema::dropIfExists('impersonation_tokens');
        Schema::dropIfExists('audit_logs');
        Schema::table('schools', fn (Blueprint $t) => $t->dropColumn(['contact_name', 'contact_phone', 'registered_via', 'suspended_at', 'suspended_reason']));
    }
};
