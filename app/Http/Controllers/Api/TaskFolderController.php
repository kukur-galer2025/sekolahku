<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Assignment;
use App\Models\AssignmentDone;
use App\Models\AssignmentFile;
use App\Models\Schedule;
use App\Models\Student;
use App\Services\StoredUpload;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantFiles;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Tugas-Ku (alur prototipe): satu folder per mata pelajaran kelas siswa.
 * Isi folder: tugas dari guru (centang selesai) + berkas yang dikumpulkan siswa.
 * Mengunggah berkas untuk tugas tertentu otomatis menandai tugas itu selesai.
 */
class TaskFolderController extends Controller
{
    public function __construct(private TenantContext $tenant, private TenantFiles $files) {}

    private function student(Request $request): Student
    {
        return $request->user()->student ?? abort(403, 'Akun ini tidak terhubung ke data siswa.');
    }

    public function index(Request $request): JsonResponse
    {
        $s = $this->student($request);
        $since = $this->tenant->get()->localNow()->subDays(120)->toDateString();
        $tasks = Assignment::query()->with('teacher:id,name')->where('classroom_id', $s->classroom_id ?? 0)->where('due_date', '>=', $since)->orderBy('due_date')->get();
        $done = AssignmentDone::query()->where('student_id', $s->id)->get()->keyBy('assignment_id');
        $myFiles = AssignmentFile::query()->where('student_id', $s->id)->latest()->get();

        $subjects = Schedule::query()->where('classroom_id', $s->classroom_id ?? 0)->distinct()->pluck('subject')
            ->merge($tasks->pluck('subject'))->merge($myFiles->pluck('folder'))->map(fn ($x) => trim((string) $x))->filter()->unique()->sort()->values();

        $folders = $subjects->map(function ($name) use ($tasks, $done, $myFiles) {
            $T = $tasks->where('subject', $name)->values();
            $F = $myFiles->where('folder', $name)->values();

            return [
                'name' => $name,
                'pending' => $T->filter(fn ($t) => ! $done->has($t->id))->count(),
                'tasks' => $T->map(fn ($t) => ['id' => $t->id, 'title' => $t->title, 'description' => $t->description, 'teacher' => $t->teacher?->name,
                    'due_date' => $t->due_date->format('Y-m-d'), 'due_time' => $t->dueHm(), 'done' => $done->has($t->id), 'files' => $F->where('assignment_id', $t->id)->count(),
                    'graded' => (bool) $done->get($t->id)?->graded_at, 'score' => $done->get($t->id)?->graded_at ? $done->get($t->id)->score : null,
                    'feedback' => $done->get($t->id)?->graded_at ? $done->get($t->id)->feedback : null]),
                'files' => $F->map(fn ($f) => $this->present($f, $T)),
            ];
        });

        return response()->json(['folders' => $folders, 'pending' => $folders->sum('pending')]);
    }

    public function store(Request $request, StoredUpload $upload): JsonResponse
    {
        $s = $this->student($request);
        $d = $request->validate(['folder' => ['required', 'string', 'max:80'], 'assignment_id' => ['nullable', 'integer'], 'file' => ['required', 'file', 'max:12288']],
            [], ['file' => 'berkas', 'folder' => 'folder']);

        $task = null;
        if (! empty($d['assignment_id'])) {
            $task = Assignment::query()->where('classroom_id', $s->classroom_id ?? 0)->find($d['assignment_id'])
                ?? throw ValidationException::withMessages(['assignment_id' => 'Tugas tidak ditemukan untuk kelasmu.']);
        }
        $f = $upload->store($d['file'], 'tugas/'.$s->id, 2 * 1024 * 1024, 1600, allowWord: true);
        $file = AssignmentFile::create(['student_id' => $s->id, 'assignment_id' => $task?->id, 'folder' => $task?->subject ?? trim($d['folder']),
            'file_path' => $f['path'], 'file_name' => $f['name'], 'file_type' => $f['type'], 'file_size' => $f['size']]);
        if ($task) {
            AssignmentDone::query()->firstOrCreate(['assignment_id' => $task->id, 'student_id' => $s->id], ['done_at' => now()]);
            $task->notifySubmitted($s, AssignmentFile::query()->where('assignment_id', $task->id)->where('student_id', $s->id)->count());
        }

        return response()->json(['message' => $task ? 'Berkas terkirim, tugas ditandai selesai.' : 'Berkas tersimpan di folder.', 'file' => $this->present($file, collect())], 201);
    }

    public function destroy(Request $request, AssignmentFile $file): JsonResponse
    {
        abort_unless($file->student_id === $this->student($request)->id, 403);
        abort_if($file->assignment_id && AssignmentDone::query()->where('assignment_id', $file->assignment_id)->where('student_id', $file->student_id)->whereNotNull('graded_at')->exists(),
            422, 'Tugas ini sudah dinilai guru, berkasnya tidak bisa dihapus.');
        $this->files->delete($file->file_path);
        $file->delete();

        return response()->json(['message' => 'Berkas dihapus.']);
    }

    private function present(AssignmentFile $f, $tasks): array
    {
        return ['id' => $f->id, 'name' => $f->file_name, 'type' => $f->file_type, 'size' => $f->file_size, 'assignment_id' => $f->assignment_id,
            'task' => $tasks->firstWhere('id', $f->assignment_id)?->title, 'url' => $this->files->url($f->file_path, 60, $f->file_name), 'created_at' => $f->created_at->toIso8601String()];
    }
}
