<?php

namespace App\Http\Controllers\Api;

use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Models\HabitSubmission;
use App\Models\Teacher;
use App\Services\StoredUpload;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantFiles;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Pembiasaan: CREO & Literasi.
 * Admin hanya MENENTUKAN guru koordinator tiap program. Yang mengisi data (mengunggah dokumentasi JPG/PNG/PDF)
 * hanya guru koordinator program itu; admin, guru lain, dan siswa melihat & membaca.
 */
class HabitController extends Controller
{
    public const KINDS = ['creo' => 'CREO', 'literasi' => 'Literasi'];

    public function __construct(private TenantContext $tenant, private TenantFiles $files) {}

    public function index(Request $request): JsonResponse
    {
        $d = $request->validate(['kind' => ['required', Rule::in(array_keys(self::KINDS))], 'classroom_id' => ['nullable', 'integer']]);
        $u = $request->user();
        $q = HabitSubmission::query()->with(['classroom:id,name', 'coordinator:id,name', 'user:id,name,role'])
            ->where('kind', $d['kind'])->latest()->limit(500);

        if ($u->role === Role::Student) {
            // Dokumentasi koordinator + kiriman lama siswa ini sendiri (dari alur lama).
            $q->where(fn ($x) => $x->whereNull('student_id')->orWhere('student_id', $u->student?->id ?? 0));
        } elseif (! empty($d['classroom_id'])) {
            $q->where('classroom_id', $d['classroom_id']);
        }

        return response()->json($q->get()->map(fn (HabitSubmission $h) => $this->present($h, $u)));
    }

    /** Koordinator CREO & Literasi yang DITENTUKAN ADMIN (menu Informasi → Pembiasaan): {creo: {id,name}|null, literasi: ...}. */
    public function coordinators(Request $request): JsonResponse
    {
        $tid = $request->user()->teacher?->id;

        return response()->json(collect(array_keys(self::KINDS))->mapWithKeys(fn ($k) => [$k => ($t = $this->coordinatorFor($k)) ? ['id' => $t->id, 'name' => $t->name, 'photo_url' => \App\Support\Avatar::url($t->user_id, $t->user?->photo_path)] : null])
            ->put('mine', array_values(array_filter(array_keys(self::KINDS), fn ($k) => $tid && $this->coordinatorFor($k)?->id === $tid))));
    }

    /** Hanya admin yang menentukan koordinator; guru, wali kelas, dan siswa tidak bisa memilih. */
    private array $coord = [];

    private function coordinatorFor(string $kind): ?Teacher
    {
        // Selalu baca pengaturan sekolah aktif; yang di-memo hanya data guru per id. Instance controller bisa dipakai
        // ulang antar-request (tes, Octane), jadi memo per bidang akan menyimpan koordinator lama/sekolah lain.
        $id = (int) (((array) (app(\App\Tenancy\TenantContext::class)->get()->settings['info_coordinators'] ?? []))[$kind] ?? 0);
        if (! $id) {
            return null;
        }
        if (! array_key_exists($id, $this->coord)) {
            $this->coord[$id] = Teacher::query()->with('user:id,photo_path')->find($id, ['id', 'name', 'user_id']);
        }

        return $this->coord[$id];
    }

    public function store(Request $request, StoredUpload $upload): JsonResponse
    {
        $u = $request->user();
        $d = $request->validate([
            'kind' => ['required', Rule::in(array_keys(self::KINDS))],
            'title' => ['nullable', 'string', 'max:120'],
            'file' => ['required', 'file', 'max:12288'],
        ], [], ['file' => 'berkas', 'title' => 'judul']);

        $coord = $this->coordinatorFor($d['kind']); // koordinator dari pengaturan admin, bukan pilihan pengunggah
        abort_unless($coord && ($u->teacher?->id ?? -1) === $coord->id, 403, 'Hanya guru koordinator '.self::KINDS[$d['kind']].' yang mengisi data pembiasaan ini.');
        $f = $upload->store($d['file'], 'pembiasaan/'.$d['kind'], 2 * 1024 * 1024);
        $h = HabitSubmission::create([
            'kind' => $d['kind'], 'title' => trim((string) ($d['title'] ?? '')) ?: $f['name'], 'user_id' => $u->id,
            'student_id' => $u->student?->id, 'classroom_id' => $u->student?->classroom_id, 'coordinator_id' => $coord?->id,
            'file_path' => $f['path'], 'file_name' => $f['name'], 'file_type' => $f['type'], 'file_size' => $f['size'],
        ]);

        rescue(fn () => app(\App\Services\Notify\Notifier::class)->toRoles(['admin', 'teacher', 'student'], 'habit', 'Dokumentasi '.self::KINDS[$d['kind']].' baru', $h->title, '#/pembiasaan?k='.$d['kind'], $u->id));

        return response()->json(['message' => 'Dokumentasi '.self::KINDS[$d['kind']].' ditambahkan.', 'item' => $this->present($h->load(['classroom:id,name', 'coordinator:id,name', 'user:id,name,role']), $u)], 201);
    }

    public function destroy(Request $request, HabitSubmission $habit): JsonResponse
    {
        $u = $request->user();
        abort_unless($habit->user_id === $u->id || ($u->teacher && $this->coordinatorFor($habit->kind)?->id === $u->teacher->id), 403, 'Hanya koordinator atau pengunggah yang bisa menghapus.');
        $this->files->delete($habit->file_path);
        $habit->delete();

        return response()->json(['message' => 'Berkas dihapus.']);
    }

    private function present(HabitSubmission $h, $viewer): array
    {
        return [
            'id' => $h->id, 'kind' => $h->kind, 'title' => $h->title, 'file_name' => $h->file_name, 'file_type' => $h->file_type, 'file_size' => $h->file_size,
            'url' => $this->files->url($h->file_path, 60), 'created_at' => $h->created_at->toIso8601String(),
            'by' => $h->user?->name, 'by_student' => $h->student_id !== null, 'classroom' => $h->classroom?->name, 'coordinator' => $h->coordinator?->name,
            'can_delete' => $h->user_id === $viewer->id || ($viewer->teacher && $this->coordinatorFor($h->kind)?->id === $viewer->teacher->id),
        ];
    }
}
