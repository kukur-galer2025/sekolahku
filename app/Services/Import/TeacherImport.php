<?php

namespace App\Services\Import;

use App\Enums\Role;
use App\Models\Classroom;
use App\Models\Teacher;
use App\Models\User;

/**
 * Format "Database Guru dan Karyawan" (sama dengan prototipe):
 *   NIK | NAMA | MATA PELAJARAN | JABATAN | WALI KELAS
 *
 * - Dicocokkan lewat NIK: guru yang sudah ada diperbarui, yang baru dibuatkan akun.
 * - Guru tidak pernah dihapus lewat impor.
 * - Kolom WALI KELAS (nama kelas) menetapkan wali kelas.
 */
class TeacherImport
{
    use ImportHelpers;

    private const REQUIRED = ['nik' => '/^NIK$/', 'name' => '/^NAMA/'];

    private const OPTIONAL = ['subject' => '/MATA ?PELAJARAN|MAPEL/', 'position' => '/^JABATAN/', 'homeroom' => '/^WALI/'];

    /** @return array{rows:list<array>,errors:list<string>,warnings:list<string>} */
    public function rows(array $sheets): array
    {
        $out = ['rows' => [], 'errors' => [], 'warnings' => []];
        $names = array_keys($sheets);
        usort($names, fn ($a, $b) => (int) ! preg_match('/daftar|guru/i', $a) <=> (int) ! preg_match('/daftar|guru/i', $b));

        foreach ($names as $name) {
            $h = $this->findHeader($sheets[$name], self::REQUIRED, self::OPTIONAL);
            if (! $h) {
                continue;
            }
            [$hi, $c] = $h;
            $seen = [];
            $noNik = [];

            foreach (array_slice($sheets[$name], $hi + 1) as $row) {
                $nik = preg_replace('/\s+/', '', $this->str($row, $c['nik']));
                $nm = $this->str($row, $c['name']);
                if ($nik === '' && $nm === '') {
                    continue;
                }
                if ($nm === '') {
                    $out['warnings'][] = "NIK $nik tanpa nama dilewati.";

                    continue;
                }
                if ($nik === '') {
                    $noNik[] = $nm;

                    continue;
                }
                if (isset($seen[$nik])) {
                    $out['warnings'][] = "NIK $nik muncul lebih dari sekali; hanya baris pertama yang dipakai.";

                    continue;
                }
                $seen[$nik] = true;
                $wali = $this->str($row, $c['homeroom'] ?? null);
                $out['rows'][] = [
                    'nik' => $nik, 'name' => $nm,
                    'subject' => $this->str($row, $c['subject'] ?? null) ?: null,
                    'position' => $this->str($row, $c['position'] ?? null) ?: null,
                    'homeroom' => $wali !== '' && $wali !== '-' ? $this->className($wali) : null,
                ];
            }

            if ($noNik) {
                $out['warnings'][] = count($noNik).' guru tanpa NIK dilewati karena NIK dipakai untuk login: '.implode(', ', array_slice($noNik, 0, 10)).(count($noNik) > 10 ? ', ...' : '').'.';
            }

            break; // satu sheet daftar guru
        }

        if (! $out['rows'] && ! $out['errors']) {
            $out['errors'][] = 'Tidak ada data guru terbaca. Gunakan format Database Guru (kolom NIK, NAMA, MATA PELAJARAN, JABATAN, WALI KELAS).';
        }

        return $out;
    }

    /**
     * PDF jadwal aSc: daftar guru (nama + mapel yang diajar) tanpa NIK. Karena guru login dengan NIK,
     * hasilnya tidak langsung disimpan, melainkan diunduh sebagai Excel Data Guru terisi untuk dilengkapi NIK-nya.
     *
     * @return list<array{nik:string,name:string,subject:string,exists:bool}>
     */
    public function fromPdf(array $sheets): array
    {
        $existing = Teacher::query()->get(['nik', 'name'])->keyBy(fn ($t) => $this->personKey($t->name));
        $found = [];
        foreach (array_slice($sheets[TimetablePdfReader::SHEET] ?? [], 1) as $r) {
            $name = trim((string) ($r[5] ?? ''));
            $k = $this->personKey($name);
            if ($k === '') {
                continue;
            }
            $found[$k]['names'][$name] = ($found[$k]['names'][$name] ?? 0) + 1;
            $found[$k]['subjects'][$r[4]] = ($found[$k]['subjects'][$r[4]] ?? 0) + (int) $r[2];
        }

        $out = [];
        foreach ($found as $k => $f) {
            arsort($f['names']);
            arsort($f['subjects']);
            $t = $existing->get($k);
            $out[] = [
                'nik' => $t->nik ?? '', 'name' => $t->name ?? array_key_first($f['names']),
                'subject' => implode(', ', array_slice(array_keys($f['subjects']), 0, 3)), 'exists' => (bool) $t,
            ];
        }
        usort($out, fn ($a, $b) => [$a['exists'], $a['name']] <=> [$b['exists'], $b['name']]);

        return $out;
    }

    public function preview(array $sheets): array
    {
        if (TimetablePdfReader::isPdf($sheets)) {
            $list = collect($this->fromPdf($sheets));
            $new = $list->where('exists', false)->count();

            return [
                'ok' => false, 'pdf_teachers' => $list->isNotEmpty(),
                'errors' => $list->isEmpty() ? ['Tidak ada nama guru di PDF ini. Gunakan PDF jadwal kelas atau jadwal guru dari aSc Timetables.'] : [],
                'warnings' => $new ? ["PDF jadwal tidak memuat NIK, padahal guru login dengan NIK. Unduh Excel Data Guru di bawah (nama & mapel sudah terisi), isi kolom NIK untuk $new guru baru, lalu unggah Excel itu di kartu Data guru."] : [],
                'stats' => ['Guru di PDF' => $list->count(), 'Sudah terdaftar' => $list->count() - $new, 'Baru (perlu NIK)' => $new],
                'columns' => ['NIK', 'Nama', 'Mata pelajaran'],
                'sample' => $list->map(fn ($x) => [$x['nik'] ?: '(isi NIK)', $x['name'], $x['subject']])->all(),
            ];
        }

        $r = $this->rows($sheets);
        $existing = Teacher::query()->pluck('nik')->flip();
        $classes = Classroom::query()->pluck('id', 'name')->mapWithKeys(fn ($id, $n) => [$this->classKey($n) => $n]);

        $unknownClasses = collect($r['rows'])->pluck('homeroom')->filter()->reject(fn ($k) => $classes->has($this->classKey($k)))->unique()->values();
        if ($unknownClasses->isNotEmpty()) {
            $r['warnings'][] = 'Kelas wali tidak ditemukan dan diabaikan: '.$unknownClasses->implode(', ').'. Impor data siswa dulu agar kelasnya ada.';
        }

        return [
            'ok' => $r['errors'] === [],
            'errors' => $r['errors'],
            'warnings' => $r['warnings'],
            'stats' => [
                'Total guru terbaca' => count($r['rows']),
                'Guru baru (dibuatkan akun)' => collect($r['rows'])->reject(fn ($x) => $existing->has($x['nik']))->count(),
                'Guru lama diperbarui' => collect($r['rows'])->filter(fn ($x) => $existing->has($x['nik']))->count(),
                'Wali kelas ditetapkan' => collect($r['rows'])->filter(fn ($x) => $x['homeroom'] && $classes->has($this->classKey($x['homeroom'])))->count(),
            ],
            'columns' => ['NIK', 'Nama', 'Mata pelajaran', 'Jabatan', 'Wali kelas'],
            'sample' => array_map(fn ($x) => [$x['nik'], $x['name'], $x['subject'], $x['position'], $x['homeroom']], array_slice($r['rows'], 0, 15)),
        ];
    }

    /** @return array{result:array,credentials:list<array>} */
    public function apply(array $sheets, string $mode): array
    {
        $rows = $this->rows($sheets)['rows'];
        $teachers = Teacher::query()->with('user')->get()->keyBy('nik');
        $classes = Classroom::query()->get()->keyBy(fn ($c) => $this->classKey($c->name));
        $created = $updated = $homerooms = 0;
        $credentials = [];

        foreach ($rows as $x) {
            $t = $teachers->get($x['nik']);
            if ($t) {
                $t->update(['name' => $x['name'], 'subject' => $x['subject'] ?? $t->subject, 'position' => $x['position'] ?? $t->position]);
                $t->user?->update(['name' => $x['name']]);
                $updated++;
            } else {
                $pw = $this->randomPassword();
                $u = User::create(['role' => Role::Teacher, 'username' => $x['nik'], 'name' => $x['name'], 'password' => $this->hashInitial($pw), 'must_change_password' => true]);
                $t = Teacher::create(['user_id' => $u->id, 'nik' => $x['nik'], 'name' => $x['name'], 'subject' => $x['subject'], 'position' => $x['position'] ?? 'Guru']);
                $credentials[] = ['Guru', $x['nik'], $x['name'], '-', $pw];
                $created++;
            }

            if ($x['homeroom'] && ($c = $classes->get($this->classKey($x['homeroom'])))) {
                $c->update(['homeroom_teacher_id' => $t->id]);
                $homerooms++;
            }
        }

        return ['result' => ['Guru baru' => $created, 'Guru diperbarui' => $updated, 'Wali kelas ditetapkan' => $homerooms], 'credentials' => $credentials];
    }
}
