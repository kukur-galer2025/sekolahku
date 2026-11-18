<?php

namespace App\Enums;

enum AttendanceStatus: string
{
    case Present = 'H'; // Hadir
    case Sick = 'S';    // Sakit
    case Permit = 'I';  // Izin
    case Absent = 'A';  // Alpa

    public function label(): string
    {
        return match ($this) {
            self::Present => 'Hadir',
            self::Sick => 'Sakit',
            self::Permit => 'Izin',
            self::Absent => 'Alpa',
        };
    }

    /** Guru hanya boleh mengoreksi ke Hadir, Sakit, atau Izin. Alpa hanya diberikan sistem. */
    public static function correctable(): array
    {
        return [self::Present->value, self::Sick->value, self::Permit->value];
    }
}
