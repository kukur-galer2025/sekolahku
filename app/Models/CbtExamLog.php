<?php

namespace App\Models;

use App\Tenancy\BelongsToSchool;
use Illuminate\Database\Eloquent\Model;

/** Jejak pelaksanaan ujian: token benar/salah, mulai, keluar aplikasi, kirim, reset, token dibuat ulang, nilai diubah. */
class CbtExamLog extends Model
{
    use BelongsToSchool;

    public const UPDATED_AT = null;

    protected $fillable = ['cbt_exam_id', 'student_id', 'user_id', 'event', 'detail'];
}
