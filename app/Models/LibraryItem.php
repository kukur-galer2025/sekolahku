<?php

namespace App\Models;

use App\Tenancy\BelongsToSchool;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LibraryItem extends Model
{
    use BelongsToSchool;

    protected $fillable = ['title','author','category','subject','classroom_id','description','type','url','file_path','file_name','file_size','allow_download','download_limit','uploaded_by','uploader_name'];

    protected function casts(): array
    {
        return ['file_size' => 'integer', 'allow_download' => 'boolean', 'download_limit' => 'integer'];
    }

    public function classroom(): BelongsTo
    {
        return $this->belongsTo(Classroom::class);
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
