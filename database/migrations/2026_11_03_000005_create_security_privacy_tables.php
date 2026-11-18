<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tahap 5: berkas, backup, ekspor & hapus data, dokumen hukum, persetujuan orang tua, 2FA.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Persetujuan orang tua/wali atas pemrosesan data anak (UU PDP Pasal 25; PP 33/2026, berlaku 16 Jan 2027).
        // Sekolah (pengendali) mengumpulkan persetujuan; MyPresence menyimpan buktinya.
        Schema::table('students', function (Blueprint $table) {
            $table->timestamp('parent_consent_at')->nullable()->after('is_active');
            $table->string('parent_consent_method', 16)->nullable()->after('parent_consent_at'); // kertas | elektronik
            $table->string('parent_consent_ref')->nullable()->after('parent_consent_method');    // no. formulir / keterangan bukti
            $table->foreignId('parent_consent_by')->nullable()->after('parent_consent_ref')->constrained('users')->nullOnDelete();
        });

        // 2FA (TOTP) — wajib untuk super-admin.
        Schema::table('users', function (Blueprint $table) {
            $table->text('two_factor_secret')->nullable()->after('password');
            $table->text('two_factor_recovery_codes')->nullable()->after('two_factor_secret');
            $table->timestamp('two_factor_confirmed_at')->nullable()->after('two_factor_recovery_codes');
            $table->unsignedBigInteger('two_factor_last_step')->nullable()->after('two_factor_confirmed_at'); // cegah kode dipakai ulang
        });

        // Persetujuan dokumen hukum, berversi (siapa, kapan, dari IP mana).
        Schema::create('legal_acceptances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('document', 16);   // terms | privacy | dpa
            $table->string('version', 16);
            $table->string('signer_name')->nullable();
            $table->string('signer_position')->nullable();
            $table->string('ip', 45)->nullable();
            $table->string('user_agent')->nullable();
            $table->timestamp('accepted_at');

            $table->index(['school_id', 'document', 'version']);
        });

        // Ekspor data sekolah (hak sekolah atas datanya). Berkas dihapus otomatis setelah 7 hari.
        Schema::create('data_exports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status', 16)->default('queued'); // queued | done | failed
            $table->string('file_path')->nullable();
            $table->unsignedBigInteger('file_size')->nullable();
            $table->json('summary')->nullable();
            $table->text('error')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
        });

        Schema::table('schools', function (Blueprint $table) {
            $table->timestamp('deletion_requested_at')->nullable()->after('suspended_reason');
            $table->string('deletion_reason')->nullable()->after('deletion_requested_at');
        });
    }

    public function down(): void
    {
        Schema::table('schools', fn (Blueprint $t) => $t->dropColumn(['deletion_requested_at', 'deletion_reason']));
        Schema::dropIfExists('data_exports');
        Schema::dropIfExists('legal_acceptances');
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn(['two_factor_secret', 'two_factor_recovery_codes', 'two_factor_confirmed_at', 'two_factor_last_step']));
        Schema::table('students', function (Blueprint $t) {
            $t->dropConstrainedForeignId('parent_consent_by');
            $t->dropColumn(['parent_consent_at', 'parent_consent_method', 'parent_consent_ref']);
        });
    }
};
