<?php

namespace App\Models;

use App\Tenancy\BelongsToSchool;
use Illuminate\Database\Eloquent\Model;

/** Iuran kas wajib per kelas (nominal & periode), diatur bendahara kelas. */
class ClassCashSetting extends Model
{
    use BelongsToSchool;

    protected $fillable = ['classroom_id', 'dues_amount', 'dues_period'];

    protected function casts(): array
    {
        return ['dues_amount' => 'integer'];
    }
}
