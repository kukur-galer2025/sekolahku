<?php

namespace App\Models;

use App\Tenancy\BelongsToSchool;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LibraryActivity extends Model
{
    use BelongsToSchool;

    protected $table = 'library_activity';

    public $timestamps = false;

    protected $fillable = ['library_item_id','student_id','opened_at','favorited_at'];

    protected function casts(): array
    {
        return ['opened_at' => 'datetime', 'favorited_at' => 'datetime'];
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(LibraryItem::class, 'library_item_id');
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }
}
