<?php

namespace App\Services\Import;

use App\Jobs\ProcessDataImport;
use App\Models\DataImport;
use App\Models\User;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantFiles;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Alur impor: unggah → pratinjau (tidak mengubah data) → konfirmasi → job antrean → selesai.
 * Berkas disimpan privat per sekolah: school_{id}/imports/{uuid}.{ext} (disk berkas tenant, lihat TenantFiles)
 */
class ImportManager
{
    public const MAX_KB = 10240;

    /** Jenis impor yang juga menerima PDF jadwal cetakan aSc Timetables. */
    public const PDF_TYPES = ['schedules', 'class_schedules', 'lesson_periods', 'teachers'];

    public function __construct(
        private TenantContext $tenant,
        private SpreadsheetReader $reader,
        private TeacherImport $teachers,
        private StudentImport $students,
        private LessonPeriodImport $periods,
        private ScheduleImport $schedules,
        private TenantFiles $files,
    ) {}

    public function upload(UploadedFile $file, string $type, User $by, string $mode = 'add'): DataImport
    {
        $school = $this->tenant->get();
        $ext = strtolower($file->getClientOriginalExtension());
        $path = $this->files->putFile($file, 'imports', Str::uuid().'.'.$ext);

        $import = DataImport::create([
            'user_id' => $by->id, 'type' => $type, 'mode' => $mode, 'file_name' => Str::limit($file->getClientOriginalName(), 200, ''),
            'file_path' => $path, 'file_size' => $file->getSize(), 'status' => 'preview',
        ]);

        return $this->refreshPreview($import);
    }

    public function refreshPreview(DataImport $import, ?string $mode = null): DataImport
    {
        if ($mode) {
            $import->mode = $mode;
        }

        try {
            $sheets = $this->sheets($import);
            $preview = match ($import->type) {
                'teachers' => $this->teachers->preview($sheets),
                'students' => $this->students->preview($sheets, $this->tenant->get(), $import->mode),
                'lesson_periods' => $this->periods->preview($sheets),
                'schedules', 'class_schedules' => $this->schedules->preview($this->scheduleSheets($sheets, $import->type)),
            };
        } catch (Throwable $e) {
            report($e);
            $msg = str_ends_with(strtolower($import->file_path), '.pdf') && $e instanceof \RuntimeException
                ? $e->getMessage()
                : 'File tidak bisa dibaca. Pastikan formatnya .xlsx atau CSV dan tidak dikunci kata sandi.';
            $preview = ['ok' => false, 'errors' => [$msg], 'warnings' => [], 'stats' => [], 'sample' => [], 'columns' => []];
        }

        $import->forceFill(['preview' => $preview])->save();

        return $import;
    }

    public function confirm(DataImport $import, string $mode): DataImport
    {
        abort_unless($import->status === 'preview', 422, 'Impor ini sudah diproses.');
        $this->refreshPreview($import, $mode);
        abort_unless($import->preview['ok'] ?? false, 422, 'Perbaiki kesalahan pada file terlebih dahulu.');

        $import->forceFill(['status' => 'queued'])->save();
        ProcessDataImport::dispatch($import->id, $import->school_id);

        return $import->refresh();
    }

    /** Dijalankan oleh job, di dalam konteks tenant. */
    public function process(DataImport $import): void
    {
        $import->forceFill(['status' => 'processing'])->save();
        $sheets = $this->sheets($import);
        $school = $this->tenant->get();

        $out = DB::transaction(fn () => match ($import->type) {
            'teachers' => $this->teachers->apply($sheets, $import->mode),
            'students' => $this->students->apply($sheets, $import->mode, $school),
            'lesson_periods' => $this->periods->apply($sheets),
            'schedules', 'class_schedules' => $this->schedules->apply($this->scheduleSheets($sheets, $import->type)),
        });

        $import->forceFill([
            'status' => 'done', 'result' => $out['result'], 'finished_at' => now(),
            'credentials' => $out['credentials'] ?: null,
        ])->save();
    }

    /** Excel Data Guru terisi dari PDF jadwal (format sama dengan template guru). @return string path file sementara */
    public function teacherSheet(DataImport $import): string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'guru').'.xlsx';
        $w = new \OpenSpout\Writer\XLSX\Writer;
        $w->openToFile($tmp);
        $w->getCurrentSheet()->setName('Daftar Guru');
        $text = (new \OpenSpout\Common\Entity\Style\Style)->setFormat('@'); // NIK 16 digit tetap teks, tidak berubah jadi 3,3E+15
        $w->addRow(\OpenSpout\Common\Entity\Row::fromValues(['NIK', 'NAMA', 'MATA PELAJARAN', 'JABATAN', 'WALI KELAS'], (new \OpenSpout\Common\Entity\Style\Style)->setFontBold()));
        foreach ($this->teachers->fromPdf($this->sheets($import)) as $x) {
            $w->addRow(\OpenSpout\Common\Entity\Row::fromValuesWithStyles([$x['nik'], $x['name'], $x['subject'], $x['exists'] ? '' : 'Guru', ''], null, [0 => $text]));
        }
        $w->close();

        return $tmp;
    }

    /**
     * Jadwal mengajar memakai halaman jadwal guru, jadwal kelas memakai halaman jadwal kelas.
     * Keduanya mengisi tabel jadwal yang sama (satu blok = kelas + jam + mapel + guru).
     */
    private function scheduleSheets(array $sheets, string $type): array
    {
        if (! TimetablePdfReader::isPdf($sheets)) {
            return $sheets;
        }
        [$key, $other] = $type === 'class_schedules'
            ? [TimetablePdfReader::SHEET_CLASS, 'PDF ini berisi jadwal guru (judul halaman "Guru ..."). Unggah di kartu Jadwal mengajar, atau gunakan PDF jadwal kelas di sini.']
            : [TimetablePdfReader::SHEET_TEACHER, 'PDF ini berisi jadwal kelas (judul halaman nama kelas). Unggah di kartu Jadwal kelas, atau gunakan PDF jadwal guru di sini.'];
        if (count($sheets[$key]) < 2) {
            throw new \RuntimeException($other);
        }

        return [TimetablePdfReader::SHEET => $sheets[$key]];
    }

    private function sheets(DataImport $import): array
    {
        return $this->reader->read($this->files->localCopy($import->file_path), pathinfo($import->file_path, PATHINFO_EXTENSION));
    }

    /** Template Excel resmi (sama dengan prototipe). */
    public static function template(string $type): ?array
    {
        return match ($type) {
            'teachers' => [resource_path('templates/Format_Database_Guru_Karyawan.xlsx'), 'Format_Database_Guru_Karyawan.xlsx'],
            'students' => [resource_path('templates/FORMAT_DATABASE_SISWA.xlsx'), 'FORMAT_DATABASE_SISWA.xlsx'],
            'lesson_periods' => [resource_path('templates/FORMAT_JAM_KBM.xlsx'), 'FORMAT_JAM_KBM.xlsx'],
            'schedules', 'class_schedules' => [resource_path('templates/FORMAT_JADWAL.xlsx'), 'FORMAT_JADWAL.xlsx'],
            default => null,
        };
    }

    /** Hapus berkas & daftar password awal impor yang lebih tua dari N hari. */
    public static function prune(int $days = 7): int
    {
        $n = 0;
        DataImport::query()->withoutGlobalScopes()->where('created_at', '<', now()->subDays($days))
            ->where(fn ($q) => $q->whereNotNull('credentials')->orWhere('file_path', '!=', ''))
            ->each(function (DataImport $i) use (&$n) {
                if ($i->file_path && str_starts_with($i->file_path, 'school_'.$i->school_id.'/')) {
                    Storage::disk(TenantFiles::diskName())->delete($i->file_path);
                }
                $i->forceFill(['credentials' => null, 'file_path' => ''])->saveQuietly();
                $n++;
            });

        return $n;
    }
}
