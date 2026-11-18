<?php

namespace App\Services\Platform;

use App\Models\AuditLog;
use App\Models\School;
use App\Models\User;

class AuditLogger
{
    public function log(string $action, string $description, ?School $school = null, ?User $actor = null, array $meta = [], ?string $actorName = null): AuditLog
    {
        $actor ??= auth()->user();

        return AuditLog::create([
            'school_id' => $school?->id,
            'actor_id' => $actor?->id,
            'actor_name' => $actorName ?? ($actor ? $actor->name.($actor->school_id === null ? ' (Parama Tech)' : '') : 'Sistem'),
            'action' => $action,
            'description' => $description,
            'ip' => request()?->ip(),
            'meta' => $meta ?: null,
        ]);
    }
}
