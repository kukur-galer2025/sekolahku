<?php

namespace App\Models;

use App\Tenancy\BelongsToSchool;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Kelas peserta ujian: token kelas (terenkripsi, untuk kartu token) + guru penilai essay. */
class CbtExamClass extends Model
{
    use BelongsToSchool;

    public $timestamps = false;

    protected $fillable = ['cbt_exam_id', 'classroom_id', 'essay_grader_id', 'token', 'token_at'];

    protected $hidden = ['token'];

    protected function casts(): array
    {
        return ['token' => 'encrypted', 'token_at' => 'datetime'];
    }

    public function exam(): BelongsTo
    {
        return $this->belongsTo(CbtExam::class, 'cbt_exam_id');
    }

    public function classroom(): BelongsTo
    {
        return $this->belongsTo(Classroom::class);
    }

    public function grader(): BelongsTo
    {
        return $this->belongsTo(Teacher::class, 'essay_grader_id');
    }

    /** 6 karakter tanpa huruf/angka yang mirip (O/0, I/1). */
    public static function newToken(): string
    {
        $c = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

        return implode('', array_map(fn () => $c[random_int(0, strlen($c) - 1)], range(1, 6)));
    }
}
