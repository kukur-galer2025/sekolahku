<?php

namespace App\Http\Controllers\Api;

use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Models\Classroom;
use App\Models\LibraryAccessLog;
use App\Models\LibraryActivity;
use App\Models\LibraryItem;
use App\Services\Library\LibraryProtection;
use App\Services\StoredUpload;
use App\Tenancy\TenantFiles;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

/**
 * MP-Library: perpustakaan digital sekolah.
 * Guru & admin menambah PDF / gambar / tautan, untuk semua kelas atau satu kelas.
 * Siswa melihat koleksi untuk kelasnya, membuka (tercatat "dibuka"), dan menyimpan favorit.
 *
 * Proteksi (semua peran): berkas dibaca di penampil terlindung lewat tiket sekali pakai, ber-watermark
 * (identitas + kode jejak), unduhan dibatasi per koleksi, dan setiap akses/percobaan tercatat di library_access_logs.
 */
class LibraryController extends Controller
{
    public const CATEGORIES = ['Buku Pelajaran', 'Modul Ajar', 'Fiksi & Novel', 'Referensi', 'Latihan Soal', 'Lainnya'];

    public function __construct(private TenantFiles $files, private LibraryProtection $guard) {}

    public function index(Request $request): JsonResponse
    {
        $u = $request->user();
        $items = LibraryItem::query()->with('classroom:id,name')->latest()->limit(1000);

        if ($u->role === Role::Student) {
            $s = $u->student;
            $items->where(fn ($q) => $q->whereNull('classroom_id')->orWhere('classroom_id', $s?->classroom_id ?? 0));
            $mine = LibraryActivity::query()->where('student_id', $s?->id ?? 0)->get()->keyBy('library_item_id');
            $list = $items->get()->map(fn ($x) => $this->present($x, $u) + [
                'opened' => (bool) $mine->get($x->id)?->opened_at, 'favorite' => (bool) $mine->get($x->id)?->favorited_at,
            ]);

            return response()->json(['categories' => self::CATEGORIES, 'items' => $list, 'stats' => [
                'total' => $list->count(), 'opened' => $list->where('opened', true)->count(), 'favorites' => $list->where('favorite', true)->count(),
            ]]);
        }

        $reads = LibraryActivity::query()->whereNotNull('opened_at')->selectRaw('library_item_id, count(*) n')->groupBy('library_item_id')->pluck('n', 'library_item_id');
        $list = $items->get()->map(fn ($x) => $this->present($x, $u) + ['reads' => (int) ($reads[$x->id] ?? 0)]);

        return response()->json(['categories' => self::CATEGORIES, 'items' => $list, 'stats' => [
            'total' => $list->count(), 'reads' => (int) $reads->sum(),
            'active_readers' => LibraryActivity::query()->whereNotNull('opened_at')->distinct()->count('student_id'),
        ]]);
    }

    public function store(Request $request, StoredUpload $upload): JsonResponse
    {
        $u = $request->user();
        $d = $request->validate([
            'title' => ['required', 'string', 'max:120'], 'author' => ['nullable', 'string', 'max:80'],
            'category' => ['required', Rule::in(self::CATEGORIES)], 'subject' => ['nullable', 'string', 'max:60'],
            'classroom_id' => ['nullable', 'integer'], 'description' => ['nullable', 'string', 'max:200'],
            'file' => ['nullable', 'file', 'max:20480'], 'url' => ['nullable', 'string', 'max:2000'],
            'allow_download' => ['nullable', 'boolean'], 'download_limit' => ['nullable', 'integer', 'min:1', 'max:20'],
        ], [], ['title' => 'judul', 'file' => 'berkas', 'url' => 'tautan']);

        if (empty($d['file']) && empty($d['url'])) {
            throw ValidationException::withMessages(['file' => 'Pilih berkas atau isi tautan.']);
        }
        if (! empty($d['classroom_id'])) {
            Classroom::query()->findOrFail($d['classroom_id']); // kelas sekolah ini saja
        }
        $f = null;
        if (! empty($d['file'])) {
            $f = $upload->store($d['file'], 'library', 20 * 1024 * 1024, 2000);
        } elseif (! preg_match('#^https?://[^\s"\'<>]+$#i', $d['url'])) {
            throw ValidationException::withMessages(['url' => 'Tautan harus diawali http:// atau https://']);
        }

        $item = LibraryItem::create([
            'title' => $d['title'], 'author' => $d['author'] ?? null, 'category' => $d['category'], 'subject' => $d['subject'] ?? null,
            'classroom_id' => $d['classroom_id'] ?? null, 'description' => $d['description'] ?? null,
            'type' => $f ? ($f['type'] === 'PDF' ? 'pdf' : 'image') : 'link', 'url' => $f ? null : $d['url'],
            'file_path' => $f['path'] ?? null, 'file_name' => $f['name'] ?? null, 'file_size' => $f['size'] ?? null,
            'allow_download' => (bool) ($d['allow_download'] ?? false), 'download_limit' => (int) ($d['download_limit'] ?? 3),
            'uploaded_by' => $u->id, 'uploader_name' => $u->role === Role::Admin ? 'Admin' : $u->name,
        ]);

        // Bacaan baru → siswa kelas tujuan (atau semua siswa).
        rescue(fn () => app(\App\Services\Notify\Notifier::class)->send(\App\Models\User::query()->where('role', 'student')->where('is_active', true)
            ->whereHas('student', fn ($q) => $q->where('status', 'aktif')->when($item->classroom_id, fn ($w) => $w->where('classroom_id', $item->classroom_id)))->get(),
            'library', 'Bacaan baru di MP-Library', $item->title.' · '.$item->category, '#/library'));

        return response()->json(['message' => 'Ditambahkan ke MP-Library.', 'item' => $this->present($item->load('classroom:id,name'), $u)], 201);
    }

    public function destroy(Request $request, LibraryItem $item): JsonResponse
    {
        $u = $request->user();
        abort_unless($u->role === Role::Admin || $item->uploaded_by === $u->id, 403, 'Hanya pengunggah atau admin yang bisa menghapus.');
        $this->files->delete($item->file_path);
        $item->delete();

        return response()->json(['message' => 'Dihapus dari MP-Library.']);
    }

    /**
     * Buka bacaan. Tautan luar dikembalikan apa adanya (tidak bisa dilindungi) dan dicatat.
     * Berkas PDF/gambar TIDAK pernah dikirim sebagai alamat: yang dikirim tiket sekali pakai + data watermark
     * untuk penampil terlindung di aplikasi.
     */
    public function open(Request $request, LibraryItem $item): JsonResponse
    {
        $u = $request->user();
        if ($u->role === Role::Student) {
            $this->assertVisible($item, $u);
            $a = LibraryActivity::query()->firstOrNew(['library_item_id' => $item->id, 'student_id' => $u->student->id]);
            $a->opened_at ??= now();
            $a->save();
        }
        if ($item->type === 'link') {
            $this->guard->log($item, $u, 'link', null, null, $request);

            return response()->json(['type' => 'link', 'url' => $item->url]);
        }

        $trace = $this->guard->trace();
        $this->guard->log($item, $u, 'open', $trace, null, $request);
        $key = $item->type === 'pdf' ? bin2hex(random_bytes(16)) : null;
        $ticket = $this->guard->issueTicket($item, $u, 'view', $trace);
        if ($key) {
            \Illuminate\Support\Facades\Cache::put('mp-lib-key:'.hash('sha256', $ticket), $key, LibraryProtection::TICKET_TTL);
        }

        return response()->json([
            'type' => $item->type, 'title' => $item->title, 'trace' => $trace, 'key' => $key,
            'stream' => '/api/library/'.$item->id.'/stream?as=view&t='.$ticket,
            'watermark' => $this->guard->watermarkLines($u, $trace),
            'policy' => ['download' => $this->downloadState($item, $u)],
        ]);
    }

    /** Isi berkas lewat tiket (middleware library.protect:ticket). Gambar dibakar watermark di server; PDF disamarkan XOR. */
    public function stream(Request $request, LibraryItem $item): Response
    {
        $t = $request->attributes->get('library_ticket');
        $u = $request->user();
        if ($u->role === Role::Student) {
            $this->assertVisible($item, $u);
        }
        abort_if($item->type === 'link', 404);
        $bytes = $this->guard->contents($item);
        $download = $t['purpose'] === 'download';
        $name = trim(preg_replace('/[^A-Za-z0-9 _-]+/', '', $item->title)) ?: 'MP-Library';

        if ($item->type === 'image') {
            $jpg = $this->guard->stampImage($bytes, $this->guard->watermarkLines($u, $t['trace']));

            return response($jpg, 200, ['Content-Type' => 'image/jpeg',
                'Content-Disposition' => ($download ? 'attachment' : 'inline').'; filename="'.$name.'.jpg"']);
        }

        // PDF: byte disamarkan dengan kunci per tiket; hanya penampil yang memegang kuncinya yang bisa membaca.
        $key = \Illuminate\Support\Facades\Cache::pull('mp-lib-key:'.hash('sha256', (string) $request->query('t')));
        abort_unless(is_string($key) && strlen($key) === 32, 403, 'Kunci baca kedaluwarsa. Buka lagi bacaannya.');

        return response($bytes ^ str_repeat($key, intdiv(strlen($bytes), 32) + 1), 200, [
            'Content-Type' => 'application/octet-stream', 'Content-Disposition' => 'attachment; filename="protected.bin"',
        ]);
    }

    /** Minta izin unduh: dicek kebijakan + jatah harian, lalu diberi tiket unduh (dicatat). */
    public function download(Request $request, LibraryItem $item): JsonResponse
    {
        $u = $request->user();
        if ($u->role === Role::Student) {
            $this->assertVisible($item, $u);
        }
        $left = $this->guard->downloadsLeft($item, $u);
        if ($left === 0) {
            $this->guard->log($item, $u, 'blocked_download', null, $item->allow_download ? 'Jatah unduh hari ini habis' : 'Koleksi hanya baca', $request);

            return response()->json(['message' => $item->allow_download
                ? 'Jatah unduh hari ini sudah habis ('.$item->download_limit.'x). Coba lagi besok.'
                : 'Koleksi ini hanya bisa dibaca di aplikasi. Unduhan tidak diizinkan pengunggah.'], 403);
        }

        $trace = $this->guard->trace();
        $this->guard->log($item, $u, 'download', $trace, null, $request);
        $ticket = $this->guard->issueTicket($item, $u, 'download', $trace);
        $key = $item->type === 'pdf' ? bin2hex(random_bytes(16)) : null;
        if ($key) {
            \Illuminate\Support\Facades\Cache::put('mp-lib-key:'.hash('sha256', $ticket), $key, LibraryProtection::TICKET_TTL);
        }

        return response()->json([
            'type' => $item->type, 'trace' => $trace, 'key' => $key, 'title' => $item->title,
            'stream' => '/api/library/'.$item->id.'/stream?as=download&t='.$ticket,
            'watermark' => $this->guard->watermarkLines($u, $trace),
            'left' => $left === null ? null : $left - 1,
        ]);
    }

    /** Laporan dari penampil: selesai membaca, coba salin/cetak/tangkap layar, pindah tab, dll. */
    public function event(Request $request, LibraryItem $item): JsonResponse
    {
        $u = $request->user();
        if ($u->role === Role::Student) {
            $this->assertVisible($item, $u);
        }
        $d = $request->validate([
            'event' => ['required', Rule::in(LibraryAccessLog::CLIENT_EVENTS)],
            'trace' => ['nullable', 'string', 'regex:/^[A-Z0-9]{8}$/'], 'detail' => ['nullable', 'string', 'max:120'],
        ]);
        // Kode jejak harus berasal dari pembukaan milik pengguna ini (cegah log palsu atas nama orang lain).
        if (! empty($d['trace']) && ! LibraryAccessLog::query()->where('library_item_id', $item->id)->where('user_id', $u->id)->where('trace', $d['trace'])->exists()) {
            $d['trace'] = null;
        }
        $this->guard->log($item, $u, $d['event'], $d['trace'] ?? null, $d['detail'] ?? null, $request);

        return response()->json(['ok' => true]);
    }

    /** Ubah kebijakan unduh (pengunggah atau admin). */
    public function policy(Request $request, LibraryItem $item): JsonResponse
    {
        $u = $request->user();
        abort_unless($this->guard->canManage($item, $u), 403, 'Hanya pengunggah atau admin yang bisa mengubah kebijakan.');
        $d = $request->validate(['allow_download' => ['required', 'boolean'], 'download_limit' => ['required', 'integer', 'min:1', 'max:20']]);
        abort_if($item->type === 'link' && $d['allow_download'], 422, 'Tautan luar tidak bisa diatur unduhannya.');
        $item->update($d);
        $this->guard->log($item, $u, 'policy', null, $d['allow_download'] ? 'Unduh diizinkan, '.$d['download_limit'].'x/hari' : 'Hanya baca', $request);

        return response()->json(['message' => $d['allow_download'] ? 'Unduhan diizinkan (maks. '.$d['download_limit'].'x per orang per hari).' : 'Koleksi diatur hanya baca.', 'item' => $this->present($item->load('classroom:id,name'), $u)]);
    }

    /**
     * Log audit. Admin: semua koleksi (bisa difilter ?item= / ?trace= / ?suspicious=1).
     * Guru: hanya koleksi yang ia unggah.
     */
    public function audit(Request $request): JsonResponse
    {
        return response()->json($this->auditData($request));
    }

    public function auditExport(Request $request): \Symfony\Component\HttpFoundation\BinaryFileResponse
    {
        $data = $this->auditData($request, 5000);
        $tz = $this->tenantTz();
        $tmp = tempnam(sys_get_temp_dir(), 'lib').'.xlsx';
        $w = new \OpenSpout\Writer\XLSX\Writer;
        $w->openToFile($tmp);
        $bold = (new \OpenSpout\Common\Entity\Style\Style)->setFontBold();
        $w->addRow(\OpenSpout\Common\Entity\Row::fromValues(['Waktu', 'Koleksi', 'Pengguna', 'Peran', 'Peristiwa', 'Kode jejak', 'Keterangan', 'Perangkat', 'IP'], $bold));
        foreach ($data['logs'] as $l) {
            $w->addRow(\OpenSpout\Common\Entity\Row::fromValues([\Illuminate\Support\Carbon::parse($l['at'])->setTimezone($tz)->format('Y-m-d H:i:s'),
                $l['item'], $l['user'], $l['role'], $l['label'], $l['trace'] ?? '', $l['detail'] ?? '', $l['device'] ?? '', $l['ip'] ?? '']));
        }
        $w->close();

        return response()->download($tmp, 'Audit_MP-Library_'.now($tz)->format('Ymd_His').'.xlsx')->deleteFileAfterSend();
    }

    private function auditData(Request $request, int $limit = 300): array
    {
        $u = $request->user();
        $q = LibraryAccessLog::query()->with('item:id,title')->latest('id');
        if ($u->role !== Role::Admin) {
            $q->whereIn('library_item_id', LibraryItem::query()->where('uploaded_by', $u->id)->select('id'));
        }
        if ($request->filled('item')) {
            $q->where('library_item_id', (int) $request->query('item'));
        }
        if ($request->filled('trace')) {
            $q->where('trace', strtoupper(substr((string) $request->query('trace'), 0, 12)));
        }
        if ($request->boolean('suspicious')) {
            $q->whereIn('event', LibraryAccessLog::SUSPICIOUS);
        }
        $since = (clone $q)->where('created_at', '>=', now()->subDays(7));

        return [
            'summary' => [
                'opens_7d' => (clone $since)->where('event', 'open')->count(),
                'downloads_7d' => (clone $since)->where('event', 'download')->count(),
                'suspicious_7d' => (clone $since)->whereIn('event', LibraryAccessLog::SUSPICIOUS)->count(),
                'readers_7d' => (clone $since)->where('event', 'open')->distinct()->count('user_id'),
            ],
            'logs' => $q->limit($limit)->get()->map(fn ($l) => [
                'id' => $l->id, 'at' => $l->created_at->toIso8601String(), 'item_id' => $l->library_item_id, 'item' => $l->item?->title ?? '(dihapus)',
                'user' => $l->user_name, 'role' => ['admin' => 'Admin', 'teacher' => 'Guru', 'student' => 'Siswa'][$l->role] ?? $l->role,
                'event' => $l->event, 'label' => LibraryAccessLog::LABELS[$l->event] ?? $l->event,
                'suspicious' => in_array($l->event, LibraryAccessLog::SUSPICIOUS, true),
                'trace' => $l->trace, 'detail' => $l->detail, 'device' => $l->device, 'ip' => $u->role === Role::Admin ? $l->ip : null,
            ])->all(),
        ];
    }

    private function downloadState(LibraryItem $item, $u): array
    {
        $left = $this->guard->downloadsLeft($item, $u);

        return ['allowed' => $left !== 0, 'left' => $left, 'limit' => $item->download_limit, 'manager' => $this->guard->canManage($item, $u)];
    }

    private function tenantTz(): string
    {
        return app(\App\Tenancy\TenantContext::class)->get()?->timezone ?: 'Asia/Jakarta';
    }

    public function favorite(Request $request, LibraryItem $item): JsonResponse
    {
        $u = $request->user();
        $this->assertVisible($item, $u);
        $a = LibraryActivity::query()->firstOrNew(['library_item_id' => $item->id, 'student_id' => $u->student->id]);
        $a->favorited_at = $a->favorited_at ? null : now();
        $a->save();

        return response()->json(['favorite' => (bool) $a->favorited_at, 'message' => $a->favorited_at ? 'Disimpan ke Tersimpan.' : 'Dihapus dari Tersimpan.']);
    }

    private function assertVisible(LibraryItem $item, $u): void
    {
        abort_unless($u->student && ($item->classroom_id === null || $item->classroom_id === $u->student->classroom_id), 404);
    }

    private function present(LibraryItem $x, $viewer): array
    {
        return [
            'id' => $x->id, 'title' => $x->title, 'author' => $x->author, 'category' => $x->category, 'subject' => $x->subject,
            'classroom' => $x->classroom?->only(['id', 'name']), 'description' => $x->description, 'type' => $x->type,
            'type_label' => ['pdf' => 'PDF', 'image' => 'Gambar', 'link' => 'Tautan'][$x->type] ?? $x->type,
            'file_size' => $x->file_size, 'uploader' => $x->uploader_name, 'created_at' => $x->created_at->toIso8601String(),
            'can_delete' => $viewer->role === Role::Admin || $x->uploaded_by === $viewer->id,
            'allow_download' => (bool) $x->allow_download, 'download_limit' => $x->download_limit,
        ];
    }
}
