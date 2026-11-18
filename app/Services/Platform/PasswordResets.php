<?php

namespace App\Services\Platform;

use App\Mail\ResetPasswordMail;
use App\Models\PasswordResetRequest;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Lupa password per sekolah. Email yang sama boleh terdaftar di dua sekolah; pencarian akun
 * selalu di dalam sekolah dari subdomain, dan token terikat ke user_id + school_id.
 */
class PasswordResets
{
    public const MINUTES = 60;

    public function __construct(private TenantContext $tenant, private AuditLogger $audit) {}

    /** Selalu "berhasil" dari sisi pengguna, agar tidak bisa dipakai menebak email yang terdaftar. */
    public function request(string $email): void
    {
        $users = User::query()->where('email', strtolower(trim($email)))->where('is_active', true)->whereNotNull('email')->get();

        foreach ($users as $user) {
            PasswordResetRequest::query()->where('user_id', $user->id)->whereNull('used_at')->delete();
            $token = Str::random(64);
            PasswordResetRequest::create(['user_id' => $user->id, 'token_hash' => hash('sha256', $token), 'expires_at' => now()->addMinutes(self::MINUTES)]);
            $url = route('portal.password.reset', ['tenant' => $this->tenant->get()->subdomain, 'token' => $token]);
            Mail::to($user->email)->send(new ResetPasswordMail($user, $this->tenant->get(), $url));
        }
    }

    public function find(string $token): ?PasswordResetRequest
    {
        return PasswordResetRequest::query()->with('user')->where('token_hash', hash('sha256', $token))
            ->whereNull('used_at')->where('expires_at', '>', now())->first();
    }

    public function reset(string $token, string $password): User
    {
        $req = $this->find($token);
        if (! $req || ! $req->user) {
            throw ValidationException::withMessages(['password' => 'Tautan reset sudah kedaluwarsa atau sudah dipakai. Minta tautan baru.']);
        }

        DB::transaction(function () use ($req, $password) {
            $req->user->forceFill(['password' => $password, 'must_change_password' => false, 'remember_token' => Str::random(60)])->save();
            $req->forceFill(['used_at' => now()])->save();
            PasswordResetRequest::query()->where('user_id', $req->user_id)->whereNull('used_at')->delete();
            if (config('session.driver') === 'database') {
                DB::table(config('session.table', 'sessions'))->where('user_id', $req->user_id)->delete();
            }
        });

        $this->audit->log('password.reset', "Password {$req->user->name} diatur ulang lewat email", $this->tenant->get(), $req->user);

        return $req->user;
    }
}
