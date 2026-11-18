<?php

namespace App\Services\Platform;

use App\Models\LegalAcceptance;
use App\Models\School;
use App\Models\User;
use App\Tenancy\SchoolScope;

class Legal
{
    public static function version(string $doc): string
    {
        return (string) config("legal.documents.$doc.version");
    }

    /** Persetujuan sekolah atas versi dokumen yang berlaku sekarang (lintas konteks tenant). */
    public static function accepted(int $schoolId, string $doc): ?LegalAcceptance
    {
        return LegalAcceptance::query()->withoutGlobalScope(SchoolScope::class)
            ->where('school_id', $schoolId)->where('document', $doc)->where('version', self::version($doc))
            ->latest('accepted_at')->first();
    }

    public static function record(string $doc, School $school, ?User $user, ?string $signer = null, ?string $position = null): LegalAcceptance
    {
        return LegalAcceptance::create([
            'school_id' => $school->id, 'user_id' => $user?->id, 'document' => $doc, 'version' => self::version($doc),
            'signer_name' => $signer ?? $user?->name, 'signer_position' => $position,
            'ip' => request()?->ip(), 'user_agent' => substr((string) request()?->userAgent(), 0, 250), 'accepted_at' => now(),
        ]);
    }
}
