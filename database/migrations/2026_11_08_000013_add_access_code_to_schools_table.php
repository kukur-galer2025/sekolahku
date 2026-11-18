<?php

use App\Support\SchoolCode;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Kode sekolah untuk satu pintu masuk guru & siswa ({domain pusat}/app → kode → NIK/NIS + password).
 * Sekolah yang sudah ada langsung dibuatkan kode acak; super-admin bisa menggantinya di panel.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('schools', function (Blueprint $t) {
            $t->string('access_code', 12)->nullable()->unique()->after('subdomain');
        });

        $used = [];
        foreach (DB::table('schools')->whereNull('access_code')->orderBy('id')->pluck('id') as $id) {
            do {
                $code = SchoolCode::generate();
            } while (isset($used[$code]) || DB::table('schools')->where('access_code', $code)->exists());
            $used[$code] = true;
            DB::table('schools')->where('id', $id)->update(['access_code' => $code]);
        }
    }

    public function down(): void
    {
        Schema::table('schools', function (Blueprint $t) {
            $t->dropUnique(['access_code']);
            $t->dropColumn('access_code');
        });
    }
};
