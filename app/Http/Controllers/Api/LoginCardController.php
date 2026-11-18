<?php

namespace App\Http\Controllers\Api;

use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Portal\PwaController;
use App\Models\Student;
use App\Models\Teacher;
use App\Services\Platform\AuditLogger;
use App\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * Kartu login per siswa (wali kelas & admin) dan per guru (admin): password sementara baru + link + QR pasang aplikasi.
 * Password lama tersimpan terenkripsi (hash) sehingga tidak bisa ditampilkan ulang — kartu selalu memakai password baru,
 * yang wajib diganti siswa saat masuk pertama. Daftar kartu hanya disimpan sementara (30 menit) untuk dicetak oleh pembuatnya.
 */
class LoginCardController extends Controller
{
    private const TTL_MINUTES = 30;

    public function __construct(private TenantContext $tenant) {}

    public function store(Request $request, AuditLogger $audit): JsonResponse
    {
        $u = $request->user();
        $d = $request->validate(['student_ids' => ['required', 'array', 'min:1', 'max:60'], 'student_ids.*' => ['integer']],
            ['student_ids.required' => 'Pilih minimal satu siswa.', 'student_ids.max' => 'Maksimal 60 siswa sekali cetak.']);
        $students = Student::query()->with(['user', 'classroom'])->whereIn('id', $d['student_ids'])->where('is_active', true)->orderBy('name')->get();
        abort_if($students->isEmpty(), 422, 'Siswa tidak ditemukan.');
        if ($u->role !== Role::Admin) {
            $mine = $students->every(fn ($s) => $s->classroom && $u->teacher && $s->classroom->homeroom_teacher_id === $u->teacher->id);
            abort_unless($mine, 403, 'Kartu login hanya untuk siswa di kelas perwalian Anda.');
        }

        $school = $this->tenant->get();
        $url = $school->url('siswa');
        $cards = DB::transaction(fn () => $students->filter(fn ($s) => $s->user)->map(function (Student $s) use ($url) {
            $pw = strtolower(Str::password(8, symbols: false));
            $s->user->forceFill(['password' => $pw, 'must_change_password' => true])->save();

            return ['role' => 'Siswa', 'username' => $s->user->username, 'name' => $s->name, 'class' => $s->classroom?->name ?? '-', 'password' => $pw,
                'app' => PwaController::APPS['siswa']['label'], 'url' => $url];
        })->values()->all());

        $key = Str::random(40);
        Cache::put($this->cacheKey($key), ['user_id' => $u->id, 'cards' => encrypt($cards), 'title' => 'Kartu Login Siswa'], now()->addMinutes(self::TTL_MINUTES));
        $classes = $students->pluck('classroom.name')->filter()->unique()->implode(', ');
        $audit->log('password.cards', 'Kartu login (password sementara baru) dicetak untuk '.count($cards).' siswa'.($classes ? ' kelas '.$classes : '').': '
            .$students->pluck('name')->take(10)->implode(', ').(count($cards) > 10 ? ', …' : ''), $school, $u);

        return response()->json(['message' => count($cards).' kartu login siap dicetak. Password lama siswa tersebut tidak berlaku lagi.',
            'url' => '/kartu-login/'.$key, 'count' => count($cards)], 201);
    }

    /** Kartu login guru (admin): password sementara baru + link & QR aplikasi Guru. */
    public function storeTeachers(Request $request, AuditLogger $audit): JsonResponse
    {
        $u = $request->user();
        abort_unless($u->role === Role::Admin, 403);
        $d = $request->validate(['teacher_ids' => ['required', 'array', 'min:1', 'max:80'], 'teacher_ids.*' => ['integer']],
            ['teacher_ids.required' => 'Pilih minimal satu guru.', 'teacher_ids.max' => 'Maksimal 80 guru sekali cetak.']);
        $teachers = Teacher::query()->with('user')->whereIn('id', $d['teacher_ids'])->orderBy('name')->get();
        abort_if($teachers->isEmpty(), 422, 'Guru tidak ditemukan.');
        // Admin tidak bisa mereset akunnya sendiri lewat kartu (mencegah terkunci).
        $teachers = $teachers->filter(fn ($t) => $t->user && $t->user->id !== $u->id && $t->user->role === Role::Teacher);
        abort_if($teachers->isEmpty(), 422, 'Guru yang dipilih belum punya akun login.');

        $school = $this->tenant->get();
        $url = $school->url('guru');
        $cards = DB::transaction(fn () => $teachers->map(function (Teacher $t) use ($url) {
            $pw = strtolower(Str::password(8, symbols: false));
            $t->user->forceFill(['password' => $pw, 'must_change_password' => true])->save();

            return ['role' => 'Guru', 'username' => $t->user->username, 'name' => $t->name, 'class' => '-', 'subject' => $t->subject, 'password' => $pw,
                'app' => PwaController::APPS['guru']['label'], 'url' => $url];
        })->values()->all());

        $key = Str::random(40);
        Cache::put($this->cacheKey($key), ['user_id' => $u->id, 'cards' => encrypt($cards), 'title' => 'Kartu Login Guru'], now()->addMinutes(self::TTL_MINUTES));
        $audit->log('password.cards', 'Kartu login guru (password sementara baru) dicetak untuk '.count($cards).' guru: '
            .$teachers->pluck('name')->take(10)->implode(', ').(count($cards) > 10 ? ', …' : ''), $school, $u);

        return response()->json(['message' => count($cards).' kartu login guru siap dicetak. Password lama guru tersebut tidak berlaku lagi.',
            'url' => '/kartu-login/'.$key, 'count' => count($cards)], 201);
    }

    public function show(Request $request, string $key): View
    {
        $data = Cache::get($this->cacheKey($key));
        abort_unless($data && $data['user_id'] === $request->user()->id, 404, 'Kartu login sudah kedaluwarsa (30 menit). Buat ulang dari menu Wali Kelas / Data Guru.');
        $cards = collect(decrypt($data['cards']));
        $qr = $cards->pluck('url')->unique()->mapWithKeys(fn ($u) => [$u => PwaController::qr($u)]);

        return view('portal.imports.cards', ['school' => $this->tenant->get(), 'cards' => $cards, 'qr' => $qr, 'title' => $data['title'] ?? 'Kartu Login Siswa', 'pwLabel' => 'Password sementara']);
    }

    private function cacheKey(string $key): string
    {
        return 'login-cards:'.$this->tenant->id().':'.hash('sha256', $key);
    }
}
