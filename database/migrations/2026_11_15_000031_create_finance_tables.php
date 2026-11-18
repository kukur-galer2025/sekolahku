<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Keuangan Parama Tech (data pusat, panel super-admin):
 * - invoices.unique_code : 3 digit kode unik yang ditambahkan ke total agar transfer mudah dikenali di mutasi bank.
 * - cash_entries         : pemasukan/pengeluaran manual (operasional, modal, prive, saldo awal).
 *                          Pembayaran invoice & gaji TIDAK disalin ke sini — dihitung langsung dari sumbernya.
 * - bank_mutations       : baris mutasi rekening yang diimpor; dicocokkan ke invoice / dicatat ke buku kas / diabaikan.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->unsignedSmallInteger('unique_code')->nullable()->after('tax');
        });

        Schema::create('cash_entries', function (Blueprint $table) {
            $table->id();
            $table->date('entry_date');
            $table->string('direction', 3);                   // in | out
            $table->string('category', 30);
            $table->unsignedBigInteger('amount');
            $table->string('description', 200);
            $table->string('method', 20)->nullable();         // transfer | tunai | lainnya
            $table->string('ref', 120)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['entry_date', 'direction']);
        });

        Schema::create('bank_mutations', function (Blueprint $table) {
            $table->id();
            $table->string('hash', 64)->unique();             // cegah baris yang sama diimpor dua kali
            $table->date('trx_date');
            $table->string('description', 255);
            $table->string('direction', 3);                   // in (kredit) | out (debit)
            $table->unsignedBigInteger('amount');
            $table->string('status', 10)->default('pending'); // pending | matched | recorded | ignored
            $table->foreignId('suggested_invoice_id')->nullable()->constrained('invoices')->nullOnDelete();
            $table->string('suggest_reason', 60)->nullable();
            $table->foreignId('invoice_id')->nullable()->constrained('invoices')->nullOnDelete();
            $table->foreignId('invoice_payment_id')->nullable()->constrained('invoice_payments')->nullOnDelete();
            $table->foreignId('cash_entry_id')->nullable()->constrained('cash_entries')->nullOnDelete();
            $table->string('batch', 40)->nullable();          // nama file impor
            $table->foreignId('handled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('handled_at')->nullable();
            $table->timestamps();
            $table->index(['status', 'trx_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bank_mutations');
        Schema::dropIfExists('cash_entries');
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropColumn('unique_code');
        });
    }
};
