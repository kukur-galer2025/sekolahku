<?php

namespace App\Http\Controllers\Api;

use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Models\Classroom;
use App\Models\SchoolRoom;
use App\Services\Platform\AuditLogger;
use App\Services\Platform\Impersonation;
use App\Support\Plan;
use App\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Denah sekolah (Informasi → Sarpras & menu Denah Sekolah).
 * Tampilan resmi = GAMBAR JPG yang disusun & diunggah guru koordinator Sarpras; admin, guru, siswa hanya melihat.
 * Denah interaktif lama (ruang per gedung) tetap tersimpan untuk data, tidak lagi ditampilkan.
 * Melihat: semua peran, semua paket. Mengubah: admin sekolah paket All Service,
 * atau tim Parama Tech lewat "Masuk sebagai sekolah" (jasa pembuatan denah) untuk paket apa pun.
 */
class SchoolMapController extends Controller
{
    public function __construct(private TenantContext $tenant, private AuditLogger $audit) {}

    /** GET /api/school-map */
    public function show(Request $request): JsonResponse
    {
        $school = $this->tenant->get();
        $isAdmin = $request->user()->role === Role::Admin;
        $rooms = SchoolRoom::query()->with('classroom:id,name')->orderBy('building')->orderBy('floor')->orderBy('id')->get();
        $students = $isAdmin || $rooms->whereNotNull('classroom_id')->isNotEmpty()
            ? DB::table('students')->where('school_id', $school->id)->where('is_active', true)->whereNotNull('classroom_id')
                ->selectRaw('classroom_id, count(*) n')->groupBy('classroom_id')->pluck('n', 'classroom_id')
            : collect();

        return response()->json([
            'rooms' => $rooms->map(fn (SchoolRoom $r) => $this->present($r, $students)),
            'grades' => $school->grades(),
            'categories' => SchoolRoom::CATEGORIES,
            'can_edit' => $this->canEdit($request),
            'editor_plan' => Plan::label(Plan::cheapestWith('denah_editor')),
            'is_admin' => $isAdmin,
            'classrooms' => $isAdmin ? Classroom::query()->orderBy('name')->get(['id', 'name', 'grade'])
                ->map(fn ($c) => ['id' => $c->id, 'name' => $c->name, 'grade' => $c->grade, 'students' => (int) ($students[$c->id] ?? 0)]) : [],
        ]);
    }

    /** PUT /api/school-map — simpan seluruh denah (ruang yang tidak dikirim dihapus). */
    public function save(Request $request): JsonResponse
    {
        if (! $this->canEdit($request)) {
            abort(403, 'Mengubah denah interaktif tersedia di paket '.Plan::label(Plan::cheapestWith('denah_editor')).'. Hubungi Parama Tech untuk jasa pembuatan denah.');
        }
        $school = $this->tenant->get();
        $c = SchoolRoom::CANVAS;
        $d = $request->validate([
            'rooms' => ['present', 'array', 'max:500'],
            'rooms.*.id' => ['nullable', 'integer', 'min:1'],
            'rooms.*.classroom_id' => ['nullable', 'integer', 'min:1'],
            'rooms.*.building' => ['required', 'string', 'max:20', 'regex:/^[\pL\pN .\-]+$/u'],
            'rooms.*.floor' => ['required', 'integer', 'between:1,10'],
            'rooms.*.name' => ['required', 'string', 'max:100'],
            'rooms.*.category' => ['required', Rule::in(array_keys(SchoolRoom::CATEGORIES))],
            'rooms.*.grade' => ['nullable', Rule::in($school->grades())],
            'rooms.*.capacity' => ['nullable', 'integer', 'min:0', 'max:2000'],
            'rooms.*.x' => ['required', 'numeric', 'between:0,'.$c['w']],
            'rooms.*.y' => ['required', 'numeric', 'between:0,'.$c['h']],
            'rooms.*.width' => ['required', 'numeric', 'between:30,'.$c['w']],
            'rooms.*.height' => ['required', 'numeric', 'between:30,'.$c['h']],
        ], [
            'rooms.*.building.regex' => 'Nama gedung hanya huruf, angka, spasi, titik, atau tanda hubung.',
        ], ['rooms.*.building' => 'gedung', 'rooms.*.name' => 'nama ruang', 'rooms.*.floor' => 'lantai', 'rooms.*.grade' => 'tingkat']);

        // Kelas yang ditautkan harus milik sekolah ini (global scope tenant).
        $linked = collect($d['rooms'])->pluck('classroom_id')->filter()->unique();
        $known = Classroom::query()->whereIn('id', $linked)->pluck('id');
        if ($missing = $linked->diff($known)->first()) {
            throw ValidationException::withMessages(['rooms' => "Kelas #$missing tidak ditemukan di sekolah ini."]);
        }

        $before = SchoolRoom::query()->count();
        DB::transaction(function () use ($d) {
            $keep = [];
            foreach ($d['rooms'] as $row) {
                $id = $row['id'] ?? null;
                $attrs = collect($row)->only(['classroom_id', 'building', 'floor', 'name', 'category', 'grade', 'capacity', 'x', 'y', 'width', 'height'])->all();
                $attrs['building'] = trim($attrs['building']);
                $attrs['capacity'] = (int) ($attrs['capacity'] ?? 0);
                $attrs['grade'] = $attrs['category'] === 'kelas' ? ($attrs['grade'] ?? null) : null;
                $room = $id ? SchoolRoom::query()->find($id) : null; // find() terfilter sekolah: id sekolah lain dianggap ruang baru
                $room ? $room->update($attrs) : $room = SchoolRoom::create($attrs);
                $keep[] = $room->id;
            }
            SchoolRoom::query()->whereNotIn('id', $keep ?: [0])->delete();
        });
        $after = SchoolRoom::query()->count();
        $this->audit->log('school_map.saved', "Denah interaktif disimpan ($after ruang, sebelumnya $before)", $school, $request->user());

        return response()->json(['message' => 'Denah tersimpan.'] + $this->show($request)->getData(true));
    }

    // ── Gambar denah (JPG) ─────────────────────────────────────────

    /** Guru koordinator Sarpras (ditunjuk admin di Informasi → Sarpras). */
    private function isSarprasCoordinator(Request $request): bool
    {
        $tid = $request->user()->teacher?->id;

        return $tid && \App\Models\Announcement::coordinatorId('sarpras') === $tid;
    }

    /** GET /api/school-map/image — semua peran. */
    public function image(Request $request, \App\Services\Platform\SchoolMapImage $img): JsonResponse
    {
        $coord = \App\Models\Announcement::coordinatorId('sarpras');

        return response()->json(['image' => $img->present($this->tenant->get()), 'can_edit' => $this->isSarprasCoordinator($request),
            'coordinator' => $coord ? \App\Models\Teacher::query()->whereKey($coord)->value('name') : null]);
    }

    /** POST /api/school-map/image — hanya koordinator Sarpras: unggah/ganti denah JPG/PNG. */
    public function storeImage(Request $request, \App\Services\Platform\SchoolMapImage $img): JsonResponse
    {
        abort_unless($this->isSarprasCoordinator($request), 403, 'Hanya guru koordinator Sarpras yang menyusun dan mengunggah denah.');
        $request->validate(['file' => ['required', 'file', 'max:15360']], ['file.max' => 'Gambar denah maksimal 15 MB.'], ['file' => 'gambar denah']);
        $school = $this->tenant->get();
        $img->store($school, (string) file_get_contents($request->file('file')->getRealPath()), $request->user()->name);
        $this->audit->log('school_map.image', 'Gambar denah sekolah diperbarui', $school, $request->user());

        return response()->json(['message' => 'Denah sekolah diperbarui. Admin, guru, dan siswa melihat gambar terbaru.', 'image' => $img->present($school)]);
    }

    /** GET /api/school-map/layout — data Penyusun Denah (koordinator Sarpras). */
    public function layout(Request $request): JsonResponse
    {
        abort_unless($this->isSarprasCoordinator($request), 403, 'Penyusun denah hanya untuk guru koordinator Sarpras.');
        $school = $this->tenant->get();

        return response()->json(['layout' => $school->settings['denah_layout'] ?? null, 'school' => $school->name]);
    }

    /** PUT /api/school-map/layout — simpan data denah (gedung → bagian/lantai → baris → ruang). */
    public function saveLayout(Request $request): JsonResponse
    {
        abort_unless($this->isSarprasCoordinator($request), 403, 'Penyusun denah hanya untuk guru koordinator Sarpras.');
        $kinds = ['x', 'xi', 'xii', 'lab', 'kantor', 'pus', 'fas', 'aula', 'tangga', 'wc', 'kosong', 'spasi'];
        $d = $request->validate([
            'layout' => ['required', 'array'],
            'layout.title' => ['nullable', 'string', 'max:60'], 'layout.subtitle' => ['nullable', 'string', 'max:120'], 'layout.note' => ['nullable', 'string', 'max:140'],
            'layout.buildings' => ['present', 'array', 'max:20'],
            'layout.buildings.*.name' => ['required', 'string', 'max:40'], 'layout.buildings.*.note' => ['nullable', 'string', 'max:60'],
            'layout.buildings.*.span' => ['required', Rule::in(['penuh', 'setengah', 'sepertiga', 'duapertiga'])],
            'layout.buildings.*.sections' => ['required', 'array', 'min:1', 'max:12'],
            'layout.buildings.*.sections.*.label' => ['nullable', 'string', 'max:20'],
            'layout.buildings.*.sections.*.rows' => ['required', 'array', 'min:1', 'max:12'],
            'layout.buildings.*.sections.*.rows.*.size' => ['required', Rule::in(['besar', 'kecil'])],
            'layout.buildings.*.sections.*.rows.*.rooms' => ['present', 'array', 'max:40'],
            'layout.buildings.*.sections.*.rows.*.rooms.*.num' => ['nullable', 'string', 'max:6'],
            'layout.buildings.*.sections.*.rows.*.rooms.*.name' => ['nullable', 'string', 'max:40'],
            'layout.buildings.*.sections.*.rows.*.rooms.*.kind' => ['required', Rule::in($kinds)],
            'layout.buildings.*.sections.*.rows.*.rooms.*.cap' => ['nullable', 'integer', 'min:0', 'max:999'],
            'layout.buildings.*.sections.*.rows.*.rooms.*.w' => ['nullable', 'numeric', 'min:0.2', 'max:6'],
        ], [], ['layout.buildings.*.name' => 'nama gedung', 'layout.buildings.*.sections.*.rows.*.rooms.*.name' => 'nama ruang']);
        $school = $this->tenant->get();
        $settings = $school->settings ?? [];
        $settings['denah_layout'] = $d['layout'];
        $school->forceFill(['settings' => $settings])->save();

        return response()->json(['message' => 'Data denah tersimpan.']);
    }

    /** DELETE /api/school-map/image — hanya koordinator Sarpras. */
    public function deleteImage(Request $request, \App\Services\Platform\SchoolMapImage $img): JsonResponse
    {
        abort_unless($this->isSarprasCoordinator($request), 403, 'Hanya guru koordinator Sarpras yang bisa menghapus denah.');
        $img->delete($this->tenant->get());
        $this->audit->log('school_map.image', 'Gambar denah sekolah dihapus', $this->tenant->get(), $request->user());

        return response()->json(['message' => 'Gambar denah dihapus.']);
    }

    private function canEdit(Request $request): bool
    {
        if ($request->user()->role !== Role::Admin) {
            return false;
        }
        $impersonating = $request->hasSession() && $request->session()->has(Impersonation::SESSION_KEY);

        return $impersonating || Plan::allows($this->tenant->get()->plan, 'denah_editor');
    }

    private function present(SchoolRoom $r, $students): array
    {
        return [
            'id' => $r->id, 'classroom_id' => $r->classroom_id, 'classroom' => $r->classroom?->name,
            'students' => $r->classroom_id ? (int) ($students[$r->classroom_id] ?? 0) : null,
            'building' => $r->building, 'floor' => $r->floor, 'name' => $r->name, 'category' => $r->category, 'grade' => $r->grade,
            'capacity' => $r->capacity, 'x' => $r->x, 'y' => $r->y, 'width' => $r->width, 'height' => $r->height,
        ];
    }
}
