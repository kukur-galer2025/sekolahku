<?php

namespace App\Http\Controllers\Api;

use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Models\AgendaPage;
use App\Models\Announcement;
use App\Models\Teacher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Agenda sekolah/briefing dan informasi terbaru. Dikelola admin, dibaca guru & siswa. */
class BoardController extends Controller
{
    /** Siswa hanya melihat agenda sekolah; briefing khusus guru. */
    private function kindsFor(Request $request): array
    {
        return $request->user()->role === Role::Student ? ['sekolah'] : array_keys(AgendaPage::KINDS);
    }

    public function agenda(Request $request): JsonResponse
    {
        $pages = AgendaPage::query()->with(['coordinator:id,name', 'editor:id,name'])->whereIn('kind', $this->kindsFor($request))->get()->keyBy('kind');

        $tid = $request->user()->teacher?->id;

        return response()->json(collect($this->kindsFor($request))->mapWithKeys(fn ($k) => [$k => [
            'kind' => $k, 'label' => AgendaPage::KINDS[$k],
            // Guru koordinator agenda ini boleh mengubah judul & isinya (koordinator tetap dipilih admin).
            'can_edit' => $request->user()->role === Role::Admin || ($tid && $pages->get($k)?->coordinator_id === $tid),
            'page' => ($p = $pages->get($k)) ? [
                'title' => $p->title, 'body' => $p->body, 'coordinator' => $p->coordinator?->only(['id', 'name']),
                'updated_at' => $p->updated_at?->toIso8601String(), 'updated_by' => $p->editor?->name,
            ] : null,
        ]]));
    }

    public function saveAgenda(Request $request, string $kind): JsonResponse
    {
        abort_unless(isset(AgendaPage::KINDS[$kind]), 404);
        $u = $request->user();
        $page = AgendaPage::query()->firstWhere('kind', $kind);
        $admin = $u->role === Role::Admin;
        abort_unless($admin || ($page && $u->teacher && $page->coordinator_id === $u->teacher->id), 403, 'Hanya admin atau guru koordinator agenda ini yang bisa mengubahnya.');
        $d = $request->validate([
            'title' => ['required', 'string', 'max:160'],
            'body' => ['required', 'string', 'max:20000'],
            'coordinator_id' => ['nullable', 'integer'],
        ], [], ['title' => 'judul agenda', 'body' => 'isi agenda']);
        if (! $admin) {
            unset($d['coordinator_id']); // koordinator hanya ditentukan admin
        } elseif (! empty($d['coordinator_id']) && ! Teacher::find($d['coordinator_id'])) {
            abort(422, 'Koordinator harus guru di sekolah ini.');
        }
        $before = $page?->coordinator_id;
        AgendaPage::updateOrCreate(['kind' => $kind], $d + ['updated_by' => $u->id]);
        if ($admin && ! empty($d['coordinator_id']) && (int) $d['coordinator_id'] !== (int) $before && ($t = Teacher::find($d['coordinator_id'])?->user)) {
            rescue(fn () => app(\App\Services\Notify\Notifier::class)->send([$t], 'coordinator', 'Kamu ditunjuk koordinator '.AgendaPage::KINDS[$kind],
                'Kamu bisa mengubah judul & isi agenda ini di menu Agenda.', '#/agenda?jenis='.$kind));
        }
        // Agenda sekolah untuk guru & siswa; agenda briefing hanya guru (sesuai kindsFor).
        $roles = $kind === 'sekolah' ? ['teacher', 'student'] : ['teacher'];
        rescue(fn () => app(\App\Services\Notify\Notifier::class)->toRoles($roles, 'agenda', AgendaPage::KINDS[$kind].' diperbarui', $d['title'], '#/agenda?jenis='.$kind, $u->id));

        return response()->json(['message' => AgendaPage::KINDS[$kind].' disimpan dan diberitahukan.']);
    }

    public function deleteAgenda(string $kind): JsonResponse
    {
        AgendaPage::query()->where('kind', $kind)->delete();

        return response()->json(null, 204);
    }

    public function announcements(Request $request): JsonResponse
    {
        $aud = match ($request->user()->role) {
            Role::Student => ['semua', 'siswa'],
            Role::Teacher => ['semua', 'guru'],
            default => array_keys(Announcement::AUDIENCES),
        };

        $cat = $request->query('category');
        $files = app(\App\Tenancy\TenantFiles::class);
        $coord = $this->coordinatorNames();
        $mine = Announcement::coordinatedBy($request->user()->teacher?->id);
        $uid = $request->user()->id;

        return response()->json(Announcement::query()->with('author:id,name')->whereIn('audience', $aud)
            ->when(is_string($cat) && isset(Announcement::CATEGORIES[$cat]), fn ($q) => $q->where('category', $cat))
            ->latest()->limit(30)->get()
            ->map(fn ($a) => ['id' => $a->id, 'title' => $a->title, 'body' => $a->body, 'audience' => $a->audience,
                'audience_label' => Announcement::AUDIENCES[$a->audience] ?? $a->audience,
                'category' => $a->category ?: 'umum', 'category_label' => Announcement::CATEGORIES[$a->category ?: 'umum'] ?? 'Umum',
                'coordinator' => $coord[$a->category] ?? null,
                'file_url' => $a->file_path ? $files->url($a->file_path, 120) : null, 'file_name' => $a->file_name, 'file_type' => $a->file_type,
                'author' => $a->author?->name, 'created_at' => $a->created_at?->toIso8601String(),
                'can_delete' => in_array($a->category, $mine, true) || $a->created_by === $uid,
                'can_edit' => in_array($a->category, $mine, true) || $a->created_by === $uid,
                'mine' => $a->created_by === $uid,
                'edited_at' => $a->updated_at && $a->created_at && $a->updated_at->gt($a->created_at->copy()->addMinute()) ? $a->updated_at->toIso8601String() : null]));
    }

    /** GET /api/info-coordinators — semua peran: {bidang: {id, name}|null, mine: [bidang yang dikoordinasi pengguna ini]}. */
    public function infoCoordinators(Request $request): JsonResponse
    {
        $ids = (array) (app(\App\Tenancy\TenantContext::class)->get()->settings['info_coordinators'] ?? []);
        $teachers = \App\Models\Teacher::query()->whereIn('id', array_filter($ids))->get(['id', 'name', 'user_id'])->keyBy('id');
        $av = \App\Support\Avatar::map($teachers->pluck('user_id'));

        return response()->json(collect(Announcement::COORDINATED)->mapWithKeys(fn ($c) => [$c => ($t = $teachers->get($ids[$c] ?? 0)) ? ['id' => $t->id, 'name' => $t->name, 'photo_url' => $av[$t->user_id] ?? null] : null])
            ->put('mine', Announcement::coordinatedBy($request->user()->teacher?->id)));
    }

    /** PUT /api/info-coordinators/{category} — admin memilih guru koordinator bidang (null = kosongkan). */
    public function saveInfoCoordinator(Request $request, string $category, \App\Services\Platform\AuditLogger $audit): JsonResponse
    {
        abort_unless(in_array($category, Announcement::COORDINATED, true), 404);
        $d = $request->validate(['teacher_id' => ['nullable', 'integer']]);
        $teacher = ! empty($d['teacher_id']) ? \App\Models\Teacher::query()->findOrFail($d['teacher_id']) : null; // terfilter sekolah ini
        $school = app(\App\Tenancy\TenantContext::class)->get();
        $settings = $school->settings ?? [];
        $settings['info_coordinators'] = array_merge((array) ($settings['info_coordinators'] ?? []), [$category => $teacher?->id]);
        $school->forceFill(['settings' => $settings])->save();
        $label = Announcement::CATEGORIES[$category];
        $audit->log('info.coordinator', $teacher ? "Koordinator $label: {$teacher->name}" : "Koordinator $label dikosongkan", $school, $request->user());
        if ($teacher?->user) {
            $habit = in_array($category, ['creo', 'literasi'], true);
            rescue(fn () => app(\App\Services\Notify\Notifier::class)->send([$teacher->user], 'coordinator', "Kamu ditunjuk koordinator $label",
                $habit ? 'Isi informasi & dokumentasi '.$label.' di menu Pembiasaan.' : 'Tulis informasi bidang '.$label.' di menu Informasi.',
                $habit ? '#/pembiasaan?k='.$category : '#/info?k='.$category));
        }

        return response()->json(['message' => $teacher ? "Koordinator $label: {$teacher->name}." : "Koordinator $label dikosongkan."]);
    }

    /** @return array<string, string> bidang → nama koordinator */
    private function coordinatorNames(): array
    {
        $ids = array_filter((array) (app(\App\Tenancy\TenantContext::class)->get()->settings['info_coordinators'] ?? []));
        if (! $ids) {
            return [];
        }
        $names = \App\Models\Teacher::query()->whereIn('id', $ids)->pluck('name', 'id');

        return array_filter(array_map(fn ($id) => $names[$id] ?? null, $ids));
    }

    public function storeAnnouncement(Request $request, \App\Services\StoredUpload $upload): JsonResponse
    {
        $d = $request->validate([
            'title' => ['required', 'string', 'max:120'],
            'body' => ['required', 'string', 'max:3000'],
            'audience' => ['required', Rule::in(array_keys(Announcement::AUDIENCES))],
            'category' => ['required', Rule::in(Announcement::WRITABLE)],
            'file' => ['nullable', 'file', 'max:10240', 'extensions:jpg,jpeg,png,pdf'],
        ], [
            'file.extensions' => 'Lampiran hanya JPG, PNG, atau PDF.',
            'file.max' => 'Lampiran maksimal 10 MB.',
        ], ['title' => 'judul', 'body' => 'isi informasi', 'audience' => 'tujuan', 'category' => 'bidang', 'file' => 'lampiran']);
        // Hanya guru koordinator bidang itu yang menulis informasi (ditunjuk admin). Admin, guru lain, dan siswa membaca saja.
        $tid = $request->user()->teacher?->id;
        abort_unless($tid && Announcement::coordinatorId($d['category']) === $tid, 403,
            'Hanya guru koordinator '.Announcement::CATEGORIES[$d['category']].' yang bisa mengirim informasi bidang ini.');

        $file = $request->file('file');
        unset($d['file']);
        if ($file) {
            if (! in_array($d['category'], Announcement::FILE_CATEGORIES, true)) {
                throw \Illuminate\Validation\ValidationException::withMessages(['file' => 'Lampiran berkas hanya untuk informasi Sarpras.']);
            }
            $f = $upload->store($file, 'informasi', 10 * 1024 * 1024, 2400); // denah perlu resolusi lebih tinggi
            $d += ['file_path' => $f['path'], 'file_name' => $f['name'], 'file_type' => $f['type']];
        }
        Announcement::create($d + ['created_by' => $request->user()->id]);

        $roles = ['semua' => ['teacher', 'student'], 'guru' => ['teacher'], 'siswa' => ['student']][$d['audience']];
        rescue(fn () => app(\App\Services\Notify\Notifier::class)->send(
            \App\Models\User::query()->whereIn('role', $roles)->where('is_active', true)->get(),
            'announcement', 'Informasi '.Announcement::CATEGORIES[$d['category']].': '.$d['title'], \Illuminate\Support\Str::limit($d['body'], 140),
            in_array($d['category'], ['creo', 'literasi'], true) ? '#/pembiasaan?k='.$d['category'] : '#/dash'));

        return response()->json(['message' => 'Informasi terkirim ke dashboard.'], 201);
    }

    /** PUT /api/announcements/{id} — penulis atau koordinator bidangnya mengubah judul, isi, dan tujuan. */
    public function updateAnnouncement(Request $request, Announcement $announcement): JsonResponse
    {
        $u = $request->user();
        abort_unless($announcement->created_by === $u->id || in_array($announcement->category, Announcement::coordinatedBy($u->teacher?->id), true), 403,
            'Hanya koordinator bidang ini atau penulisnya yang bisa mengubah.');
        $d = $request->validate([
            'title' => ['required', 'string', 'max:120'], 'body' => ['required', 'string', 'max:3000'],
            'audience' => ['required', Rule::in(array_keys(Announcement::AUDIENCES))],
        ], [], ['title' => 'judul', 'body' => 'isi informasi', 'audience' => 'tujuan']);
        $announcement->update($d);

        return response()->json(['message' => 'Informasi diperbarui.']);
    }

    public function deleteAnnouncement(Request $request, Announcement $announcement): JsonResponse
    {
        $u = $request->user();
        abort_unless($announcement->created_by === $u->id || in_array($announcement->category, Announcement::coordinatedBy($u->teacher?->id), true), 403,
            'Hanya koordinator bidang ini atau penulisnya yang bisa menghapus.');
        app(\App\Tenancy\TenantFiles::class)->delete($announcement->file_path);
        $announcement->delete();

        return response()->json(null, 204);
    }
}
