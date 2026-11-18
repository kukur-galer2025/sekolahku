<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Login sidik jari / Face ID (WebAuthn passkey). Hanya KUNCI PUBLIK yang disimpan; sidik jari tidak pernah keluar dari HP.
 * Satu baris = satu HP (authenticator) milik satu akun.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('webauthn_credentials', function (Blueprint $t) {
            $t->id();
            $t->foreignId('school_id')->constrained()->cascadeOnDelete();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->string('credential_id', 512);              // base64url
            $t->string('credential_hash', 64)->unique();   // sha256(credential_id) untuk indeks
            $t->text('public_key');                        // PEM
            $t->smallInteger('alg');                       // -7 ES256 | -257 RS256
            $t->unsignedBigInteger('sign_count')->default(0);
            $t->string('user_handle', 64);
            $t->string('name', 80)->nullable();            // "HP Android (Chrome)"
            $t->timestamp('last_used_at')->nullable();
            $t->timestamps();
            $t->index(['school_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webauthn_credentials');
    }
};
