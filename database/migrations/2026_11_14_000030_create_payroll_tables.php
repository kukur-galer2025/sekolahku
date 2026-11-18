<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Penggajian karyawan ParamaEdu.Tech (data pusat, hanya panel super-admin).
 * employees    = data karyawan + komponen gaji bawaan (dipakai saat membuat periode baru).
 * payroll_runs = satu periode gaji (bulan & tahun).
 * payroll_items= baris gaji per karyawan per periode (salinan data saat itu, sesuai Template Tabel Penggajian).
 */
return new class extends Migration
{
    private const MONEY = ['base_salary', 'allowance_position', 'allowance_meal', 'allowance_transport', 'bpjs_tk', 'bpjs_kes', 'pph21', 'other_deduction'];

    public function up(): void
    {
        Schema::create('employees', function (Blueprint $table) {
            $table->id();
            $table->string('nik', 40)->unique();                      // NIK / ID karyawan
            $table->string('name', 120);
            $table->string('position', 80)->nullable();               // jabatan
            $table->string('work_status', 20)->default('tetap');      // tetap | kontrak | paruh_waktu | magang | freelance
            $table->date('join_date')->nullable();
            $table->string('phone', 30)->nullable();
            $table->string('bank_name', 60)->nullable();
            $table->string('bank_account', 40)->nullable();
            $table->string('bank_holder', 120)->nullable();
            foreach (self::MONEY as $c) {
                $table->unsignedBigInteger($c)->default(0);         // komponen bawaan per bulan
            }
            $table->boolean('active')->default(true);
            $table->string('notes', 200)->nullable();
            $table->timestamps();
        });

        Schema::create('payroll_runs', function (Blueprint $table) {
            $table->id();
            $table->unsignedSmallInteger('year');
            $table->unsignedTinyInteger('month');
            $table->date('pay_date')->nullable();                     // tanggal pembayaran
            $table->unsignedTinyInteger('work_days')->default(22);   // hari kerja bawaan periode ini
            $table->string('company', 120)->default('ParamaEdu.Tech');
            $table->string('notes', 200)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['year', 'month']);
        });

        Schema::create('payroll_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payroll_run_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->nullable()->constrained()->nullOnDelete();
            $table->string('nik', 40);
            $table->string('name', 120);
            $table->string('position', 80)->nullable();
            $table->string('work_status', 20)->nullable();
            $table->unsignedTinyInteger('work_days')->default(0);
            $table->unsignedTinyInteger('present_days')->default(0);
            // Pendapatan
            $table->unsignedBigInteger('base_salary')->default(0);
            $table->unsignedBigInteger('allowance_position')->default(0);
            $table->unsignedBigInteger('allowance_meal')->default(0);
            $table->unsignedBigInteger('allowance_transport')->default(0);
            $table->unsignedBigInteger('overtime')->default(0);
            $table->unsignedBigInteger('bonus')->default(0);
            $table->unsignedBigInteger('gross')->default(0);          // total pendapatan
            // Potongan
            $table->unsignedBigInteger('bpjs_tk')->default(0);
            $table->unsignedBigInteger('bpjs_kes')->default(0);
            $table->unsignedBigInteger('pph21')->default(0);
            $table->unsignedBigInteger('other_deduction')->default(0);
            $table->unsignedBigInteger('deductions')->default(0);     // total potongan
            $table->bigInteger('net')->default(0);                    // gaji bersih
            // Pembayaran
            $table->date('paid_on')->nullable();
            $table->string('method', 20)->nullable();                 // transfer | tunai | lainnya
            $table->string('pay_status', 12)->default('belum');       // belum | parsial | dibayar
            $table->unsignedBigInteger('paid_amount')->default(0);    // dipakai saat DP/parsial
            $table->string('note', 200)->nullable();
            $table->timestamps();
            $table->index(['payroll_run_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payroll_items');
        Schema::dropIfExists('payroll_runs');
        Schema::dropIfExists('employees');
    }
};
