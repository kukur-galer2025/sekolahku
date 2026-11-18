<?php

namespace App\Services\Platform;

use App\Enums\Role;
use App\Models\ImpersonationToken;
use App\Models\School;
use App\Models\User;
use App\Tenancy\SchoolScope;
use App\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

/**
 * "Masuk sebagai sekolah" untuk membantu admin sekolah.
 *
 * Sesi super-admin (mypresence.id) tidak berlaku di subdomain sekolah, jadi panel membuat
 * token sekali pakai berumur 2 menit. Subdomain sekolah menukarnya dengan sesi admin sekolah
 * bertanda "impersonator". Setiap langkah (mulai, masuk, selesai) tercatat di log sekolah itu.
 */
class Impersonation
{
    public const SESSION_KEY = 'impersonator';

    public function __construct(private TenantContext $tenant, private AuditLogger $audit) {}

    public function start(School $school, User $superadmin, string $reason): string
    {
        abort_unless($superadmin->role === Role::SuperAdmin, 403);
        abort_if($school->isSuspended(), 422, 'Aktifkan sekolah terlebih dahulu.');

        $admin = User::query()->withoutGlobalScope(SchoolScope::class)
            ->where('school_id', $school->id)->where('role', Role::Admin->value)->where('is_active', true)->orderBy('id')->first();
        abort_unless($admin, 422, 'Sekolah ini belum punya akun admin aktif.');

        $token = Str::random(64);
        ImpersonationToken::create([
            'school_id' => $school->id, 'user_id' => $admin->id, 'superadmin_id' => $superadmin->id,
            'token_hash' => hash('sha256', $token), 'reason' => $reason, 'expires_at' => now()->addMinutes(2),
        ]);

        $this->audit->log('impersonation.started', "Parama Tech ({$superadmin->name}) membuka akses ke sekolah sebagai {$admin->name}. Alasan: $reason", $school, $superadmin, ['reason' => $reason]);

        return route('portal.impersonate', ['tenant' => $school->subdomain, 'token' => $token]);
    }

    /** Dijalankan di subdomain sekolah (tenant aktif): token sekolah lain tidak akan ditemukan. */
    public function consume(Request $request, string $token): bool
    {
        $t = ImpersonationToken::query()->where('token_hash', hash('sha256', $token))->whereNull('used_at')->where('expires_at', '>', now())->first();
        if (! $t) {
            return false;
        }
        // Sekali pakai secara atomik: dari dua permintaan bersamaan, hanya satu yang berhasil menandai token.
        $claimed = ImpersonationToken::query()->whereKey($t->id)->whereNull('used_at')->update(['used_at' => now()]);
        if ($claimed !== 1) {
            return false;
        }

        $user = User::find($t->user_id);
        $superadmin = User::query()->withoutGlobalScope(SchoolScope::class)->find($t->superadmin_id);
        if (! $user || ! $superadmin) {
            return false;
        }

        Auth::login($user);
        $request->session()->regenerate();
        $request->session()->put(self::SESSION_KEY, ['id' => $superadmin->id, 'name' => $superadmin->name, 'reason' => $t->reason, 'since' => now()->toIso8601String()]);

        $this->audit->log('impersonation.entered', "Parama Tech ({$superadmin->name}) masuk ke portal sekolah sebagai {$user->name}", $this->tenant->get(), $superadmin, ['reason' => $t->reason]);

        return true;
    }

    public function end(Request $request): void
    {
        $who = $request->session()->get(self::SESSION_KEY);
        if ($who) {
            $this->audit->log('impersonation.ended', "Parama Tech ({$who['name']}) keluar dari portal sekolah", $this->tenant->get(), null, ['by' => $who['id']], $who['name'].' (Parama Tech)');
        }
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
    }
}
