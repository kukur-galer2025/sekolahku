<?php

namespace App\Models;

use App\Enums\Role;
use App\Tenancy\BelongsToSchool;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use BelongsToSchool, HasFactory, Notifiable;

    /** Sama dengan default kolom di database, supaya model yang baru dibuat tidak bernilai null (dianggap nonaktif). */
    protected $attributes = ['is_active' => true, 'must_change_password' => false];

    protected $fillable = [
        'role', 'username', 'name', 'email', 'phone', 'photo_path', 'password',
        'must_change_password', 'is_active',
    ];

    protected $hidden = ['password', 'remember_token', 'two_factor_secret', 'two_factor_recovery_codes'];

    protected function casts(): array
    {
        return [
            'role' => Role::class,
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'password' => 'hashed',
            'must_change_password' => 'boolean',
            'is_active' => 'boolean',
            'two_factor_secret' => 'encrypted',
            'two_factor_recovery_codes' => 'encrypted:array',
            'two_factor_confirmed_at' => 'datetime',
        ];
    }

    /** Hanya super-admin yang boleh tanpa sekolah. */
    public function allowsCentralRecord(): bool
    {
        return $this->role === Role::SuperAdmin;
    }

    public function isRole(Role ...$roles): bool
    {
        return in_array($this->role, $roles, true);
    }

    public function teacher(): HasOne
    {
        return $this->hasOne(Teacher::class);
    }

    public function student(): HasOne
    {
        return $this->hasOne(Student::class);
    }
}
