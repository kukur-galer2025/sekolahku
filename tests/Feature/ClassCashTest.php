<?php

namespace Tests\Feature;

use App\Models\ClassCashEntry;
use App\Models\School;
use Tests\TestCase;

/** Kas Kelas: bendahara mencatat, siswa sekelas melihat, wali kelas memantau, paket membatasi. */
class ClassCashTest extends TestCase
{
    public function test_bendahara_mencatat_siswa_melihat_wali_memantau(): void
    {
        $s = $this->makeSchool(['subdomain' => 'smkn1']);
        School::query()->whereKey($s['school']->id)->update(['plan' => 'all_service']);
        $api = fn ($p) => $this->at($s['school'], 'api/'.$p);
        // fresh(): jabatan kelas (class_role) berubah di tengah tes; relasi student pada objek lama sudah ter-cache.
        $siswa = fn () => $this->fromDevice($s['deviceToken'])->actingAs($s['student']->user->fresh());
        $sid = $s['student']->id;

        // Belum bendahara: hanya melihat.
        $siswa()->getJson($api('class-cash'))->assertOk()->assertJsonPath('can_edit', false)->assertJsonPath('balance', 0);
        $siswa()->postJson($api('class-cash'), ['type' => 'masuk', 'category' => 'sumbangan', 'amount' => 5000, 'date' => now('Asia/Jakarta')->toDateString()])->assertForbidden();

        // Wali kelas memilih bendahara.
        $this->tenant()->run($s['school'], fn () => $s['classroom']->update(['homeroom_teacher_id' => $s['teacher']->id]));
        $this->tenant()->forget();
        $this->actingAs($s['teacher']->user)->putJson($api("students/$sid/class-role"), ['class_role' => 'bendahara_1'])->assertOk();

        $today = now('Asia/Jakarta')->toDateString();
        $siswa()->putJson($api('class-cash/settings'), ['dues_amount' => 5000, 'dues_period' => 'minggu'])->assertOk()->assertJsonPath('dues.amount', 5000);
        $siswa()->postJson($api('class-cash'), ['type' => 'masuk', 'category' => 'iuran', 'amount' => 5000, 'date' => $today])->assertStatus(422); // tanpa siswa
        $siswa()->postJson($api('class-cash'), ['type' => 'masuk', 'category' => 'iuran', 'amount' => 5000, 'date' => $today, 'student_ids' => [$sid]])
            ->assertCreated()->assertJsonPath('balance', 5000)->assertJsonPath('members.0.status', 'lunas');
        $siswa()->postJson($api('class-cash'), ['type' => 'keluar', 'category' => 'perlengkapan', 'amount' => 9000, 'date' => $today, 'description' => 'Spidol'])
            ->assertStatus(422)->assertJsonValidationErrors('amount'); // saldo kurang
        $siswa()->postJson($api('class-cash'), ['type' => 'keluar', 'category' => 'perlengkapan', 'amount' => 3000, 'date' => $today])->assertStatus(422); // keperluan wajib
        $siswa()->postJson($api('class-cash'), ['type' => 'keluar', 'category' => 'perlengkapan', 'amount' => 3000, 'date' => $today, 'description' => 'Spidol'])
            ->assertCreated()->assertJsonPath('balance', 2000)->assertJsonPath('month.out', 3000);
        $siswa()->postJson($api('class-cash'), ['type' => 'masuk', 'category' => 'sumbangan', 'amount' => 1000, 'date' => now('Asia/Jakarta')->addDay()->toDateString()])->assertStatus(422); // tanggal depan

        // Hapus pemasukan yang membuat saldo minus ditolak; pengeluaran boleh dihapus.
        $in = ClassCashEntry::query()->withoutGlobalScopes()->where('type', 'masuk')->value('id');
        $out = ClassCashEntry::query()->withoutGlobalScopes()->where('type', 'keluar')->value('id');
        $siswa()->deleteJson($api("class-cash/$in"))->assertStatus(422);
        $siswa()->deleteJson($api("class-cash/$out"))->assertOk()->assertJsonPath('balance', 5000);

        // Wali kelas: pantau saja.
        $cid = $s['classroom']->id;
        $this->actingAs($s['teacher']->user)->getJson($api("class-cash?classroom_id=$cid"))->assertOk()->assertJsonPath('can_edit', false)->assertJsonPath('treasurers.0', 'Aulia Rahma');
        $this->actingAs($s['teacher']->user)->postJson($api('class-cash'), ['type' => 'masuk'])->assertForbidden();
        $this->actingAs($s['teacher']->user)->get($api("class-cash/export?classroom_id=$cid"))->assertOk();
        $this->actingAs($s['admin'])->getJson($api("class-cash?classroom_id=$cid"))->assertOk()->assertJsonPath('balance', 5000);

        // Guru bukan wali kelas ditolak; paket Starter mengunci fitur.
        $other = $this->makeSchool(['subdomain' => 'smkn2']);
        School::query()->whereKey($other['school']->id)->update(['plan' => 'all_service']);
        $this->actingAs($other['teacher']->user)->withSession(['tenant_id' => $other['school']->id])
            ->getJson($this->at($other['school'], "api/class-cash?classroom_id=$cid"))->assertNotFound();
        $this->flushSession();
        School::query()->whereKey($s['school']->id)->update(['plan' => 'starter']);
        $siswa()->getJson($api('class-cash'))->assertForbidden()->assertJsonPath('code', 'plan_locked');
    }
}
