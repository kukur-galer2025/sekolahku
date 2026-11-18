<?php

namespace Tests\Feature\Platform;

use App\Enums\Role;
use App\Models\Employee;
use App\Models\PayrollItem;
use App\Models\PayrollRun;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class PayrollTest extends TestCase
{
    private function central(string $path): string
    {
        return 'http://'.config('tenancy.central_domain').'/'.ltrim($path, '/');
    }

    private function superadmin(): User
    {
        $u = User::factory()->create(['role' => Role::SuperAdmin, 'username' => 'superadmin', 'password' => 'SuperRahasia1']);
        $u->forceFill(['two_factor_secret' => 'JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXP', 'two_factor_confirmed_at' => now()])->save();

        return $u;
    }

    public function test_alur_penggajian_karyawan(): void
    {
        $super = $this->superadmin();
        $this->actingAs($super)->get($this->central('panel/penggajian'))->assertOk()->assertSee('Penggajian karyawan');

        // Tambah karyawan dengan format rupiah bertitik.
        $this->actingAs($super)->post($this->central('panel/penggajian/karyawan'), [
            'nik' => 'PET-001', 'name' => 'Andi Pratama', 'position' => 'Customer Success', 'work_status' => 'tetap', 'active' => 1,
            'base_salary' => '4.000.000', 'allowance_meal' => '500.000', 'allowance_transport' => '300.000', 'bpjs_kes' => '40.000',
        ])->assertRedirect()->assertSessionHas('ok');
        $this->actingAs($super)->post($this->central('panel/penggajian/karyawan'), ['nik' => 'PET-002', 'name' => 'Sari', 'work_status' => 'kontrak', 'active' => 1, 'base_salary' => '3.000.000']);
        $this->actingAs($super)->post($this->central('panel/penggajian/karyawan'), ['nik' => 'PET-003', 'name' => 'Nonaktif', 'work_status' => 'magang', 'base_salary' => '1']);
        $andi = Employee::where('nik', 'PET-001')->firstOrFail();
        $this->assertSame(4000000, $andi->base_salary);
        $this->assertFalse(Employee::where('nik', 'PET-003')->first()->active);
        // NIK unik.
        $this->actingAs($super)->post($this->central('panel/penggajian/karyawan'), ['nik' => 'PET-001', 'name' => 'Dobel', 'work_status' => 'tetap'])->assertSessionHasErrors('nik');

        // Periode dari karyawan aktif.
        $this->actingAs($super)->post($this->central('panel/penggajian'), ['month' => 11, 'year' => 2026, 'pay_date' => '2026-11-25', 'work_days' => 21, 'source' => 'employees'])->assertRedirect();
        $run = PayrollRun::where('year', 2026)->where('month', 11)->firstOrFail();
        $this->assertSame(2, $run->items()->count());
        $item = $run->items()->where('nik', 'PET-001')->first();
        $this->assertSame(4800000, $item->gross);
        $this->assertSame(4760000, $item->net);
        // Periode ganda ditolak.
        $this->actingAs($super)->post($this->central('panel/penggajian'), ['month' => 11, 'year' => 2026, 'work_days' => 21, 'source' => 'employees'])->assertSessionHasErrors('month');

        // Simpan tabel: lembur, PPh 21, status dibayar.
        $rows = [];
        foreach ($run->items as $i) {
            $rows[$i->id] = ['nik' => $i->nik, 'name' => $i->name, 'work_status' => $i->work_status, 'work_days' => 21, 'present_days' => 20,
                'base_salary' => number_format($i->base_salary, 0, ',', '.'), 'allowance_meal' => $i->allowance_meal, 'allowance_transport' => $i->allowance_transport,
                'overtime' => $i->nik === 'PET-001' ? '250.000' : 0, 'bpjs_kes' => $i->bpjs_kes, 'pph21' => $i->nik === 'PET-001' ? '50.000' : 0,
                'pay_status' => $i->nik === 'PET-001' ? 'dibayar' : 'parsial', 'paid_amount' => $i->nik === 'PET-001' ? 0 : '1.000.000', 'method' => 'transfer'];
        }
        $this->actingAs($super)->put($this->central('panel/penggajian/'.$run->id), ['company' => 'ParamaEdu.Tech', 'pay_date' => '2026-11-25', 'work_days' => 21, 'items' => $rows])
            ->assertSessionHas('ok');
        $item->refresh();
        $this->assertSame(5050000, $item->gross);
        $this->assertSame(90000, $item->deductions);
        $this->assertSame(4960000, $item->net);
        $this->assertSame('2026-11-25', $item->paid_on->toDateString());
        $s = $run->fresh()->summary();
        $this->assertSame(4960000 + 1000000, $s['paid']);
        $this->assertSame(2000000, $s['unpaid']);

        $this->actingAs($super)->get($this->central('panel/penggajian/'.$run->id))->assertOk()->assertSee('Andi Pratama')->assertSee('4.960.000');
        $this->actingAs($super)->get($this->central('panel/penggajian/'.$run->id.'/slip/'.$item->id))->assertOk()->assertSee('SLIP GAJI')->assertSee('Rp4.960.000');
        $this->actingAs($super)->get($this->central('panel/penggajian/'.$run->id.'/slip'))->assertOk()->assertSee('Sari');
        $this->actingAs($super)->get($this->central('panel/penggajian/'.$run->id.'/unduh'))->assertOk()->assertDownload('Penggajian_ParamaEduTech_2026_11.xlsx');

        // Salin ke bulan berikut: status pembayaran & lembur direset.
        $this->actingAs($super)->post($this->central('panel/penggajian'), ['month' => 12, 'year' => 2026, 'work_days' => 22, 'source' => 'run:'.$run->id]);
        $dec = PayrollRun::where('month', 12)->firstOrFail()->items()->where('nik', 'PET-001')->first();
        $this->assertSame('belum', $dec->pay_status);
        $this->assertSame(0, $dec->overtime);
        $this->assertSame(50000, $dec->pph21);

        // Hapus karyawan: riwayat gaji tetap ada.
        $this->actingAs($super)->delete($this->central('panel/penggajian/karyawan/'.$andi->id))->assertSessionHas('ok');
        $this->assertNull($item->fresh()->employee_id);
        $this->assertSame('Andi Pratama', $item->fresh()->name);
    }

    public function test_impor_template_excel(): void
    {
        $super = $this->superadmin();
        $run = PayrollRun::create(['year' => 2026, 'month' => 10, 'work_days' => 22]);
        $path = tempnam(sys_get_temp_dir(), 'tpl').'.xlsx';
        $w = new \OpenSpout\Writer\XLSX\Writer;
        $w->openToFile($path);
        for ($r = 1; $r <= 8; $r++) {
            $w->addRow(\OpenSpout\Common\Entity\Row::fromValues($r === 8 ? ['No', 'NIK / ID Karyawan', 'Nama Karyawan'] : ['x']));
        }
        $w->addRow(\OpenSpout\Common\Entity\Row::fromValues([1, 'K-9', 'Budi', 'Teknisi', 'Kontrak', 22, 21, 3500000, 0, 400000, 200000, 0, 100000, null, 0, 35000, 0, 0, null, null, '25/10/2026', 'Transfer Bank', 'Dibayar']));
        $w->addRow(\OpenSpout\Common\Entity\Row::fromValues(['', '', '']));
        $w->close();

        $file = new UploadedFile($path, 'gaji.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
        $this->actingAs($super)->post($this->central('panel/penggajian/'.$run->id.'/impor'), ['file' => $file])->assertSessionHas('ok');
        $i = PayrollItem::where('payroll_run_id', $run->id)->firstOrFail();
        $this->assertSame('Budi', $i->name);
        $this->assertSame('kontrak', $i->work_status);
        $this->assertSame(4200000, $i->gross);
        $this->assertSame(4165000, $i->net);
        $this->assertSame('dibayar', $i->pay_status);
        $this->assertSame('transfer', $i->method);
        $this->assertSame('2026-10-25', $i->paid_on->toDateString());
    }

    public function test_hanya_superadmin(): void
    {
        $s = $this->makeSchool();
        $this->tenant()->forget();
        $this->actingAs($s['admin'])->get($this->central('panel/penggajian'))->assertStatus(403);
    }
}
