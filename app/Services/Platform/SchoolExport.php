<?php

namespace App\Services\Platform;

use App\Models\Attendance;
use App\Models\AuditLog;
use App\Models\Classroom;
use App\Models\DataExport;
use App\Models\LessonPeriod;
use App\Models\Schedule;
use App\Models\Student;
use App\Models\Teacher;
use App\Tenancy\TenantFiles;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Writer;

/**
 * Ekspor seluruh data sekolah ke satu berkas Excel (hak sekolah atas datanya).
 * Dijalankan di dalam konteks tenant oleh job ExportSchoolData.
 */
class SchoolExport
{
    public const DAYS = [1 => 'Senin', 2 => 'Selasa', 3 => 'Rabu', 4 => 'Kamis', 5 => 'Jumat', 6 => 'Sabtu', 7 => 'Minggu'];

    public function __construct(private TenantFiles $files) {}

    public function run(DataExport $export): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'ex').'.xlsx';
        $w = new Writer;
        $w->openToFile($tmp);
        $bold = (new Style)->setFontBold();
        $first = true;
        $counts = [];

        $sheet = function (string $name, array $head, iterable $rows) use ($w, $bold, &$first, &$counts) {
            $s = $first ? $w->getCurrentSheet() : $w->addNewSheetAndMakeItCurrent();
            $first = false;
            $s->setName($name);
            $w->addRow(Row::fromValues($head, $bold));
            $n = 0;
            foreach ($rows as $r) {
                $w->addRow(Row::fromValues(array_map(fn ($v) => $v ?? '', $r)));
                $n++;
            }
            $counts[$name] = $n;
        };

        $classes = Classroom::query()->pluck('name', 'id');
        $teachers = Teacher::query()->pluck('name', 'id');

        $sheet('Siswa', ['NIS', 'NISN', 'Nama', 'L/P', 'Kelas', 'Tanggal lahir', 'Status', 'Persetujuan ortu', 'Cara persetujuan', 'Bukti'],
            Student::query()->orderBy('classroom_id')->orderBy('name')->lazy()->map(fn ($s) => [
                $s->nis, $s->nisn, $s->name, $s->gender, $classes[$s->classroom_id] ?? '', $s->birth_date?->toDateString(),
                $s->is_active ? 'Aktif' : 'Nonaktif', $s->parent_consent_at?->toDateString(), $s->parent_consent_method, $s->parent_consent_ref,
            ]));
        $sheet('Guru', ['NIK', 'Nama', 'Mata pelajaran', 'Jabatan'],
            Teacher::query()->orderBy('name')->lazy()->map(fn ($t) => [$t->nik, $t->name, $t->subject, $t->position]));
        $sheet('Kelas', ['Kelas', 'Tingkat', 'Wali kelas'],
            Classroom::query()->orderBy('name')->get()->map(fn ($c) => [$c->name, $c->grade, $teachers[$c->homeroom_teacher_id] ?? '']));
        $sheet('Jam KBM', ['Urutan', 'Sesi', 'Mulai', 'Selesai', 'Istirahat'],
            LessonPeriod::query()->orderBy('sort')->get()->map(fn ($p) => [$p->sort, $p->label, substr($p->starts_at, 0, 5), substr($p->ends_at, 0, 5), $p->is_break ? 'Ya' : '']));
        $sheet('Jadwal', ['Hari', 'Jam ke', 'Jumlah jam', 'Kelas', 'Mata pelajaran', 'Guru'],
            Schedule::query()->orderBy('day')->orderBy('classroom_id')->orderBy('period_start')->lazy()->map(fn ($b) => [
                self::DAYS[$b->day] ?? $b->day, $b->period_start, $b->period_count, $classes[$b->classroom_id] ?? '', $b->subject, $teachers[$b->teacher_id] ?? '',
            ]));

        $students = Student::query()->pluck('name', 'id');
        $nis = Student::query()->pluck('nis', 'id');
        $tz = app(\App\Tenancy\TenantContext::class)->get()->timezone;
        $sheet('Presensi', ['Tanggal', 'NIS', 'Nama', 'Kelas', 'Status', 'Cara', 'Jam scan', 'Terlambat', 'Catatan'],
            Attendance::query()->orderBy('date')->orderBy('student_id')->lazy()->map(fn ($a) => [
                $a->date, $nis[$a->student_id] ?? '', $students[$a->student_id] ?? '', $classes[$a->classroom_id] ?? '', $a->status->label(),
                ['qr' => 'Scan QR', 'manual' => 'Koreksi', 'auto' => 'Otomatis'][$a->method->value] ?? $a->method->value,
                $a->scanned_at?->setTimezone($tz)->format('H:i'), $a->is_late ? 'Ya' : '', $a->note,
            ]));
        $sheet('Jurnal', ['Tanggal', 'Jam ke', 'Kelas', 'Mata pelajaran', 'Guru', 'Materi', 'Hadir', 'Sakit', 'Izin', 'Alpa'],
            \App\Models\Journal::query()->orderBy('date')->lazy()->map(fn ($j) => [
                $j->date->format('Y-m-d'), $j->period_start.'–'.$j->period_end, $classes[$j->classroom_id] ?? '', $j->subject, $teachers[$j->teacher_id] ?? '', $j->topic, $j->present, $j->sick, $j->permit, $j->absent,
            ]));
        $sheet('Tugas', ['Dibuat', 'Tenggat', 'Kelas', 'Mata pelajaran', 'Guru', 'Judul', 'Keterangan'],
            \App\Models\Assignment::query()->orderBy('created_at')->lazy()->map(fn ($a) => [
                $a->created_at?->setTimezone($tz)->format('Y-m-d'), $a->due_date->format('Y-m-d'), $classes[$a->classroom_id] ?? '', $a->subject, $teachers[$a->teacher_id] ?? '', $a->title, $a->description,
            ]));
        $sheet('Agenda & Informasi', ['Jenis', 'Judul', 'Isi', 'Diperbarui'],
            \App\Models\AgendaPage::query()->get()->map(fn ($g) => ['Agenda '.$g->kind, $g->title, $g->body, $g->updated_at?->setTimezone($tz)->format('Y-m-d H:i')])
                ->concat(\App\Models\Announcement::query()->orderBy('created_at')->get()->map(fn ($a) => ['Informasi ('.$a->audience.')', $a->title, $a->body, $a->created_at?->setTimezone($tz)->format('Y-m-d H:i')])));
        $sheet('Log akses', ['Waktu', 'Oleh', 'Tindakan', 'Keterangan', 'IP'],
            AuditLog::query()->orderBy('created_at')->lazy()->map(fn ($l) => [$l->created_at?->setTimezone($tz)->format('Y-m-d H:i'), $l->actor_name, $l->action, $l->description, $l->ip]));
        $w->close();

        $path = $this->files->put('exports/'.Str::uuid().'.xlsx', (string) file_get_contents($tmp));
        $export->forceFill(['status' => 'done', 'file_path' => $path, 'file_size' => filesize($tmp), 'summary' => $counts, 'expires_at' => now()->addDays(7)])->save();
        @unlink($tmp);
    }

    public static function prune(): int
    {
        $n = 0;
        DataExport::query()->withoutGlobalScopes()->whereNotNull('file_path')->where('expires_at', '<', now())->each(function (DataExport $e) use (&$n) {
            if (str_starts_with((string) $e->file_path, 'school_'.$e->school_id.'/')) {
                Storage::disk(TenantFiles::diskName())->delete($e->file_path);
            }
            $e->forceFill(['file_path' => null])->saveQuietly();
            $n++;
        });

        return $n;
    }
}
