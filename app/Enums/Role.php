<?php

namespace App\Enums;

enum Role: string
{
    case SuperAdmin = 'superadmin'; // tim Parama Tech, tanpa sekolah
    case Admin = 'admin';           // operator sekolah
    case Teacher = 'teacher';       // guru (login dengan NIK)
    case Student = 'student';       // siswa (login dengan NIS)

    public function label(): string
    {
        return match ($this) {
            self::SuperAdmin => 'Super Admin',
            self::Admin => 'Admin Sekolah',
            self::Teacher => 'Guru',
            self::Student => 'Siswa',
        };
    }
}
