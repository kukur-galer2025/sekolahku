<?php

namespace App\Services;

use App\Models\School;
use Symfony\Component\HttpFoundation\IpUtils;

/**
 * Pembatas jaringan WiFi sekolah untuk presensi.
 *
 * Browser/PWA tidak bisa membaca nama WiFi (SSID), jadi yang dicek adalah IP publik
 * yang dilihat server. Semua HP yang tersambung ke WiFi sekolah keluar ke internet lewat
 * IP publik sekolah (NAT); HP yang memakai kuota atau WiFi rumah punya IP lain.
 *
 * Daftar jaringan disimpan per sekolah di schools.settings.attendance.networks:
 *   [{"cidr": "103.10.20.30/32", "label": "IndiHome gedung A"}, ...]
 *
 * Gagal tertutup: bila belum ada jaringan terdaftar, scan ditolak.
 */
class SchoolNetwork
{
    /** @return list<array{cidr:string,label:?string}> */
    public function networks(School $school): array
    {
        return array_values($school->setting('attendance.networks', []));
    }

    public function isConfigured(School $school): bool
    {
        return $this->networks($school) !== [];
    }

    public function allows(School $school, ?string $ip): bool
    {
        if (! $ip || ! $this->isConfigured($school)) {
            return false;
        }

        return IpUtils::checkIp($ip, array_column($this->networks($school), 'cidr'));
    }

    /**
     * Rentang yang didaftarkan dari IP perangkat saat ini.
     * IPv4: IP itu sendiri (/32). IPv6: prefiks /64, karena tiap HP di jaringan
     * yang sama mendapat alamat IPv6 berbeda di dalam prefiks yang sama.
     */
    public function rangeFor(string $ip): string
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            return $this->ipv6Prefix64($ip).'/64';
        }

        return $ip.'/32';
    }

    public function isValidCidr(string $cidr): bool
    {
        [$ip, $bits] = array_pad(explode('/', $cidr, 2), 2, null);
        $v6 = (bool) filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6);
        $v4 = (bool) filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4);

        if (! $v4 && ! $v6) {
            return false;
        }
        if ($bits === null) {
            return true;
        }
        if (! ctype_digit($bits)) {
            return false;
        }

        // Tolak rentang terlalu lebar yang praktis membuka untuk semua orang.
        return $v4 ? ((int) $bits >= 16 && (int) $bits <= 32) : ((int) $bits >= 40 && (int) $bits <= 128);
    }

    private function ipv6Prefix64(string $ip): string
    {
        $bin = inet_pton($ip);

        return inet_ntop(substr($bin, 0, 8).str_repeat("\0", 8));
    }
}
