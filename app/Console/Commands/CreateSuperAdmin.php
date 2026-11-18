<?php

namespace App\Console\Commands;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Validation\Rules\Password;
use Illuminate\Support\Facades\Validator;

/**
 * Produksi: buat akun super-admin Parama Tech tanpa seeder demo.
 *   php artisan platform:superadmin superadmin admin@paramatech.id "Tim Parama Tech"
 * Menjalankan ulang untuk username yang sama = reset password (+ reset 2FA bila --reset-2fa).
 */
class CreateSuperAdmin extends Command
{
    protected $signature = 'platform:superadmin {username} {email} {name=Parama Tech} {--reset-2fa : hapus 2FA (mis. HP hilang)} {--from-env : ambil password dari SUPERADMIN_PASSWORD di .env (cPanel tanpa terminal)}';

    protected $description = 'Buat atau reset akun super-admin panel Parama Tech';

    public function handle(): int
    {
        if ($this->option('from-env')) {
            // Untuk Cron Jobs cPanel (tanpa terminal). Hapus SUPERADMIN_PASSWORD dari .env setelah berhasil.
            $password = $confirm = (string) env('SUPERADMIN_PASSWORD');
        } else {
            $password = $this->secret('Password baru (min. 12 karakter, huruf besar/kecil + angka)');
            $confirm = $this->secret('Ulangi password');
        }
        $v = Validator::make(
            ['username' => $this->argument('username'), 'email' => $this->argument('email'), 'password' => $password, 'password_confirmation' => $confirm],
            ['username' => ['required', 'alpha_dash', 'max:50'], 'email' => ['required', 'email'], 'password' => ['required', 'confirmed', Password::min(12)->mixedCase()->numbers()]]
        );
        if ($v->fails()) {
            foreach ($v->errors()->all() as $e) {
                $this->error($e);
            }

            return self::FAILURE;
        }

        $user = User::query()->whereNull('school_id')->where('username', $this->argument('username'))->first();
        $isNew = ! $user;
        $user ??= new User(['role' => Role::SuperAdmin, 'username' => $this->argument('username')]);
        $user->forceFill(['role' => Role::SuperAdmin, 'name' => $this->argument('name'), 'email' => $this->argument('email'), 'password' => $password, 'is_active' => true]);
        if ($this->option('reset-2fa')) {
            $user->forceFill(['two_factor_secret' => null, 'two_factor_confirmed_at' => null, 'two_factor_recovery_codes' => null]);
        }
        $user->save();

        $this->info(($isNew ? 'Super-admin dibuat' : 'Super-admin diperbarui').": {$user->username} <{$user->email}>");
        if ($this->option('from-env')) {
            $this->warn('Sekarang HAPUS isi SUPERADMIN_PASSWORD dari .env.');
        }
        $this->line('Masuk di https://'.config('tenancy.central_domain').'/panel/masuk — 2FA wajib diaktifkan saat login pertama.');

        return self::SUCCESS;
    }
}
