<?php

namespace App\Http\Controllers\Api;

use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Models\ClassCashEntry;
use App\Models\ClassCashSetting;
use App\Models\Classroom;
use App\Models\Student;
use App\Services\Notify\Notifier;
use App\Services\StoredUpload;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantFiles;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Kas Kelas.
 *  - Bendahara kelas (siswa berjabatan bendahara_1 / bendahara_2, dipilih wali kelas di Struktur Kelas) mencatat
 *    pemasukan & pengeluaran, mengatur iuran wajib, dan menghapus catatan yang salah (≤ 30 hari).
 *  - Siswa sekelas melihat saldo, riwayat, dan status iuran (transparan).
 *  - Wali kelas & admin hanya memantau (lihat + ekspor Excel).
 */
class ClassCashController extends Controller
{
    public const TREASURERS = ['bendahara_1', 'bendahara_2'];

    public function __construct(private TenantContext $tenant, private TenantFiles $files) {}

    public function show(Request $request): JsonResponse
    {
        [$c, $me] = $this->classroom($request);

        return response()->json($this->payload($c, $me));
    }

    public function store(Request $request, StoredUpload $upload, Notifier $notifier): JsonResponse
    {
        [$c, $me] = $this->classroom($request);
        $this->assertTreasurer($me);
        $type = $request->input('type');
        $d = $request->validate([
            'type' => ['required', Rule::in(['masuk', 'keluar'])],
            'category' => ['required', Rule::in(array_keys($type === 'keluar' ? ClassCashEntry::OUT : ClassCashEntry::IN))],
            'amount' => ['required', 'integer', 'min:100', 'max:100000000'],
            'date' => ['required', 'date_format:Y-m-d', 'before_or_equal:'.$this->today()],
            'description' => [$type === 'keluar' ? 'required' : 'nullable', 'string', 'max:190'],
            'student_ids' => ['nullable', 'array', 'max:60'], 'student_ids.*' => ['integer'],
            'proof' => ['nullable', 'file', 'max:5120'],
        ], ['description.required' => 'Tulis keperluan pengeluaran.', 'amount.min' => 'Nominal minimal Rp100.'],
            ['amount' => 'nominal', 'date' => 'tanggal', 'category' => 'kategori']);

        $ids = $d['type'] === 'masuk' && $d['category'] === 'iuran' ? array_values(array_unique($d['student_ids'] ?? [])) : [];
        if ($d['type'] === 'masuk' && $d['category'] === 'iuran' && ! $ids) {
            throw \Illuminate\Validation\ValidationException::withMessages(['student_ids' => 'Pilih siswa yang membayar iuran.']);
        }
        $members = Student::query()->where('classroom_id', $c->id)->whereIn('id', $ids)->get(['id', 'name', 'user_id']);
        if (count($ids) !== $members->count()) {
            throw \Illuminate\Validation\ValidationException::withMessages(['student_ids' => 'Ada siswa yang bukan anggota kelas ini.']);
        }
        if ($d['type'] === 'keluar' && $d['amount'] > $this->balance($c)) {
            throw \Illuminate\Validation\ValidationException::withMessages(['amount' => 'Saldo kas tidak cukup untuk pengeluaran ini.']);
        }

        $proof = ! empty($d['proof']) ? $upload->store($d['proof'], 'kas/'.$c->id, 2 * 1024 * 1024, 1600)['path'] : null;
        $base = ['classroom_id' => $c->id, 'type' => $d['type'], 'category' => $d['category'], 'amount' => $d['amount'], 'date' => $d['date'],
            'description' => $d['description'] ?? null, 'proof_path' => $proof, 'recorded_by' => $request->user()->id, 'recorded_name' => $me->name];
        if ($ids) {
            foreach ($members as $m) {
                ClassCashEntry::create($base + ['student_id' => $m->id]); // iuran: satu baris per siswa (nominal per siswa)
            }
            rescue(fn () => $notifier->send(\App\Models\User::query()->whereIn('id', $members->pluck('user_id')->filter())->get(), 'class_cash',
                'Iuran kas tercatat', 'Rp'.number_format($d['amount'], 0, ',', '.').' · dicatat bendahara '.$me->name, '#/kas'));
            $msg = 'Iuran '.$members->count().' siswa tercatat.';
        } else {
            ClassCashEntry::create($base);
            $msg = $d['type'] === 'masuk' ? 'Pemasukan tercatat.' : 'Pengeluaran tercatat.';
            // Wali kelas memantau: diberi tahu setiap pengeluaran kas.
            if ($d['type'] === 'keluar' && ($wali = $c->homeroomTeacher?->user)) {
                rescue(fn () => $notifier->send([$wali], 'class_cash', 'Pengeluaran kas '.$c->name.': Rp'.number_format($d['amount'], 0, ',', '.'),
                    ($d['description'] ?? '').' · dicatat '.$me->name, '#/wali'));
            }
        }

        return response()->json(['message' => $msg] + $this->payload($c, $me), 201);
    }

    public function destroy(Request $request, ClassCashEntry $entry): JsonResponse
    {
        [$c, $me] = $this->classroom($request);
        $this->assertTreasurer($me);
        abort_unless($entry->classroom_id === $c->id, 404);
        abort_if($entry->created_at->lt(now()->subDays(30)), 422, 'Catatan lebih dari 30 hari tidak bisa dihapus. Catat koreksi sebagai transaksi baru.');
        if ($entry->type === 'masuk' && $this->balance($c) - $entry->amount < 0) {
            abort(422, 'Menghapus pemasukan ini membuat saldo minus. Hapus pengeluaran terkait dulu.');
        }
        if ($entry->proof_path && ! ClassCashEntry::query()->where('proof_path', $entry->proof_path)->whereKeyNot($entry->id)->exists()) {
            $this->files->delete($entry->proof_path);
        }
        $entry->delete();

        return response()->json(['message' => 'Catatan dihapus.'] + $this->payload($c, $me));
    }

    /** Iuran wajib: nominal per siswa per minggu/bulan (0 = tidak ada iuran wajib). */
    public function settings(Request $request): JsonResponse
    {
        [$c, $me] = $this->classroom($request);
        $this->assertTreasurer($me);
        $d = $request->validate(['dues_amount' => ['required', 'integer', 'min:0', 'max:10000000'], 'dues_period' => ['required', Rule::in(['minggu', 'bulan'])]]);
        ClassCashSetting::query()->updateOrCreate(['classroom_id' => $c->id], $d);

        return response()->json(['message' => 'Iuran kas disimpan.'] + $this->payload($c, $me));
    }

    public function export(Request $request): BinaryFileResponse
    {
        [$c] = $this->classroom($request);
        $tz = $this->tz();
        $rows = ClassCashEntry::query()->where('classroom_id', $c->id)->with('student:id,name')->orderBy('date')->orderBy('id')->get();
        $tmp = tempnam(sys_get_temp_dir(), 'kas').'.xlsx';
        $w = new \OpenSpout\Writer\XLSX\Writer;
        $w->openToFile($tmp);
        $bold = (new \OpenSpout\Common\Entity\Style\Style)->setFontBold();
        $row = fn (array $v, $s = null) => $w->addRow(\OpenSpout\Common\Entity\Row::fromValues($v, $s));
        $row(['KAS KELAS '.mb_strtoupper($c->name)], $bold);
        $row(['Dicetak '.now($tz)->locale('id')->translatedFormat('j F Y H:i')]);
        $row([]);
        $row(['Tanggal', 'Jenis', 'Kategori', 'Keterangan', 'Siswa', 'Masuk', 'Keluar', 'Saldo', 'Dicatat oleh'], $bold);
        $saldo = 0;
        foreach ($rows as $e) {
            $saldo += $e->type === 'masuk' ? $e->amount : -$e->amount;
            $row([$e->date->format('d-m-Y'), $e->type === 'masuk' ? 'Masuk' : 'Keluar', $this->catLabel($e), (string) $e->description, $e->student?->name ?? '',
                $e->type === 'masuk' ? $e->amount : '', $e->type === 'keluar' ? $e->amount : '', $saldo, $e->recorded_name]);
        }
        $row([]);
        $row(['', '', '', '', 'Saldo akhir', '', '', $saldo], $bold);
        $w->close();

        return response()->download($tmp, 'Kas_Kelas_'.preg_replace('/[^A-Za-z0-9_-]+/', '_', $c->name).'_'.now($tz)->format('Ymd').'.xlsx')->deleteFileAfterSend();
    }

    // ── bantuan ──

    /** Siswa: kelasnya sendiri. Wali kelas: kelas yang diwalikan (?classroom_id). Admin: kelas mana pun. */
    private function classroom(Request $request): array
    {
        $u = $request->user();
        if ($u->role === Role::Student) {
            $me = $u->student;
            abort_unless($me && $me->classroom_id, 404, 'Kamu belum masuk kelas.');

            return [Classroom::query()->findOrFail($me->classroom_id), $me];
        }
        $c = Classroom::query()->findOrFail((int) $request->input('classroom_id'));
        abort_unless($u->role === Role::Admin || $c->homeroom_teacher_id === ($u->teacher?->id ?? -1), 403, 'Hanya wali kelas ini yang bisa memantau kas kelasnya.');

        return [$c, null];
    }

    private function assertTreasurer(?Student $me): void
    {
        abort_unless($me && in_array($me->class_role, self::TREASURERS, true), 403, 'Hanya bendahara kelas yang mencatat kas. Bendahara dipilih wali kelas di Struktur Kelas.');
    }

    private function balance(Classroom $c): int
    {
        $q = ClassCashEntry::query()->where('classroom_id', $c->id);

        return (int) (clone $q)->where('type', 'masuk')->sum('amount') - (int) (clone $q)->where('type', 'keluar')->sum('amount');
    }

    private function tz(): string
    {
        return $this->tenant->get()->timezone ?: 'Asia/Jakarta';
    }

    private function today(): string
    {
        return now($this->tz())->toDateString();
    }

    /** Periode iuran berjalan: minggu ini (Senin–Minggu) atau bulan ini. */
    private function period(string $p): array
    {
        $now = now($this->tz());
        [$a, $b] = $p === 'bulan' ? [$now->copy()->startOfMonth(), $now->copy()->endOfMonth()] : [$now->copy()->startOfWeek(Carbon::MONDAY), $now->copy()->endOfWeek(Carbon::SUNDAY)];
        $label = $p === 'bulan' ? $a->locale('id')->translatedFormat('F Y') : $a->locale('id')->translatedFormat('j M').' – '.$b->locale('id')->translatedFormat('j M Y');

        return [$a->toDateString(), $b->toDateString(), $label];
    }

    private function catLabel(ClassCashEntry $e): string
    {
        return ($e->type === 'keluar' ? ClassCashEntry::OUT : ClassCashEntry::IN)[$e->category] ?? $e->category;
    }

    private function payload(Classroom $c, ?Student $me): array
    {
        $set = ClassCashSetting::query()->firstWhere('classroom_id', $c->id);
        $dues = (int) ($set?->dues_amount ?? 0);
        $per = $set?->dues_period ?? 'minggu';
        [$from, $to, $label] = $this->period($per);
        $students = Student::query()->where('classroom_id', $c->id)->where('is_active', true)->orderBy('name')->get(['id', 'name', 'class_role']);
        $paid = ClassCashEntry::query()->where('classroom_id', $c->id)->where('type', 'masuk')->where('category', 'iuran')
            ->whereBetween('date', [$from, $to])->selectRaw('student_id, sum(amount) s')->groupBy('student_id')->pluck('s', 'student_id');
        $total = ClassCashEntry::query()->where('classroom_id', $c->id)->where('type', 'masuk')->where('category', 'iuran')->selectRaw('student_id, sum(amount) s')->groupBy('student_id')->pluck('s', 'student_id');
        $month = [now($this->tz())->startOfMonth()->toDateString(), now($this->tz())->endOfMonth()->toDateString()];
        $mq = ClassCashEntry::query()->where('classroom_id', $c->id)->whereBetween('date', $month);
        $entries = ClassCashEntry::query()->where('classroom_id', $c->id)->with('student:id,name')->orderByDesc('date')->orderByDesc('id')->limit(300)->get();
        $treasurer = $me && in_array($me->class_role, self::TREASURERS, true);
        $c->loadMissing('homeroomTeacher');

        return [
            'classroom' => $c->only(['id', 'name']), 'homeroom' => $c->homeroomTeacher?->name,
            'can_edit' => $treasurer, 'is_student' => (bool) $me, 'me' => $me?->id,
            'treasurers' => $students->filter(fn ($s) => in_array($s->class_role, self::TREASURERS, true))->sortBy('class_role')->pluck('name')->values(),
            'balance' => $this->balance($c),
            'month' => ['in' => (int) (clone $mq)->where('type', 'masuk')->sum('amount'), 'out' => (int) (clone $mq)->where('type', 'keluar')->sum('amount'),
                'label' => now($this->tz())->locale('id')->translatedFormat('F Y')],
            'dues' => ['amount' => $dues, 'period' => $per, 'label' => $label],
            'categories' => ['masuk' => ClassCashEntry::IN, 'keluar' => ClassCashEntry::OUT],
            'members' => $students->map(fn ($s) => ['id' => $s->id, 'name' => $s->name, 'paid' => (int) ($paid[$s->id] ?? 0), 'total' => (int) ($total[$s->id] ?? 0),
                'status' => $dues <= 0 ? null : (($paid[$s->id] ?? 0) >= $dues ? 'lunas' : (($paid[$s->id] ?? 0) > 0 ? 'kurang' : 'belum'))])->values(),
            'entries' => $entries->map(fn ($e) => ['id' => $e->id, 'type' => $e->type, 'category' => $e->category, 'category_label' => $this->catLabel($e),
                'amount' => $e->amount, 'date' => $e->date->format('Y-m-d'), 'description' => $e->description, 'student' => $e->student?->name,
                'proof_url' => $e->proof_path ? $this->files->url($e->proof_path, 30) : null, 'by' => $e->recorded_name,
                'can_delete' => $treasurer && $e->created_at->gte(now()->subDays(30))])->values(),
            'today' => $this->today(),
        ];
    }
}
