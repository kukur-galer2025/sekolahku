<?php

namespace App\Models;

use App\Tenancy\BelongsToSchool;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Assignment extends Model
{
    use BelongsToSchool;

    protected $fillable = ['teacher_id', 'classroom_id', 'journal_id', 'subject', 'title', 'description', 'due_date', 'due_time'];

    protected function casts(): array
    {
        return ['due_date' => 'date:Y-m-d'];
    }

    /** Jam tenggat "HH:MM" (tugas lama tanpa jam = 23:59). */
    public function dueHm(): string
    {
        return $this->due_time ? substr((string) $this->due_time, 0, 5) : '23:59';
    }

    /** Batas waktu pengumpulan dalam zona waktu sekolah. */
    public function deadline(string $tz): \Illuminate\Support\Carbon
    {
        return \Illuminate\Support\Carbon::parse($this->due_date->format('Y-m-d').' '.$this->dueHm().':59', $tz);
    }

    public function teacher(): BelongsTo
    {
        return $this->belongsTo(Teacher::class);
    }

    public function classroom(): BelongsTo
    {
        return $this->belongsTo(Classroom::class);
    }

    public function done(): HasMany
    {
        return $this->hasMany(AssignmentDone::class);
    }

    /**
     * Beri tahu guru pembuat tugas bahwa siswa mengumpulkan (unggah berkas / tandai selesai).
     * Satu siswa yang mengunggah beberapa berkas sekaligus tidak memicu notifikasi beruntun (jeda 10 menit).
     */
    public function notifySubmitted(Student $student, int $files = 0): void
    {
        $teacherUser = $this->teacher?->user;
        if (! $teacherUser) {
            return;
        }
        $url = '#/tugas?a='.$this->id;
        $title = mb_substr($student->name.' mengumpulkan: '.$this->title, 0, 160);
        $recent = \Illuminate\Support\Facades\DB::table('user_notifications')->where('user_id', $teacherUser->id)->where('type', 'submission')
            ->where('title', $title)->where('created_at', '>=', now()->subMinutes(10))->exists();
        if ($recent) {
            return;
        }
        $done = AssignmentDone::query()->where('assignment_id', $this->id)->count();
        $total = Student::query()->where('classroom_id', $this->classroom_id)->where('is_active', true)->count();
        $tz = app(\App\Tenancy\TenantContext::class)->get()?->timezone ?: config('tenancy.default_timezone', 'Asia/Jakarta');
        $late = $this->due_date ? $this->deadline($tz)->isPast() : false;
        $body = trim(($this->classroom?->name ?? '').' · '.$this->subject.' · '.($files ? $files.' berkas' : 'ditandai selesai tanpa berkas')
            .($late ? ' · lewat tenggat' : '').' · '.$done.'/'.$total.' terkumpul', ' ·');
        rescue(fn () => app(\App\Services\Notify\Notifier::class)->send([$teacherUser], 'submission', $title, $body, $url));
    }
}
