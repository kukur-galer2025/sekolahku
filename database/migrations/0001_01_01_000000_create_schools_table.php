<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Tenant: satu baris = satu sekolah. Tidak memakai school_id karena inilah induknya.
        Schema::create('schools', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('subdomain', 63)->unique();
            $table->string('custom_domain')->nullable()->unique(); // tahap berikutnya: domain sendiri
            $table->string('npsn', 16)->nullable();
            $table->string('address')->nullable();
            $table->string('logo_path')->nullable();
            $table->string('status', 16)->default('trial');     // trial | active | suspended
            $table->string('timezone', 40)->default('Asia/Jakarta');
            $table->string('plan', 32)->default('per_student'); // model harga: per jumlah siswa
            $table->unsignedInteger('student_quota')->nullable(); // batas siswa sesuai paket; null = tanpa batas
            $table->timestamp('trial_ends_at')->nullable();
            $table->timestamp('active_until')->nullable();
            $table->json('settings')->nullable(); // batas presensi, hari sekolah, dll.
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('schools');
    }
};
