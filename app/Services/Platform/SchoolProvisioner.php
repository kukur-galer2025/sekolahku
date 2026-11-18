<?php

namespace App\Services\Platform;

use App\Enums\Role;
use App\Enums\SchoolStatus;
use App\Models\School;
use App\Models\User;
use App\Tenancy\Subdomain;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Membuat sekolah (tenant) baru beserta akun admin pertamanya.
 * Dipakai oleh pendaftaran mandiri (/daftar) dan panel super-admin.
 */
class SchoolProvisioner
{
    public function __construct(private TenantContext $tenant, private AuditLogger $audit, private EmailVerification $verification) {}

    /**
     * @param  array{name:string,subdomain:string,npsn?:?string,address?:?string,timezone?:string,contact_name?:?string,contact_phone?:?string,
     *               status?:string,student_quota?:?int,active_until?:mixed,admin_name:string,admin_email:string,admin_password?:?string}  $d
     * @return array{school:School,admin:User,temporary_password:?string}
     */
    public function create(array $d, string $via, ?User $by = null): array
    {
        $temporary = empty($d['admin_password']) ? Str::password(12, symbols: false) : null;

        [$school, $admin] = DB::transaction(function () use ($d, $via, $temporary) {
            $status = SchoolStatus::from($d['status'] ?? SchoolStatus::Trial->value);
            $school = School::create([
                'name' => $d['name'],
                'subdomain' => Subdomain::normalize($d['subdomain']),
                'npsn' => $d['npsn'] ?? null,
                'level' => \App\Support\SchoolLevel::normalize($d['level'] ?? null, $d['name']),
                'address' => $d['address'] ?? null,
                'contact_name' => $d['contact_name'] ?? $d['admin_name'],
                'contact_phone' => $d['contact_phone'] ?? null,
                'timezone' => $d['timezone'] ?? config('tenancy.default_timezone'),
                'status' => $status,
                'plan' => \App\Support\Plan::normalize($d['plan'] ?? null),
                'student_quota' => $d['student_quota'] ?? config('tenancy.trial_student_quota'),
                'trial_ends_at' => $status === SchoolStatus::Trial ? now()->addDays(config('tenancy.trial_days')) : null,
                'active_until' => $d['active_until'] ?? null,
                'registered_via' => $via,
                // Didaftarkan lewat panel/tim sales = sudah dicek Parama Tech.
                'verified_at' => $via === 'self' ? null : now(),
                'settings' => ['attendance_cutoff_period' => 1, 'school_days' => config('tenancy.default_school_days'),
                    'attendance' => ['qr_mode' => 'static', 'networks' => []]],
            ]);

            $admin = $this->tenant->run($school, fn () => User::create([
                'role' => Role::Admin, 'username' => 'admin', 'name' => $d['admin_name'],
                'email' => strtolower($d['admin_email']), 'phone' => $d['contact_phone'] ?? null,
                'password' => $d['admin_password'] ?? $temporary, 'must_change_password' => $temporary !== null,
            ]));

            return [$school, $admin];
        });

        $this->audit->log('school.created', ($via === 'self' ? 'Sekolah mendaftar mandiri' : 'Sekolah dibuat dari panel').": {$school->name} ({$school->subdomain})", $school, $by ?? $admin,
            ['via' => $via, 'status' => $school->status->value, 'quota' => $school->student_quota]);

        $this->verification->send($admin, $school);

        return ['school' => $school, 'admin' => $admin, 'temporary_password' => $temporary];
    }
}
