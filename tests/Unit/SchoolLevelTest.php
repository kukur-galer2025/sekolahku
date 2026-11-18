<?php

namespace Tests\Unit;

use App\Support\SchoolLevel;
use PHPUnit\Framework\TestCase;

class SchoolLevelTest extends TestCase
{
    public function test_jenjang_ditebak_dari_nama_sekolah(): void
    {
        $this->assertSame('smp', SchoolLevel::guess('SMP PEMBUDI DARMA 1 KESUGIHAN'));
        $this->assertSame('smp', SchoolLevel::guess('MTs Negeri 2'));
        $this->assertSame('sd', SchoolLevel::guess('SDN 1 Cilacap'));
        $this->assertSame('sma', SchoolLevel::guess('SMK Negeri 9 Contoh'));
        $this->assertSame('sma', SchoolLevel::guess('MA Al Hikmah'));
    }

    public function test_tingkat_sesuai_jenjang(): void
    {
        $this->assertSame(['7', '8', '9'], SchoolLevel::grades('smp'));
        $this->assertSame(['X', 'XI', 'XII'], SchoolLevel::grades('sma'));
        $this->assertSame(['1', '2', '3', '4', '5', '6'], SchoolLevel::grades('sd'));
    }

    public function test_tingkat_dari_nama_kelas(): void
    {
        foreach (['7A' => '7', 'VII-B' => '7', 'VIIIC' => '8', 'IX A' => '9', 'Kelas 8' => '8'] as $name => $g) {
            $this->assertSame($g, SchoolLevel::gradeOf($name, 'smp'), $name);
        }
        foreach (['X-FKK 1' => 'X', '11 IPA 2' => 'XI', 'XII' => 'XII', 'XIA' => 'XI'] as $name => $g) {
            $this->assertSame($g, SchoolLevel::gradeOf($name, 'sma'), $name);
        }
        $this->assertNull(SchoolLevel::gradeOf('X-FKK 1', 'smp'));
        $this->assertNull(SchoolLevel::gradeOf('Ruang Guru', 'sma'));
    }

    public function test_tahun_ajaran_berganti_bulan_juli(): void
    {
        $this->assertSame('2026/2027', SchoolLevel::academicYear(new \DateTime('2026-07-01')));
        $this->assertSame('2026/2027', SchoolLevel::academicYear(new \DateTime('2027-06-30')));
        $this->assertSame('2025/2026', SchoolLevel::academicYear(new \DateTime('2026-06-30')));
    }
}
