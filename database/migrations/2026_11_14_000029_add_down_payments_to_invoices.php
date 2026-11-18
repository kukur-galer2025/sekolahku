<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Invoice dengan DP / uang muka & pembayaran bertahap (dicatat Parama Tech di panel).
 * invoices.paid_amount = jumlah pembayaran yang sudah diterima; sisa = total − paid_amount.
 * invoices.activated_at = layanan sudah diaktifkan saat DP (masa aktif & kuota tidak diterapkan ulang saat lunas).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->unsignedBigInteger('paid_amount')->default(0)->after('total');
            $table->timestamp('activated_at')->nullable()->after('paid_at');
        });

        Schema::create('invoice_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 12);                       // dp | tambahan | pelunasan
            $table->unsignedBigInteger('amount');
            $table->date('paid_on');
            $table->string('method', 32);                     // transfer_manual | tunai | lainnya | gateway
            $table->string('ref', 120)->nullable();
            $table->string('note', 200)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['invoice_id', 'paid_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoice_payments');
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropColumn(['paid_amount', 'activated_at']);
        });
    }
};
