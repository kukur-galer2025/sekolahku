<?php

namespace App\Services\Import;

use App\Enums\Role;
use App\Models\Classroom;
use App\Models\School;
use App\Models\Student;
use App\Models\User;

/**
 * Format "DATABASE_SISWA" (sama dengan prototipe):
 * - Satu sheet per kelas, nama sheet = nama kelas (mis. "X - FKK 1"), kolom NISN | NIS | NAMA | L/P
 * - atau satu tabel dengan kolom KELAS / ROMBEL.
 * - Opsional: TANGGAL LAHIR → disimpan sebagai data siswa. Password awal SELALU acak (tanggal lahir mudah ditebak),
 *   tercantum di daftar akun (CSV) yang bisa diunduh admin setelah impor.
 *
 * Username login = NIS (bila kosong, NISN). Mode:
 * - add: tambah siswa baru dan perbarui yang sudah ada (cocok lewat NIS).
 * - replace: seperti add, lalu siswa yang tidak ada di file DINONAKTIFKAN (riwayat presensi tetap aman).
 */
class StudentImport
{
    use ImportHelpers;

    private const REQUIRED = ['name' => '/^NAMA/'];

    private const OPTIONAL = [
        'nisn' => '/^NISN$/', 'nis' => '/^(NIS|NIPD|NO\.? ?INDUK)$/', 'gender' => '/^(L\/P|JK|GENDER|JENIS KELAMIN)$/',
        'class' => '/^(KELAS|ROMBEL)/', 'birth' => '/^(TGL|TANGGAL)\.? ?LAHIR$/',
    ];

    public function rows(array $sheets): array
    {
        $out = ['rows' => [], 'errors' => [], 'warnings' => [], 'sheets' => 0];
        $seen = [];
        $skipped = $noClass = 0;

        foreach ($sheets as $sheetName => $rows) {
            $h = $this->findHeader($rows, self::REQUIRED, self::OPTIONAL);
            if (! $h || (! isset($h[1]['nis']) && ! isset($h[1]['nisn']))) {
                continue;
            }
            [$hi, $c] = $h;
            $sheetClass = $this->className((string) $sheetName);
            if (! preg_match('/^[A-Z0-9]+-\S.*$/', $sheetClass)) {
                $sheetClass = ''; // nama sheet bukan nama kelas (mis. "Sheet1" atau "X - " kosong)
            }
            $hasRows = false;

            foreach (array_slice($rows, $hi + 1) as $row) {
                $name = $this->str($row, $c['name']);
                if ($name === '') {
                    continue;
                }
                $nis = preg_replace('/\s+/', '', $this->str($row, $c['nis'] ?? null));
                $nisn = preg_replace('/\s+/', '', $this->str($row, $c['nisn'] ?? null));
                $username = $nis !== '' ? $nis : $nisn;
                $class = isset($c['class']) && $this->str($row, $c['class']) !== '' ? $this->className($this->str($row, $c['class'])) : $sheetClass;

                if ($username === '') {
                    $skipped++;

                    continue;
                }
                if ($class === '') {
                    $noClass++;

                    continue;
                }
                if (isset($seen[$username])) {
                    $out['warnings'][] = "NIS $username muncul lebih dari sekali ($name); hanya baris pertama yang dipakai.";

                    continue;
                }
                $seen[$username] = true;
                $hasRows = true;
                $g = strtoupper(substr($this->str($row, $c['gender'] ?? null), 0, 1));

                $out['rows'][] = [
                    'nis' => $username, 'nisn' => $nisn ?: null, 'name' => $name, 'class' => $class,
                    'gender' => in_array($g, ['L', 'P'], true) ? $g : null,
                    'birth_date' => isset($c['birth']) ? $this->date($row[$c['birth']] ?? null) : null,
                ];
            }
            $out['sheets'] += $hasRows ? 1 : 0;
        }

        if ($skipped) {
            $out['warnings'][] = "$skipped baris tanpa NIS dan NISN dilewati (NIS dipakai untuk login).";
        }
        if ($noClass) {
            $out['warnings'][] = "$noClass baris tanpa kelas dilewati. Beri nama sheet sesuai kelas (mis. \"X - FKK 1\") atau tambahkan kolom KELAS.";
        }
        if (! $out['rows']) {
            $out['errors'][] = 'Tidak ada data siswa terbaca. Gunakan format DATABASE_SISWA (kolom NISN, NIS, NAMA, L/P; satu sheet per kelas).';
        }

        return $out;
    }

    public function preview(array $sheets, School $school, string $mode = 'add'): array
    {
        $r = $this->rows($sheets);
        $rows = collect($r['rows']);
        $existing = Student::query()->pluck('is_active', 'nis');
        $classes = Classroom::query()->pluck('name')->mapWithKeys(fn ($n) => [$this->classKey($n) => $n]);
        $newClasses = $rows->pluck('class')->unique()->reject(fn ($k) => $classes->has($this->classKey($k)))->values();

        $inFile = $rows->pluck('nis')->flip();
        $new = $rows->reject(fn ($x) => $existing->has($x['nis']))->count();
        $activeNow = $existing->filter()->count();
        $deactivate = $existing->filter()->keys()->reject(fn ($nis) => $inFile->has($nis))->count();
        $after = ['add' => $activeNow + $new + $rows->filter(fn ($x) => $existing->has($x['nis']) && ! $existing[$x['nis']])->count(), 'replace' => $rows->count()];

        foreach ($after as $m => $n) {
            $quota = $school->effectiveStudentQuota();
            if ($quota !== null && $n > $quota) {
                $after[$m.'_blocked'] = $school->isVerified()
                    ? "Jumlah siswa aktif akan menjadi $n, melebihi kuota paket ({$quota})."
                    : "Jumlah siswa aktif akan menjadi $n. Sebelum sekolah diverifikasi Parama Tech, maksimal {$quota} siswa.";
            }
        }
        if (! empty($after[$mode.'_blocked'])) {
            $r['errors'][] = $after[$mode.'_blocked'].' Tingkatkan paket atau kurangi data.';
        }

        if ($new) {
            $r['warnings'][] = "$new siswa baru mendapat password awal acak (bukan tanggal lahir). Daftar akun bisa diunduh setelah impor selesai.";
        }

        return [
            'ok' => $r['errors'] === [],
            'errors' => $r['errors'],
            'warnings' => $r['warnings'],
            'stats' => [
                'Total siswa terbaca' => $rows->count(),
                'Kelas di file' => $rows->pluck('class')->unique()->count(),
                'Siswa baru (dibuatkan akun)' => $new,
                'Siswa lama diperbarui' => $rows->count() - $new,
                'Kelas baru dibuat' => $newClasses->count(),
            ],
            'mode_effects' => [
                'add' => 'Siswa aktif setelah impor: '.$after['add'].'.',
                'replace' => "Siswa aktif setelah impor: {$after['replace']}. $deactivate siswa yang tidak ada di file akan dinonaktifkan (riwayat presensinya tetap tersimpan).",
            ],
            'blocked' => array_filter(['add' => $after['add_blocked'] ?? null, 'replace' => $after['replace_blocked'] ?? null]),
            'new_classes' => $newClasses->all(),
            'columns' => ['NIS', 'NISN', 'Nama', 'L/P', 'Kelas', 'Tgl lahir'],
            'sample' => $rows->take(15)->map(fn ($x) => [$x['nis'], $x['nisn'], $x['name'], $x['gender'], $x['class'], $x['birth_date']])->all(),
        ];
    }

    public function apply(array $sheets, string $mode, School $school): array
    {
        $rows = $this->rows($sheets)['rows'];
        $classes = Classroom::query()->get()->keyBy(fn ($c) => $this->classKey($c->name));
        $students = Student::query()->with('user')->get()->keyBy('nis');
        $created = $updated = $newClasses = $reactivated = $alumni = 0;
        $credentials = [];

        foreach ($rows as $x) {
            $key = $this->classKey($x['class']);
            if (! $classes->has($key)) {
                $classes[$key] = Classroom::create(['name' => $x['class']]); // tingkat diisi otomatis sesuai jenjang
                $newClasses++;
            }
            $classroomId = $classes[$key]->id;
            $s = $students->get($x['nis']);

            // Siswa yang sudah lulus/diarsipkan tidak dihidupkan lagi oleh impor ulang file lama.
            if ($s && in_array($s->status, ['lulus', 'arsip'], true)) {
                $alumni++;

                continue;
            }
            if ($s) {
                $reactivated += $s->is_active ? 0 : 1;
                $s->update(['name' => $x['name'], 'nisn' => $x['nisn'] ?? $s->nisn, 'gender' => $x['gender'] ?? $s->gender, 'classroom_id' => $classroomId,
                    'birth_date' => $x['birth_date'] ?? $s->birth_date, 'is_active' => true, 'status' => 'aktif', 'left_at' => null, 'left_reason' => null]);
                $s->user?->update(['name' => $x['name'], 'is_active' => true]);
                $updated++;

                continue;
            }

            // Selalu acak: tanggal lahir mudah ditebak teman sekelas.
            $pw = $this->randomPassword();
            $u = User::create(['role' => Role::Student, 'username' => $x['nis'], 'name' => $x['name'], 'password' => $this->hashInitial($pw), 'must_change_password' => true]);
            Student::create(['user_id' => $u->id, 'nis' => $x['nis'], 'nisn' => $x['nisn'], 'name' => $x['name'], 'gender' => $x['gender'], 'classroom_id' => $classroomId, 'birth_date' => $x['birth_date']]);
            $credentials[] = ['Siswa', $x['nis'], $x['name'], $x['class'], $pw];
            $created++;
        }

        $deactivated = 0;
        if ($mode === 'replace') {
            $keep = array_column($rows, 'nis');
            $gone = Student::query()->where('is_active', true)->whereNotIn('nis', $keep)->get();
            foreach ($gone as $s) {
                $s->update(['is_active' => false, 'status' => 'nonaktif']);
                $s->user?->update(['is_active' => false]);
            }
            $deactivated = $gone->count();
        }

        return ['result' => array_filter([
            'Siswa baru' => $created, 'Siswa diperbarui' => $updated, 'Diaktifkan kembali' => $reactivated,
            'Dinonaktifkan' => $deactivated, 'Kelas baru' => $newClasses, 'Alumni dilewati' => $alumni,
        ], fn ($v, $k) => $v > 0 || in_array($k, ['Siswa baru', 'Siswa diperbarui'], true), ARRAY_FILTER_USE_BOTH), 'credentials' => $credentials];
    }
}
