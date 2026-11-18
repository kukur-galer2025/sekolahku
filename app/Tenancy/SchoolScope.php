<?php

namespace App\Tenancy;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Global scope: setiap query model milik sekolah otomatis difilter school_id.
 *
 * Tanpa tenant aktif, scope ini "gagal tertutup": hanya baris dengan
 * school_id NULL yang terlihat (mis. akun super-admin). Untuk data sekolah
 * yang kolomnya NOT NULL, hasilnya kosong, sehingga lupa menyetel tenant
 * tidak pernah membocorkan data sekolah lain.
 *
 * Panel super-admin yang memang perlu melihat lintas sekolah harus menulis
 * ->withoutGlobalScope(SchoolScope::class) secara eksplisit.
 */
class SchoolScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $column = $model->qualifyColumn('school_id');
        $tenantId = app(TenantContext::class)->id();

        if ($tenantId === null) {
            $builder->whereNull($column);

            return;
        }

        $builder->where($column, $tenantId);
    }
}
