<?php

namespace App\Models;

use App\Tenancy\BelongsToSchool;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Biodata peserta didik (cover/identitas buku rapor). Satu baris per siswa. */
class StudentProfile extends Model
{
    use BelongsToSchool;

    /** kolom => [label, panjang maks.] — urutan mengikuti format "Keterangan tentang diri peserta didik". */
    public const FIELDS = [
        'birth_place' => ['Tempat lahir', 60], 'religion' => ['Agama / kepercayaan', 30], 'family_status' => ['Status dalam keluarga', 30],
        'child_order' => ['Anak ke', 5], 'address' => ['Alamat peserta didik', 300], 'phone' => ['Nomor telepon rumah', 30],
        'origin_school' => ['Sekolah asal', 120], 'accepted_class' => ['Diterima di kelas', 30], 'accepted_date' => ['Diterima pada tanggal', 10],
        'father_name' => ['Nama ayah', 120], 'mother_name' => ['Nama ibu', 120], 'parents_address' => ['Alamat orang tua', 300],
        'parents_phone' => ['Nomor telepon orang tua', 30], 'father_job' => ['Pekerjaan ayah', 60], 'mother_job' => ['Pekerjaan ibu', 60],
        'guardian_name' => ['Nama wali', 120], 'guardian_address' => ['Alamat wali', 300], 'guardian_phone' => ['Nomor telepon wali', 30],
        'guardian_job' => ['Pekerjaan wali', 60],
    ];

    protected $fillable = ['student_id', 'updated_by', ...self::KEYS];

    public const KEYS = ['birth_place', 'religion', 'family_status', 'child_order', 'address', 'phone', 'origin_school', 'accepted_class', 'accepted_date',
        'father_name', 'mother_name', 'parents_address', 'parents_phone', 'father_job', 'mother_job', 'guardian_name', 'guardian_address', 'guardian_phone', 'guardian_job'];

    protected function casts(): array
    {
        return ['accepted_date' => 'date:Y-m-d'];
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function editor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
