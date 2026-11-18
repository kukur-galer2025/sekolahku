<?php

namespace App\Http\Controllers\Api;

use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Models\CbtBank;
use App\Models\CbtQuestion;
use App\Models\Classroom;
use App\Models\Student;
use App\Models\Teacher;
use App\Models\User;
use App\Services\Cbt\CbtService;
use App\Services\CbtImporter;
use App\Services\Notify\Notifier;
use App\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * CBT bagian soal: guru membuat paket soal (PG/essay + gambar) dan mengirim ke admin;
 * admin menerima/mengembalikan; paket diterima menjadi bank soal pusat.
 */
class CbtPackageController extends Controller
{
    public function __construct(private CbtService $cbt) {}

    private function teacher(Request $request): Teacher
    {
        return $request->user()->teacher ?? abort(403, 'Akun guru belum terhubung dengan data guru.');
    }

    /** Guru hanya boleh membuka paketnya sendiri; admin boleh membuka semua yang sudah dikirim. */
    private function authorizeView(Request $request, CbtBank $bank): void
    {
        $u = $request->user();
        if ($u->role === Role::Admin) {
            abort_if($bank->status === 'draf' || $bank->kind === 'uh', 403, 'Paket masih draf guru.');

            return;
        }
        abort_unless($bank->teacher_id && $bank->teacher_id === ($u->teacher?->id ?? -1), 403, 'Paket ini milik guru lain.');
    }

    public function meta(Request $request, TenantContext $tenant): JsonResponse
    {
        $u = $request->user();
        $school = $tenant->get();
        $out = ['grades' => $school->grades(), 'tz' => $school->timezone ?: 'Asia/Jakarta'];
        if ($u->role === Role::Teacher) {
            $t = $this->teacher($request);

            // Kelas yang diajar (Jadwal KBM) per mapel — untuk peserta Ulangan Harian.
            $rows = \App\Models\Schedule::query()->where('teacher_id', $t->id)->whereNotNull('classroom_id')->get(['classroom_id', 'subject']);
            $counts = Student::query()->where('is_active', true)->whereIn('classroom_id', $rows->pluck('classroom_id'))->groupBy('classroom_id')->selectRaw('classroom_id, count(*) n')->pluck('n', 'classroom_id');
            $classes = Classroom::query()->whereIn('id', $rows->pluck('classroom_id'))->orderBy('grade')->orderBy('name')->get(['id', 'name', 'grade'])
                ->map(fn ($c) => ['id' => $c->id, 'name' => $c->name, 'grade' => $c->grade, 'students' => (int) ($counts[$c->id] ?? 0),
                    'subjects' => $rows->where('classroom_id', $c->id)->pluck('subject')->map(fn ($s) => trim($s))->unique()->values()]);

            return response()->json($out + ['subjects' => $this->cbt->teacherSubjects($t), 'teacher' => ['id' => $t->id, 'name' => $t->name],
                'classrooms' => $classes, 'lm_max' => \App\Models\GradeBook::MAX_LM]);
        }
        $counts = Student::query()->where('is_active', true)->whereNotNull('classroom_id')->groupBy('classroom_id')->selectRaw('classroom_id, count(*) n')->pluck('n', 'classroom_id');

        return response()->json($out + [
            'subjects' => CbtBank::query()->where('status', 'diterima')->distinct()->orderBy('subject')->pluck('subject')->filter()->values(),
            'classrooms' => Classroom::query()->orderBy('grade')->orderBy('name')->get(['id', 'name', 'grade'])
                ->map(fn ($c) => ['id' => $c->id, 'name' => $c->name, 'grade' => $c->grade, 'students' => (int) ($counts[$c->id] ?? 0)]),
            'teachers' => Teacher::query()->orderBy('name')->get(['id', 'name', 'subject']),
            'pengampu' => $this->cbt->pengampu(),
            'inbox' => CbtBank::query()->where('status', 'dikirim')->count(),
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        $u = $request->user();
        $q = CbtBank::query()->with('teacher:id,name')->withCount([
            'questions as pg_count' => fn ($x) => $x->where('type', 'pg'),
            'questions as essay_count' => fn ($x) => $x->where('type', 'essay'),
            'questions as image_count' => fn ($x) => $x->whereNotNull('image_path'),
        ])->withSum('questions as total_score', 'score');
        if ($u->role === Role::Teacher) {
            // Tab "Paket soal saya" = paket PTS/PAS; paket Ulangan Harian dikelola dari layar ulangan.
            $q->where('teacher_id', $this->teacher($request)->id)->where('kind', $request->query('kind') === 'uh' ? 'uh' : 'pts');
        } else {
            $q->where('kind', '!=', 'uh');
            $q->where('status', '!=', 'draf');
            if ($s = $request->string('status')->toString()) {
                $q->where('status', $s);
            }
        }

        return response()->json($q->orderByRaw("status = 'dikirim' desc")->latest('updated_at')->limit(300)->get()->map(fn (CbtBank $b) => [
            'id' => $b->id, 'kind' => $b->kind ?: 'pts', 'name' => $b->name, 'subject' => $b->subject, 'grade' => $b->grade, 'status' => $b->status, 'note' => $b->review_note,
            'teacher' => $b->teacher?->name ?? $b->creator_name, 'questions' => $b->question_count, 'pg' => (int) $b->pg_count, 'essay' => (int) $b->essay_count,
            'images' => (int) $b->image_count, 'total_score' => (float) $b->total_score, 'submitted_at' => $b->submitted_at?->toIso8601String(),
            'updated_at' => $b->updated_at?->toIso8601String(),
        ]));
    }

    public function store(Request $request): JsonResponse
    {
        $t = $this->teacher($request);
        $d = $request->validate(['name' => ['required', 'string', 'max:120'], 'subject' => ['required', 'string', 'max:80'], 'grade' => ['nullable', 'string', 'max:8'],
            'kind' => ['nullable', 'in:pts,uh']],
            [], ['name' => 'judul paket', 'subject' => 'mata pelajaran']);
        $subjects = $this->cbt->teacherSubjects($t);
        abort_if($subjects && ! in_array($d['subject'], $subjects, true), 422, 'Pilih mata pelajaran yang Anda ajar.');
        $d['kind'] ??= 'pts';
        $bank = CbtBank::create($d + ['teacher_id' => $t->id, 'status' => 'draf', 'question_count' => 0, 'created_by' => $request->user()->id, 'creator_name' => $t->name]);

        return response()->json(['id' => $bank->id, 'message' => 'Paket soal dibuat.'], 201);
    }

    public function show(Request $request, CbtBank $bank): JsonResponse
    {
        $this->authorizeView($request, $bank);

        return response()->json([
            'id' => $bank->id, 'kind' => $bank->kind ?: 'pts', 'name' => $bank->name, 'subject' => $bank->subject, 'grade' => $bank->grade, 'status' => $bank->status,
            'note' => $bank->review_note, 'teacher' => $bank->teacher?->name ?? $bank->creator_name,
            'editable' => $request->user()->role === Role::Teacher && $bank->editable(),
            'questions' => $bank->questions()->get()->map(fn ($q) => $this->cbt->presentQuestion($q)),
        ]);
    }

    /** Simpan otomatis seluruh isi paket (guru, hanya saat draf/dikembalikan). */
    public function update(Request $request, CbtBank $bank): JsonResponse
    {
        $this->authorizeView($request, $bank);
        abort_unless($request->user()->role === Role::Teacher && $bank->editable(), 422, 'Paket yang sudah dikirim tidak bisa diubah.');
        $d = $request->validate(['name' => ['sometimes', 'string', 'max:120'], 'grade' => ['nullable', 'string', 'max:8'], 'questions' => ['present', 'array']]);
        if (isset($d['name'])) {
            $bank->name = $d['name'];
        }
        if (array_key_exists('grade', $d)) {
            $bank->grade = $d['grade'];
        }
        $bank->save();
        $this->cbt->savePackage($bank, $d['questions']);

        return response()->json(['message' => 'Tersimpan.', 'problems' => $this->cbt->problems($bank),
            'ids' => $bank->questions()->pluck('id')]);
    }

    public function submit(Request $request, CbtBank $bank, Notifier $notifier): JsonResponse
    {
        $this->authorizeView($request, $bank);
        abort_unless($request->user()->role === Role::Teacher && $bank->editable(), 422, 'Paket ini sudah dikirim.');
        abort_if($bank->kind === 'uh', 422, 'Soal Ulangan Harian tidak perlu dikirim ke admin.');
        abort_if($bank->question_count < 1, 422, 'Paket belum berisi soal.');
        if ($p = $this->cbt->problems($bank)) {
            return response()->json(['message' => 'Masih ada soal yang belum lengkap.', 'problems' => $p], 422);
        }
        $bank->update(['status' => 'dikirim', 'submitted_at' => now()]);
        $admins = User::query()->where('role', Role::Admin)->get();
        $notifier->send($admins, 'cbt', 'Paket soal baru masuk', $bank->name.' · '.$bank->teacher?->name, '#/cbt?t=masuk');

        return response()->json(['message' => 'Paket dikirim ke admin.']);
    }

    public function review(Request $request, CbtBank $bank, Notifier $notifier): JsonResponse
    {
        abort_unless($bank->status === 'dikirim', 422, 'Paket ini tidak sedang menunggu diperiksa.');
        $d = $request->validate(['action' => ['required', 'in:terima,kembalikan'], 'note' => ['nullable', 'string', 'max:1000', 'required_if:action,kembalikan']],
            ['note.required_if' => 'Tulis catatan agar guru tahu apa yang harus diperbaiki.']);
        $ok = $d['action'] === 'terima';
        $bank->update(['status' => $ok ? 'diterima' : 'dikembalikan', 'review_note' => $ok ? null : $d['note'],
            'reviewed_by' => $request->user()->id, 'reviewed_at' => now()]);
        if ($user = $bank->teacher?->user) {
            $notifier->send([$user], 'cbt', $ok ? 'Paket soal diterima' : 'Paket soal dikembalikan',
                $bank->name.($ok ? ' masuk bank soal sekolah.' : ': '.$d['note']), '#/cbt');
        }

        return response()->json(['message' => $ok ? 'Paket masuk bank soal '.$bank->subject.'.' : 'Paket dikembalikan ke guru.']);
    }

    public function import(Request $request, CbtBank $bank, CbtImporter $importer): JsonResponse
    {
        $this->authorizeView($request, $bank);
        abort_unless($request->user()->role === Role::Teacher && $bank->editable(), 422, 'Paket yang sudah dikirim tidak bisa diubah.');
        $d = $request->validate(['file' => ['required', 'file', 'max:10240']], [], ['file' => 'file soal']);
        $ext = strtolower($d['file']->getClientOriginalExtension());
        abort_unless(in_array($ext, ['xlsx', 'csv'], true), 422, 'Pilih file .xlsx atau .csv.');
        $r = $importer->parse($d['file']->getRealPath(), $ext);
        DB::transaction(function () use ($bank, $r) {
            $n = (int) $bank->questions()->max('number');
            foreach ($r['questions'] as $q) {
                $bank->questions()->create(['number' => ++$n, 'type' => $q['type'], 'body' => $q['body'], 'answer' => $q['answer'], 'score' => $q['score'],
                    'essay_guide' => $q['guide'],
                    'options' => $q['type'] === 'pg' ? array_map(fn ($o) => ['text' => (string) $o, 'image' => null], $q['options']) : []]);
            }
            $bank->update(['question_count' => $bank->questions()->count()]);
        });

        return response()->json(['message' => count($r['questions']).' soal ditambahkan dari Excel.', 'warnings' => $r['warnings']]);
    }

    public function destroy(Request $request, CbtBank $bank): JsonResponse
    {
        $u = $request->user();
        if ($u->role === Role::Teacher) {
            abort_unless($bank->teacher_id === ($u->teacher?->id ?? -1) && $bank->editable(), 422, 'Hanya paket draf/dikembalikan milik Anda yang bisa dihapus.');
        }
        $bank->delete(); // soal yang sudah dipakai ujian tetap aman (ujian memakai salinan beku)

        return response()->json(['message' => 'Paket soal dihapus.']);
    }

    public function image(Request $request): JsonResponse
    {
        $request->validate(['file' => ['required', 'file', 'max:'.(CbtService::IMAGE_MAX / 1024)]], ['file.max' => 'Gambar maksimal 2 MB.'], ['file' => 'gambar']);
        $path = $this->cbt->storeImage($request->file('file'));

        return response()->json(['path' => $path, 'url' => $this->cbt->url($path)], 201);
    }

    /** Bank soal pusat (admin): semua soal dari paket diterima, bisa disaring. Guru: soal dari paketnya sendiri. */
    public function bank(Request $request): JsonResponse
    {
        // Guru (Ulangan Harian): hanya soal dari paket miliknya sendiri, semua status.
        $own = $request->user()->role === Role::Teacher ? $this->teacher($request)->id : null;
        $q = CbtQuestion::query()->with('bank.teacher:id,name')->whereHas('bank', function ($b) use ($request, $own) {
            $own ? $b->where('teacher_id', $own) : $b->where('status', 'diterima');
            if ($s = $request->string('subject')->toString()) {
                $b->where('subject', $s);
            }
            if (! $own && ($t = $request->integer('teacher_id'))) {
                $b->where('teacher_id', $t);
            }
            if ($g = $request->string('grade')->toString()) {
                $b->where('grade', $g);
            }
        });
        if (in_array($type = $request->string('type')->toString(), ['pg', 'essay'], true)) {
            $q->where('type', $type);
        }
        $rows = $q->orderBy('cbt_bank_id')->orderBy('number')->limit(1000)->get();
        $summary = $own ? collect() : CbtBank::query()->where('status', 'diterima')->get(['id', 'subject', 'grade'])->groupBy(fn ($b) => $b->subject.'|'.$b->grade)
            ->map(function ($banks, $k) {
                [$s, $g] = explode('|', $k);
                $qs = CbtQuestion::query()->whereIn('cbt_bank_id', $banks->pluck('id'));

                return ['subject' => $s, 'grade' => $g ?: null, 'packages' => $banks->count(), 'pg' => (clone $qs)->where('type', 'pg')->count(), 'essay' => (clone $qs)->where('type', 'essay')->count()];
            })->values();

        return response()->json(['summary' => $summary, 'questions' => $rows->map(fn ($x) => $this->cbt->presentQuestion($x) + [
            'package' => $x->bank->name, 'package_id' => $x->cbt_bank_id, 'subject' => $x->bank->subject, 'grade' => $x->bank->grade, 'teacher' => $x->bank->teacher?->name ?? $x->bank->creator_name,
        ])]);
    }

    public function format(CbtImporter $importer): BinaryFileResponse
    {
        return response()->download($importer->template(), 'FORMAT_CBT.xlsx')->deleteFileAfterSend();
    }
}
