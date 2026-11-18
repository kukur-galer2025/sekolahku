<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Struktur kelas diperluas: sekretaris & bendahara kini 1 dan 2, ditambah seksi-seksi.
 * Jabatan lama "sekretaris" / "bendahara" dipindahkan ke "…_1". Kolom tetap string(16).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('students')->where('class_role', 'sekretaris')->update(['class_role' => 'sekretaris_1']);
        DB::table('students')->where('class_role', 'bendahara')->update(['class_role' => 'bendahara_1']);
    }

    public function down(): void
    {
        DB::table('students')->where('class_role', 'sekretaris_1')->update(['class_role' => 'sekretaris']);
        DB::table('students')->where('class_role', 'bendahara_1')->update(['class_role' => 'bendahara']);
        DB::table('students')->whereNotIn('class_role', ['ketua', 'wakil', 'sekretaris', 'bendahara'])->update(['class_role' => null]);
    }
};
