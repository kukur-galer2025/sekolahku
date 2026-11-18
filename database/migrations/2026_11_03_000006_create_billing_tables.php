<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tahap 6: langganan & pembayaran (paket per jumlah siswa). Nominal dalam rupiah bulat.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->string('number', 32)->nullable()->unique();     // INV/2026/11/000123
            $table->string('type', 12);                              // new | renewal | upgrade | custom
            $table->string('status', 16)->default('unpaid');         // unpaid | paid | void
            $table->unsignedInteger('student_count');                // kuota siswa yang dibeli (untuk upgrade: kuota total baru)
            $table->unsignedTinyInteger('months')->default(1);
            $table->unsignedInteger('unit_price');                   // rupiah per siswa per bulan
            $table->unsignedBigInteger('gross');                     // sebelum diskon
            $table->unsignedBigInteger('discount')->default(0);
            $table->unsignedBigInteger('tax')->default(0);
            $table->unsignedBigInteger('total');
            $table->string('description');
            $table->json('lines')->nullable();                       // rincian baris invoice
            $table->date('period_start')->nullable();                // diisi saat lunas
            $table->date('period_end')->nullable();
            $table->timestamp('due_at');
            $table->timestamp('paid_at')->nullable();
            $table->string('payment_method', 32)->nullable();        // midtrans:bank_transfer, xendit:QRIS, transfer_manual, ...
            $table->string('payment_ref')->nullable();               // id transaksi gateway / no. referensi transfer
            $table->string('gateway', 16)->nullable();
            $table->string('gateway_ref')->nullable()->index();      // order_id Midtrans / external_id Xendit
            $table->string('gateway_invoice_id')->nullable();        // id invoice Xendit
            $table->text('payment_url')->nullable();
            $table->unsignedSmallInteger('checkout_attempts')->default(0);
            $table->string('transfer_proof_path')->nullable();
            $table->timestamp('transfer_proof_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('notes')->nullable();
            $table->timestamps();

            $table->index(['school_id', 'status']);
        });

        // Semua notifikasi gateway (diterima atau ditolak) untuk audit & idempotensi.
        Schema::create('payment_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('invoice_id')->nullable()->constrained()->nullOnDelete();
            $table->string('gateway', 16);
            $table->string('event_key', 191)->unique();   // gateway + ref + status → event yang sama tidak diproses dua kali
            $table->string('status', 24);
            $table->boolean('verified')->default(false);
            $table->string('result', 32);                 // applied | duplicate | rejected | ignored
            $table->string('message')->nullable();
            $table->json('payload');
            $table->string('ip', 45)->nullable();
            $table->timestamp('created_at')->useCurrent();
        });

        Schema::table('schools', function (Blueprint $table) {
            $table->string('suspension_type', 12)->nullable()->after('suspended_reason'); // manual | billing
            $table->json('billing_notices')->nullable()->after('suspension_type');       // pengingat yang sudah terkirim
        });
    }

    public function down(): void
    {
        Schema::table('schools', fn (Blueprint $t) => $t->dropColumn(['suspension_type', 'billing_notices']));
        Schema::dropIfExists('payment_events');
        Schema::dropIfExists('invoices');
    }
};
