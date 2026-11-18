<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CbtAnswer;
use App\Models\CbtAttempt;
use App\Models\CbtExam;
use App\Models\CbtExamClass;
use App\Models\CbtExamQuestion;
use App\Models\Student;
use App\Services\Cbt\CbtService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

/**
 * CBT di HP siswa: daftar ujian → token kelas → mengerjakan (simpan per jawaban) → kirim → nilai langsung.
 * Tidak ada kunci jawaban yang dikirim ke HP.
 */
class CbtStudentController extends Controller
{
    public function __construct(private CbtService $cbt) {}

    private function student(Request $request): Student
    {
        $s = $request->user()->student ?? abort(403, 'Akun siswa belum terhubung dengan data siswa.');
        abort_unless($s->is_active && $s->classroom_id, 403, 'Akun siswa tidak aktif di kelas mana pun.');

        return $s;
    }

    private function own(Request $request, CbtAttempt $attempt): Student
    {
        $s = $this->student($request);
        abort_unless($attempt->student_id === $s->id, 403);

        return $s;
    }

    public function index(Request $request): JsonResponse
    {
        $s = $this->student($request);
        $exams = CbtExam::query()->where('status', 'terbit')->whereHas('classes', fn ($q) => $q->where('classroom_id', $s->classroom_id))
            ->withCount('questions')->orderByDesc('starts_at')->limit(60)->get();
        $attempts = CbtAttempt::query()->where('student_id', $s->id)->whereIn('cbt_exam_id', $exams->pluck('id'))->get()->keyBy('cbt_exam_id');

        return response()->json($exams->map(function (CbtExam $e) use ($attempts) {
            $a = $attempts->get($e->id);
            if ($a) {
                $this->cbt->expireIfDue($a);
                $a->refresh();
            }

            return ['id' => $e->id, 'title' => $e->title, 'kind' => $e->kind ?: 'ujian', 'kind_label' => $e->kindLabel(), 'subject' => $e->subject, 'code' => $e->code, 'phase' => $e->phase(),
                'starts_at' => $e->starts_at->toIso8601String(), 'ends_at' => $e->ends_at->toIso8601String(), 'duration' => $e->duration_minutes,
                'questions' => $e->questions_count, 'exit_action' => $e->exit_action ?: 'ulang', 'exit_allow' => (int) $e->exit_allow,
                'attempt' => $a ? ['id' => $a->id] + $this->cbt->studentResult($a) : null];
        }));
    }

    public function start(Request $request, CbtExam $exam): JsonResponse
    {
        $s = $this->student($request);
        $class = CbtExamClass::query()->where('cbt_exam_id', $exam->id)->where('classroom_id', $s->classroom_id)->first();
        abort_unless($exam->status === 'terbit' && $class, 404, 'Ujian ini bukan untuk kelasmu.');

        $existing = CbtAttempt::query()->where('cbt_exam_id', $exam->id)->where('student_id', $s->id)->first();
        if ($existing) {
            $this->cbt->expireIfDue($existing);
            abort_if($existing->fresh()->status === 'selesai', 422, 'Kamu sudah menyelesaikan ujian ini.');

            return response()->json(['attempt_id' => $existing->id, 'resumed' => true]); // lanjut tanpa token setelah terputus
        }

        $phase = $exam->phase();
        abort_if($phase === 'terbit', 422, 'Ujian belum dimulai. Tunggu jadwalnya.');
        abort_if($phase === 'selesai', 422, 'Jadwal ujian sudah berakhir.');

        $key = 'cbt-token:'.$exam->id.':'.$s->id;
        if (RateLimiter::tooManyAttempts($key, 5)) {
            abort(429, 'Terlalu banyak token salah. Coba lagi dalam '.RateLimiter::availableIn($key).' detik.');
        }
        $token = strtoupper(preg_replace('/\s+/', '', (string) $request->input('token')));
        if ($token === '' || ! hash_equals((string) $class->token, $token)) {
            RateLimiter::hit($key, 60);
            $this->cbt->log($exam, 'token_salah', null, $s->id, $request->user());

            return response()->json(['message' => 'Token salah. Tanyakan ulang ke pengawas di kelasmu.', 'left' => max(0, 5 - RateLimiter::attempts($key))], 422);
        }
        RateLimiter::clear($key);
        $a = $this->cbt->start($exam, $s, $class, $request->ip());
        $this->cbt->log($exam, 'mulai', null, $s->id, $request->user());

        return response()->json(['attempt_id' => $a->id, 'resumed' => false], 201);
    }

    public function show(Request $request, CbtAttempt $attempt): JsonResponse
    {
        $this->own($request, $attempt);
        $this->cbt->expireIfDue($attempt);
        $attempt->refresh();
        $exam = $attempt->exam;
        $out = ['id' => $attempt->id, 'exam' => ['id' => $exam->id, 'title' => $exam->title, 'subject' => $exam->subject, 'code' => $exam->code, 'max_exits' => $exam->max_exits,
            'exit_action' => $exam->exit_action ?: 'ulang', 'exit_allow' => (int) $exam->exit_allow],
            'status' => $attempt->status, 'now' => now()->toIso8601String(), 'deadline' => $attempt->deadline_at->toIso8601String(), 'exits' => $attempt->exits,
            'restarts' => (int) $attempt->restarts, 'locked' => (bool) $attempt->locked_at];
        if ($attempt->status === 'selesai') {
            return response()->json($out + ['result' => $this->cbt->studentResult($attempt)]);
        }
        if ($attempt->locked_at) {
            return response()->json($out); // terkunci: soal tidak dikirim sampai pengawas membuka
        }
        $attempt->update(['last_seen_at' => now()]);

        return response()->json($out + ['questions' => $this->cbt->studentQuestions($attempt)]);
    }

    /** Simpan satu jawaban. rev naik terus di HP; kiriman lama/ganda (rev ≤ tersimpan) diabaikan. */
    public function answer(Request $request, CbtAttempt $attempt): JsonResponse
    {
        $this->own($request, $attempt);
        $d = $request->validate(['question_id' => ['required', 'integer'], 'choice' => ['nullable', 'integer', 'min:0', 'max:9'],
            'text' => ['nullable', 'string', 'max:10000'], 'flagged' => ['boolean'], 'rev' => ['required', 'integer', 'min:1']]);
        if ($attempt->status !== 'mengerjakan') {
            return response()->json(['message' => 'Ujian sudah selesai. Jawaban tidak bisa diubah.', 'finished' => true], 409);
        }
        if ($attempt->locked_at) {
            return response()->json(['message' => 'Ujian terkunci karena keluar aplikasi. Hubungi pengawas.', 'locked' => true], 423);
        }
        if (now()->gt($attempt->deadline_at->copy()->addSeconds(30))) { // jeda 30 dtk untuk kiriman yang sedang di jalan
            $this->cbt->finish($attempt, 'waktu');

            return response()->json(['message' => 'Waktu habis. Jawaban sudah dikirim.', 'finished' => true], 409);
        }
        abort_unless(in_array((int) $d['question_id'], array_map('intval', $attempt->question_order), true), 422, 'Soal tidak ada di ujian ini.');
        $q = CbtExamQuestion::query()->findOrFail($d['question_id']);
        $choice = $q->type === 'pg' && isset($d['choice']) && $d['choice'] < count($q->options ?? []) ? (int) $d['choice'] : null;

        $a = CbtAnswer::query()->firstOrCreate(['cbt_attempt_id' => $attempt->id, 'cbt_exam_question_id' => $q->id]);
        $updated = CbtAnswer::query()->whereKey($a->id)->where('rev', '<', $d['rev'])->update([
            'choice' => $q->type === 'pg' ? $choice : null, 'text' => $q->type === 'essay' ? ($d['text'] ?? null) : null,
            'flagged' => (bool) ($d['flagged'] ?? false), 'rev' => $d['rev'], 'updated_at' => now(),
        ]);
        $attempt->update(['last_seen_at' => now()]);

        return response()->json(['saved' => (bool) $updated, 'rev' => max($d['rev'], (int) $a->rev)]);
    }

    /** Sinyal keluar aplikasi / kembali / putus koneksi untuk pengawas (bukan bukti curang). */
    public function event(Request $request, CbtAttempt $attempt): JsonResponse
    {
        $s = $this->own($request, $attempt);
        $d = $request->validate(['type' => ['required', 'in:keluar,kembali,offline,online'], 'seconds' => ['nullable', 'integer', 'min:0', 'max:86400']]);
        if ($attempt->status !== 'mengerjakan') {
            return response()->json(['exits' => $attempt->exits]);
        }
        $exam = $attempt->exam;
        $label = ['keluar' => 'Keluar aplikasi', 'kembali' => 'Kembali ke aplikasi', 'offline' => 'Koneksi terputus', 'online' => 'Tersambung lagi'][$d['type']];
        $this->cbt->log($exam, $d['type'], $label.(isset($d['seconds']) ? ' ('.$d['seconds'].' dtk)' : ''), $s->id, $request->user());
        $attempt->update(['last_seen_at' => now()]);
        $action = null;
        if ($d['type'] === 'keluar' && ! $attempt->locked_at) {
            $attempt->increment('exits');
            $attempt->refresh();
            // Sanksi setelah melewati toleransi: kunci (pengawas membuka) atau ulang dari awal (jawaban dihapus, waktu tetap berjalan).
            if ($attempt->exits > (int) $exam->exit_allow && in_array($exam->exit_action, ['kunci', 'ulang'], true)) {
                $action = $exam->exit_action;
                if ($action === 'kunci') {
                    $attempt->update(['locked_at' => now()]);
                    $this->cbt->log($exam, 'dikunci', 'Ujian dikunci otomatis: keluar aplikasi ke-'.$attempt->exits, $s->id, $request->user());
                    rescue(fn () => app(\App\Services\Notify\Notifier::class)->toRoles(['admin'], 'cbt', 'Ujian terkunci: '.$s->name, $exam->title.' · keluar aplikasi '.$attempt->exits.'×. Buka kunci di menu Pantau.', '#/cbt?t=ujian'));
                } else {
                    \App\Models\CbtAnswer::query()->where('cbt_attempt_id', $attempt->id)->delete();
                    $attempt->increment('restarts');
                    $this->cbt->log($exam, 'ulang', 'Jawaban dihapus, ulang dari soal 1: keluar aplikasi ke-'.$attempt->exits, $s->id, $request->user());
                }
            }
        }
        $attempt->refresh();

        return response()->json(['exits' => $attempt->exits, 'action' => $action, 'locked' => (bool) $attempt->locked_at, 'restarts' => (int) $attempt->restarts]);
    }

    public function finish(Request $request, CbtAttempt $attempt): JsonResponse
    {
        $this->own($request, $attempt);
        abort_if($attempt->locked_at && $attempt->status === 'mengerjakan' && now()->lt($attempt->deadline_at), 423, 'Ujian terkunci. Hubungi pengawas untuk membukanya.');
        $this->cbt->finish($attempt, 'siswa');
        $attempt->refresh();
        $this->cbt->notifyGrader($attempt);

        return response()->json(['message' => 'Jawaban terkirim.', 'result' => $this->cbt->studentResult($attempt)]);
    }
}
