<?php

namespace App\Models;

use App\Tenancy\BelongsToSchool;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AgendaPage extends Model
{
    use BelongsToSchool;

    public const KINDS = ['sekolah' => 'Agenda Sekolah', 'briefing' => 'Agenda Briefing'];

    protected $fillable = ['kind', 'title', 'coordinator_id', 'body', 'updated_by'];

    public function coordinator(): BelongsTo
    {
        return $this->belongsTo(Teacher::class, 'coordinator_id');
    }

    public function editor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
