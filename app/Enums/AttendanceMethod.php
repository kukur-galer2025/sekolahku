<?php

namespace App\Enums;

enum AttendanceMethod: string
{
    case Qr = 'qr';         // siswa scan QR kelas
    case Manual = 'manual'; // dikoreksi guru/admin
    case Auto = 'auto';     // alpa otomatis oleh sistem setelah batas presensi
}
