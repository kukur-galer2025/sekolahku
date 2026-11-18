<?php

namespace App\Support;

use App\Models\School;

/**
 * Menu & istilah per sekolah (diatur super-admin di panel):
 *  - nama menu bisa diganti per portal (admin / guru / siswa),
 *  - menu fitur yang tidak dipakai bisa disembunyikan (menu inti tidak bisa),
 *  - menu di luar paket tetap dikunci oleh Plan (pengaturan ini tidak membuka fitur).
 * Disimpan di schools.settings['menu'] = ['names' => [rute => [peran => nama]], 'hidden' => [rute, ...]].
 *
 * CATALOG harus sama dengan NAV di public/pwa/app.js (rute, nama bawaan per peran).
 */
class SchoolMenu
{
    public const MAX = 24;

    public const ROLES = ['admin' => 'Admin', 'teacher' => 'Guru', 'student' => 'Siswa'];

    /** rute => [kelompok, inti?, [admin, guru, siswa] nama bawaan (null = tidak ada di portal itu)] */
    public const CATALOG = [
        'dash' => ['Inti', true, ['Dashboard', 'Dashboard', 'Dashboard']],
        'qr' => ['Inti', true, ['Absensi Masuk Kelas', 'Absensi Masuk Kelas', null]],
        'scan' => ['Inti', true, [null, null, 'Absensi Masuk Kelas']],
        'rekap' => ['Inti', true, [null, null, 'Rekap Absensi']],
        'jadwal' => ['Inti', true, ['Jadwal KBM', 'Jadwal Mengajar', 'Jadwal Pelajaran']],
        'info' => ['Inti', true, ['Agenda', 'Agenda', 'Informasi']],
        'guru' => ['Data sekolah', true, ['Data Guru', null, null]],
        'kartuguru' => ['Data sekolah', false, ['Kartu Login Guru', null, null]],
        'siswa' => ['Data sekolah', true, ['Data Siswa', null, null]],
        'jurnal' => ['Pembelajaran', false, ['Jurnal', 'Jurnal', null]],
        'tugas' => ['Pembelajaran', false, [null, 'Penugasan', 'Tugas-Ku']],
        'cbt' => ['Pembelajaran', false, ['CBT', 'CBT', 'CBT']],
        'library' => ['Pembelajaran', false, ['MP-Library', 'MP-Library', 'MP-Library']],
        'nilai' => ['Penilaian', false, [null, 'Daftar Nilai', null]],
        'leger' => ['Penilaian', false, ['Leger Rapor', 'Leger Rapor', null]],
        'wali' => ['Kesiswaan', false, ['Wali Kelas', 'Wali Kelas', null]],
        'struktur' => ['Kesiswaan', false, [null, null, 'Struktur Kelas']],
        'jkelas' => ['Kesiswaan', false, [null, null, 'Jurnal Kelas']],
        'piket' => ['Kesiswaan', false, [null, null, 'Jadwal Piket']],
        'kas' => ['Kesiswaan', false, [null, null, 'Kas Kelas']],
        'pembiasaan' => ['Program', false, ['Pembiasaan', 'Pembiasaan', 'Pembiasaan']],
        'denah' => ['Program', false, ['Denah Sekolah', 'Denah Sekolah', 'Denah Sekolah']],
    ];

    /** Nama bawaan sebuah menu di portal peran tertentu (null bila menu tidak ada di portal itu). */
    public static function default(string $key, string $role): ?string
    {
        return self::CATALOG[$key][2][array_search($role, array_keys(self::ROLES), true)] ?? null;
    }

    public static function hideable(string $key): bool
    {
        return isset(self::CATALOG[$key]) && ! self::CATALOG[$key][1];
    }

    /** Pengaturan tersimpan sebuah sekolah (sudah dibersihkan). */
    public static function of(?School $school): array
    {
        return self::clean((array) (($school?->settings ?? [])['menu'] ?? []));
    }

    /** Nama yang tampil untuk sekolah ini (bawaan bila tidak diganti). */
    public static function label(?School $school, string $key, string $role, ?string $fallback = null): string
    {
        return self::of($school)['names'][$key][$role] ?? $fallback ?? self::default($key, $role) ?? $key;
    }

    public static function isHidden(?School $school, string $key): bool
    {
        return in_array($key, self::of($school)['hidden'], true);
    }

    /**
     * Bersihkan masukan: hanya rute & peran yang dikenal, buang tag/spasi berlebih, maks. 24 karakter,
     * buang nama yang sama dengan bawaan; hanya menu fitur yang boleh disembunyikan.
     *
     * @return array{names: array<string, array<string, string>>, hidden: list<string>}
     */
    public static function clean(array $in): array
    {
        $names = [];
        foreach ((array) ($in['names'] ?? []) as $key => $roles) {
            if (! isset(self::CATALOG[$key]) || ! is_array($roles)) {
                continue;
            }
            foreach ($roles as $role => $name) {
                $def = isset(self::ROLES[$role]) ? self::default((string) $key, (string) $role) : null;
                $name = trim(preg_replace('/\s+/u', ' ', strip_tags((string) $name)));
                $name = mb_substr($name, 0, self::MAX);
                if ($def !== null && $name !== '' && $name !== $def) {
                    $names[$key][$role] = $name;
                }
            }
        }
        $hidden = array_values(array_unique(array_filter(array_map('strval', (array) ($in['hidden'] ?? [])), fn ($k) => self::hideable($k))));
        sort($hidden);

        return ['names' => $names, 'hidden' => $hidden];
    }

    /** Ringkasan perubahan untuk log aktivitas. */
    public static function diff(array $before, array $after): array
    {
        $out = [];
        foreach (self::CATALOG as $key => $c) {
            foreach (array_keys(self::ROLES) as $role) {
                $def = self::default($key, $role);
                if ($def === null) {
                    continue;
                }
                $a = $before['names'][$key][$role] ?? $def;
                $b = $after['names'][$key][$role] ?? $def;
                if ($a !== $b) {
                    $out[] = self::ROLES[$role].": $a → $b";
                }
            }
            $h1 = in_array($key, $before['hidden'], true);
            $h2 = in_array($key, $after['hidden'], true);
            if ($h1 !== $h2) {
                $out[] = ($c[2][0] ?? $c[2][1] ?? $c[2][2]).($h2 ? ' disembunyikan' : ' ditampilkan lagi');
            }
        }

        return $out;
    }

    /** Data katalog untuk tampilan panel (JSON). */
    public static function catalogForUi(): array
    {
        return collect(self::CATALOG)->map(fn ($c, $k) => ['key' => $k, 'group' => $c[0], 'core' => $c[1],
            'admin' => $c[2][0], 'teacher' => $c[2][1], 'student' => $c[2][2]])->values()->all();
    }
}
