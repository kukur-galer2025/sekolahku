<?php

namespace App\Http\Controllers\Api;

use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Models\Classroom;
use App\Models\GradeBook;
use App\Models\GradeEntry;
use App\Models\Schedule;
use App\Models\Student;
use App\Services\Grades\SummativeMath;
use App\Services\LegerService;
use App\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Daftar Nilai Sumatif (portal guru).
 * Guru membuka buku nilai untuk setiap kelas + mapel yang ia ampu (dari jadwal), per semester.
 * Admin dapat melihat & mengekspor semua buku nilai (hanya baca).
 */
class GradeBookController extends Controller
{
    public function __construct(private TenantContext $tenant, private LegerService $leger) {}

    private function currentTerm(): string
    {
        return $this->leger->term($this->tenant->get());
    }

    private function validTerm(?string $t): string
    {
        return is_string($t) && preg_match('/^(Gasal|Genap) \d{4}\/\d{4}$/', $t) ? $t : $this->currentTerm();
    }

    /** Daftar kelas + mapel yang diampu beserta progres pengisian. */
    public function index(Request $request): JsonResponse
    {
        $u = $request->user();
        $term = $this->validTerm($request->query('term'));
        $terms = GradeBook::query()->when($u->role === Role::Teacher, fn ($q) => $q->where('teacher_id', $u->teacher?->id ?? 0))
            ->distinct()->pluck('term')->push($this->currentTerm())->unique()->sortDesc()->values();

        if ($u->role === Role::Teacher) {
            $tid = $u->teacher?->id ?? 0;
            $pairs = Schedule::query()->where('teacher_id', $tid)->select('classroom_id', 'subject')->distinct()->get()
                ->map(fn ($s) => ['classroom_id' => $s->classroom_id, 'subject' => trim((string) $s->subject)]);
            $books = GradeBook::query()->where('teacher_id', $tid)->where('term', $term)->get();
            $pairs = $pairs->merge($books->map(fn ($b) => ['classroom_id' => $b->classroom_id, 'subject' => $b->subject]))
                ->filter(fn ($p) => $p['subject'] !== '')->unique(fn ($p) => $p['classroom_id'].'|'.mb_strtolower($p['subject']))->values();
        } else {
            $books = GradeBook::query()->where('term', $term)->with('teacher:id,name')->get();
            $pairs = $books->map(fn ($b) => ['classroom_id' => $b->classroom_id, 'subject' => $b->subject, 'teacher' => $b->teacher?->name]);
        }

        $classes = Classroom::query()->whereIn('id', $pairs->pluck('classroom_id'))->withCount(['students' => fn ($q) => $q->where('is_active', true)])->get()->keyBy('id');
        $progress = GradeEntry::query()->whereIn('grade_book_id', $books->pluck('id'))->selectRaw('grade_book_id, count(*) n')->groupBy('grade_book_id')->pluck('n', 'grade_book_id');

        $list = $pairs->filter(fn ($p) => $classes->has($p['classroom_id']))->map(function ($p) use ($classes, $books, $progress) {
            $b = $books->first(fn ($x) => $x->classroom_id === $p['classroom_id'] && mb_strtolower($x->subject) === mb_strtolower($p['subject']));
            $c = $classes[$p['classroom_id']];

            return ['classroom' => ['id' => $c->id, 'name' => $c->name], 'subject' => $b?->subject ?? $p['subject'], 'teacher' => $p['teacher'] ?? null,
                'book_id' => $b?->id, 'students' => $c->students_count, 'filled' => (int) ($b ? ($progress[$b->id] ?? 0) : 0), 'updated_at' => $b?->updated_at?->toIso8601String()];
        })->sortBy(fn ($x) => $x['classroom']['name'].'|'.$x['subject'], SORT_NATURAL)->values();

        return response()->json(['term' => $term, 'terms' => $terms, 'books' => $list]);
    }

    /** Buka (atau buat) buku nilai untuk kelas + mapel yang diampu. */
    public function open(Request $request): JsonResponse
    {
        $teacher = $request->user()->teacher;
        abort_unless($teacher, 403, 'Hanya guru mapel yang mengisi daftar nilai.');
        $d = $request->validate(['classroom_id' => ['required', 'integer'], 'subject' => ['required', 'string', 'max:120'], 'term' => ['nullable', 'string', 'max:24']]);
        $c = Classroom::query()->findOrFail($d['classroom_id']);
        $subject = trim($d['subject']);
        $teaches = Schedule::query()->where('teacher_id', $teacher->id)->where('classroom_id', $c->id)->where('subject', $subject)->exists();
        $term = $this->validTerm($d['term'] ?? null);
        $book = GradeBook::query()->where(['teacher_id' => $teacher->id, 'classroom_id' => $c->id, 'subject' => $subject, 'term' => $term])->first();
        abort_unless($book || $teaches, 403, 'Kamu tidak mengajar mapel ini di kelas '.$c->name.'.');
        $book ??= GradeBook::create(['teacher_id' => $teacher->id, 'classroom_id' => $c->id, 'subject' => $subject, 'term' => $term,
            'phase' => $this->guessPhase($c), 'lm_count' => 5]);

        return response()->json(['id' => $book->id]);
    }

    public function show(Request $request, GradeBook $book): JsonResponse
    {
        $this->authorizeRead($request, $book);

        return response()->json($this->payload($book, $request));
    }

    /** Fase, jumlah & nama Lingkup Materi. */
    public function update(Request $request, GradeBook $book): JsonResponse
    {
        $this->authorizeWrite($request, $book);
        $d = $request->validate([
            'phase' => ['nullable', 'string', 'max:8'],
            'lm_count' => ['required', 'integer', 'min:1', 'max:'.GradeBook::MAX_LM],
            'lm_labels' => ['nullable', 'array', 'max:'.GradeBook::MAX_LM], 'lm_labels.*' => ['nullable', 'string', 'max:80'],
        ], [], ['lm_count' => 'jumlah lingkup materi']);
        $book->update(['phase' => $d['phase'] ?? null, 'lm_count' => $d['lm_count'],
            'lm_labels' => array_slice(array_map(fn ($x) => trim((string) $x), $d['lm_labels'] ?? []), 0, $d['lm_count'])]);

        return response()->json(['message' => 'Pengaturan daftar nilai disimpan.'] + $this->payload($book->fresh(), $request));
    }

    /** Simpan nilai (beberapa baris sekaligus; dipanggil otomatis dari PWA). */
    public function saveEntries(Request $request, GradeBook $book): JsonResponse
    {
        $this->authorizeWrite($request, $book);
        $score = ['nullable', 'numeric', 'min:0', 'max:100', 'decimal:0,2'];
        $d = $request->validate([
            'rows' => ['required', 'array', 'min:1', 'max:200'],
            'rows.*.student_id' => ['required', 'integer'],
            'rows.*.lm' => ['nullable', 'array', 'max:'.GradeBook::MAX_LM], 'rows.*.lm.*' => $score,
            'rows.*.sts_nontes' => $score, 'rows.*.sts_tes' => $score, 'rows.*.sas_nontes' => $score, 'rows.*.sas_tes' => $score,
        ], ['*.max' => 'Nilai maksimal 100.', '*.min' => 'Nilai minimal 0.', '*.numeric' => 'Nilai harus berupa angka.', '*.decimal' => 'Nilai maksimal 2 angka di belakang koma.']);

        $ids = Student::query()->where('classroom_id', $book->classroom_id)->pluck('id')->flip();
        foreach ($d['rows'] as $r) {
            if (! $ids->has($r['student_id'])) {
                throw ValidationException::withMessages(['rows' => 'Ada siswa yang bukan anggota kelas ini.']);
            }
        }
        $num = fn ($v) => $v === null || $v === '' ? null : round((float) $v, 2);
        DB::transaction(function () use ($d, $book, $num, $request) {
            foreach ($d['rows'] as $r) {
                $lm = array_map($num, array_slice(array_values($r['lm'] ?? []), 0, GradeBook::MAX_LM));
                GradeEntry::query()->updateOrCreate(['grade_book_id' => $book->id, 'student_id' => $r['student_id']], [
                    'lm' => $lm, 'sts_nontes' => $num($r['sts_nontes'] ?? null), 'sts_tes' => $num($r['sts_tes'] ?? null),
                    'sas_nontes' => $num($r['sas_nontes'] ?? null), 'sas_tes' => $num($r['sas_tes'] ?? null), 'updated_by' => $request->user()->id,
                ]);
            }
            $book->touch();
        });

        return response()->json(['message' => count($d['rows']).' baris nilai tersimpan.', 'saved_at' => now()->toIso8601String()]);
    }

    /** Excel sesuai format "DAFTAR NILAI SUMATIF" sekolah. */
    public function export(Request $request, GradeBook $book): BinaryFileResponse
    {
        $this->authorizeRead($request, $book);
        $p = $this->payload($book, $request);
        [$sem, $year] = explode(' ', $book->term, 2) + [1 => ''];
        $n = $book->lm_count;
        $tmp = tempnam(sys_get_temp_dir(), 'nilai').'.xlsx';

        $options = new \OpenSpout\Writer\XLSX\Options;
        // Baris (1-based) & kolom (0-based) untuk sel gabungan, seperti format sekolah.
        $colS = 3 + $n; $colSts = $colS + 1; $colSas = $colSts + 3; $colR = $colSas + 3;
        $merges = [[0, 6, 0, 8], [1, 6, 1, 8], [2, 6, 2, 8], [3, 6, $colS, 6], [$colSts, 6, $colSts + 2, 6], [$colSas, 6, $colSas + 2, 6], [$colR, 6, $colR, 8],
            [$colS, 7, $colS, 8], [$colSts, 7, $colSts, 8], [$colSts + 1, 7, $colSts + 1, 8], [$colSts + 2, 7, $colSts + 2, 8],
            [$colSas, 7, $colSas, 8], [$colSas + 1, 7, $colSas + 1, 8], [$colSas + 2, 7, $colSas + 2, 8], [0, 1, $colR, 1], [0, 2, $colR, 2]];
        if (method_exists($options, 'mergeCells')) {
            foreach ($merges as [$c1, $r1, $c2, $r2]) {
                $options->mergeCells($c1, $r1, $c2, $r2);
            }
        }
        if (method_exists($options, 'setColumnWidth')) {
            $options->setColumnWidth(5, 1);
            $options->setColumnWidth(12, 2);
            $options->setColumnWidth(32, 3);
            $options->setColumnWidth(9, ...range(4, $colR + 1));
        }
        $w = new \OpenSpout\Writer\XLSX\Writer($options);
        $w->openToFile($tmp);
        $S = fn () => new \OpenSpout\Common\Entity\Style\Style;
        $title = $S()->setFontBold()->setFontSize(13)->setCellAlignment(\OpenSpout\Common\Entity\Style\CellAlignment::CENTER);
        $head = $S()->setFontBold()->setShouldWrapText(true)->setCellAlignment(\OpenSpout\Common\Entity\Style\CellAlignment::CENTER)->setBackgroundColor('E2E8F0')
            ->setBorder(new \OpenSpout\Common\Entity\Style\Border(...array_map(fn ($side) => new \OpenSpout\Common\Entity\Style\BorderPart($side), ['top', 'right', 'bottom', 'left'])));
        if (class_exists(\OpenSpout\Common\Entity\Style\CellVerticalAlignment::class) && method_exists($head, 'setCellVerticalAlignment')) {
            $head->setCellVerticalAlignment(\OpenSpout\Common\Entity\Style\CellVerticalAlignment::CENTER);
        }
        $cell = $S()->setBorder(new \OpenSpout\Common\Entity\Style\Border(...array_map(fn ($side) => new \OpenSpout\Common\Entity\Style\BorderPart($side), ['top', 'right', 'bottom', 'left'])));
        $row = fn (array $v, $st = null) => $w->addRow(\OpenSpout\Common\Entity\Row::fromValues($v, $st));

        $row(['DAFTAR NILAI SUMATIF SEMESTER '.mb_strtoupper($sem)], $title);
        $row(['TAHUN AJARAN '.$year], $title);
        $row(['Kelas', '', ': '.$p['classroom']['name']]);
        $row(['Mata Pelajaran', '', ': '.$book->subject]);
        $row(['Fase', '', ': '.($book->phase ?: '-')]);
        $blank = fn (int $k) => array_fill(0, $k, '');
        $row(array_merge(['No', 'NIS', 'Nama Peserta Didik', 'Sumatif Lingkup Materi'], $blank($n), ['Sumatif Tengah Semester (STS)', '', '', 'Sumatif Akhir Semester (SAS)', '', '', 'Nilai Rapor (Rerata S + STS + SAS)']), $head);
        $lmHead = array_map(fn ($i) => 'LM '.($i + 1), range(0, $n - 1));
        $row(array_merge(['', '', ''], $lmHead, ['NA Sumatif (S)', 'Non Tes', 'Tes', 'NA Sumatif Tengah Semester (STS)', 'Non Tes', 'Tes', 'NA Sumatif Akhir Semester (SAS)', '']), $head);
        $row(array_merge(['', '', ''], array_map(fn ($i) => $p['lm_labels'][$i] ?? 'Sumatif '.($i + 1), range(0, $n - 1)), $blank(8)), $head);

        $v = fn ($x) => $x === null ? '' : $x;
        foreach ($p['students'] as $i => $s) {
            $e = $s['entry'];
            $row(array_merge([$i + 1, $s['nis'], $s['name']], array_map($v, array_slice(array_pad($e['lm'], $n, null), 0, $n)),
                [$v($s['calc']['na_s']), $v($e['sts_nontes']), $v($e['sts_tes']), $v($s['calc']['na_sts']), $v($e['sas_nontes']), $v($e['sas_tes']), $v($s['calc']['na_sas']), $v($s['calc']['rapor'])]), $cell);
        }
        $row([]);
        $tz = $this->tenant->get()->timezone ?: 'Asia/Jakarta';
        $row(['', '', 'Guru Mata Pelajaran: '.($book->teacher?->name ?? '')]);
        $row(['', '', 'Dicetak '.now($tz)->locale('id')->translatedFormat('j F Y H:i')]);
        $w->close();

        $name = 'Nilai_Sumatif_'.preg_replace('/[^A-Za-z0-9_-]+/', '_', $p['classroom']['name'].'_'.$book->subject.'_'.$book->term).'.xlsx';

        return response()->download($tmp, $name)->deleteFileAfterSend();
    }

    private function payload(GradeBook $book, Request $request): array
    {
        $book->loadMissing(['classroom:id,name', 'teacher:id,name']);
        $students = Student::query()->where('classroom_id', $book->classroom_id)->where('is_active', true)->orderBy('name')->get(['id', 'nis', 'name']);
        $entries = GradeEntry::query()->where('grade_book_id', $book->id)->get()->keyBy('student_id');
        $n = $book->lm_count;

        return [
            'id' => $book->id, 'term' => $book->term, 'subject' => $book->subject, 'phase' => $book->phase, 'lm_count' => $n,
            'lm_labels' => array_values(array_pad((array) ($book->lm_labels ?? []), $n, '')),
            'classroom' => $book->classroom?->only(['id', 'name']), 'teacher' => $book->teacher?->only(['id', 'name']),
            'can_edit' => ($request->user()->teacher?->id ?? -1) === $book->teacher_id,
            'updated_at' => $book->updated_at?->toIso8601String(),
            'students' => $students->map(function ($s) use ($entries, $n) {
                $e = $entries->get($s->id);
                $entry = ['lm' => array_values(array_pad(array_slice((array) ($e?->lm ?? []), 0, GradeBook::MAX_LM), GradeBook::MAX_LM, null)),
                    'sts_nontes' => $e?->sts_nontes, 'sts_tes' => $e?->sts_tes, 'sas_nontes' => $e?->sas_nontes, 'sas_tes' => $e?->sas_tes];

                return ['id' => $s->id, 'nis' => $s->nis, 'name' => $s->name, 'entry' => $entry, 'calc' => SummativeMath::compute($entry, $n)];
            })->values()->all(),
        ];
    }

    private function authorizeRead(Request $request, GradeBook $book): void
    {
        $u = $request->user();
        abort_unless($u->role === Role::Admin || ($u->teacher?->id ?? -1) === $book->teacher_id, 403, 'Daftar nilai ini milik guru lain.');
    }

    private function authorizeWrite(Request $request, GradeBook $book): void
    {
        abort_unless(($request->user()->teacher?->id ?? -1) === $book->teacher_id, 403, 'Hanya guru pengampu yang mengisi daftar nilai ini.');
    }

    /** Fase Kurikulum Merdeka dari tingkat kelas: 1–2 A, 3–4 B, 5–6 C, 7–9 D, X E, XI–XII F. */
    private function guessPhase(Classroom $c): ?string
    {
        return GradeBook::guessPhase($c, $this->tenant->get());
    }
}
