<?php

namespace App\Http\Controllers\Panel;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\PayrollItem;
use App\Models\PayrollRun;
use App\Services\Platform\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Panel Parama Tech: penggajian karyawan ParamaEdu.Tech.
 * Kolom & rumus mengikuti "Template Tabel Penggajian Karyawan": total pendapatan = gaji pokok + tunjangan + lembur + bonus;
 * total potongan = BPJS TK + BPJS Kes + PPh 21 + potongan lain; gaji bersih = pendapatan − potongan.
 * Nominal BPJS/PPh 21 diisi manual (tidak dihitung otomatis) karena bergantung pada kondisi tiap karyawan.
 */
class PayrollController extends Controller
{
    private const TZ = 'Asia/Jakarta';

    public function __construct(private AuditLogger $audit) {}

    // ── Periode ─────────────────────────────────────────────────────

    public function index(): View
    {
        $runs = PayrollRun::query()->with('items')->orderByDesc('year')->orderByDesc('month')->get();
        $now = now(self::TZ);
        $year = (int) $now->year;
        $ytd = $runs->where('year', $year)->sum(fn ($r) => $r->summary($r->items)['net']);

        return view('panel.payroll.index', [
            'runs' => $runs, 'ytd' => $ytd, 'year' => $year,
            'activeCount' => Employee::where('active', true)->count(),
            'next' => ['month' => (int) $now->month, 'year' => $year],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $d = $request->validate([
            'month' => ['required', 'integer', 'between:1,12'],
            'year' => ['required', 'integer', 'between:2020,2100'],
            'pay_date' => ['nullable', 'date'],
            'work_days' => ['required', 'integer', 'between:0,31'],
            'source' => ['required', 'string'],
        ], [], ['month' => 'bulan', 'year' => 'tahun', 'pay_date' => 'tanggal pembayaran', 'work_days' => 'hari kerja']);

        if (PayrollRun::where('year', $d['year'])->where('month', $d['month'])->exists()) {
            return back()->withInput()->withErrors(['month' => 'Periode '.PayrollRun::MONTHS[$d['month']].' '.$d['year'].' sudah ada.']);
        }

        $run = DB::transaction(function () use ($d, $request) {
            $run = PayrollRun::create(['year' => $d['year'], 'month' => $d['month'], 'pay_date' => $d['pay_date'] ?? null,
                'work_days' => $d['work_days'], 'created_by' => $request->user()->id]);

            if (str_starts_with($d['source'], 'run:')) {
                // Salin dari periode lain: komponen tetap, lembur/bonus & status pembayaran dikosongkan.
                $from = PayrollRun::findOrFail((int) substr($d['source'], 4));
                foreach ($from->items as $i) {
                    $new = $i->replicate(['payroll_run_id', 'paid_on', 'method', 'pay_status', 'paid_amount', 'note', 'overtime', 'bonus']);
                    $new->fill(['payroll_run_id' => $run->id, 'work_days' => $run->work_days, 'present_days' => $run->work_days,
                        'overtime' => 0, 'bonus' => 0, 'pay_status' => 'belum', 'paid_amount' => 0]);
                    $new->recalc()->save();
                }
            } elseif ($d['source'] === 'employees') {
                foreach (Employee::where('active', true)->orderBy('name')->get() as $e) {
                    $this->itemFromEmployee($run, $e)->save();
                }
            }

            return $run;
        });

        $this->audit->log('payroll.run_created', 'Periode gaji '.$run->label().' dibuat ('.$run->items()->count().' karyawan)', null, $request->user());

        return redirect()->route('panel.payroll.show', $run->id)->with('ok', 'Periode '.$run->label().' dibuat. Lengkapi lembur, bonus, potongan, lalu simpan.');
    }

    public function show(PayrollRun $run): View
    {
        $items = $run->items()->get();
        $inRun = $items->pluck('employee_id')->filter()->all();

        return view('panel.payroll.show', [
            'run' => $run, 'items' => $items, 'sum' => $run->summary($items),
            'available' => Employee::where('active', true)->whereNotIn('id', $inRun)->orderBy('name')->get(['id', 'nik', 'name']),
        ]);
    }

    /** Simpan seluruh tabel sekaligus (header periode + baris karyawan). */
    public function update(Request $request, PayrollRun $run): RedirectResponse
    {
        $money = array_keys(PayrollItem::EARNINGS + PayrollItem::DEDUCTIONS);
        $request->merge(['items' => collect($request->input('items', []))->map(function ($row) use ($money) {
            foreach ([...$money, 'paid_amount'] as $k) {
                if (isset($row[$k])) {
                    $row[$k] = self::money($row[$k]);
                }
            }

            return $row;
        })->all()]);

        $rules = [
            'pay_date' => ['nullable', 'date'], 'work_days' => ['required', 'integer', 'between:0,31'],
            'company' => ['required', 'string', 'max:120'], 'notes' => ['nullable', 'string', 'max:200'],
            'items' => ['array'],
            'items.*.nik' => ['required', 'string', 'max:40'], 'items.*.name' => ['required', 'string', 'max:120'],
            'items.*.position' => ['nullable', 'string', 'max:80'], 'items.*.work_status' => ['nullable', Rule::in(array_keys(Employee::STATUSES))],
            'items.*.work_days' => ['nullable', 'integer', 'between:0,31'], 'items.*.present_days' => ['nullable', 'integer', 'between:0,31'],
            'items.*.paid_on' => ['nullable', 'date'], 'items.*.method' => ['nullable', Rule::in(array_keys(PayrollItem::METHODS))],
            'items.*.pay_status' => ['required', Rule::in(array_keys(PayrollItem::PAY_STATUSES))],
            'items.*.paid_amount' => ['nullable', 'integer', 'min:0', 'max:10000000000'],
            'items.*.note' => ['nullable', 'string', 'max:200'],
        ];
        foreach ($money as $k) {
            $rules["items.*.$k"] = ['nullable', 'integer', 'min:0', 'max:10000000000'];
        }
        $d = $request->validate($rules, [], ['items.*.name' => 'nama karyawan', 'items.*.nik' => 'NIK/ID', 'work_days' => 'hari kerja']);

        DB::transaction(function () use ($run, $d, $money) {
            $run->update(['pay_date' => $d['pay_date'] ?? null, 'work_days' => $d['work_days'], 'company' => $d['company'], 'notes' => $d['notes'] ?? null]);
            $items = $run->items()->get()->keyBy('id');
            foreach ($d['items'] ?? [] as $id => $row) {
                $item = $items->get((int) $id);
                if (! $item) {
                    continue;
                }
                $fill = ['nik' => $row['nik'], 'name' => $row['name'], 'position' => $row['position'] ?? null, 'work_status' => $row['work_status'] ?? null,
                    'work_days' => (int) ($row['work_days'] ?? 0), 'present_days' => (int) ($row['present_days'] ?? 0),
                    'paid_on' => $row['paid_on'] ?? null, 'method' => $row['method'] ?? null, 'pay_status' => $row['pay_status'],
                    'paid_amount' => (int) ($row['paid_amount'] ?? 0), 'note' => $row['note'] ?? null];
                foreach ($money as $k) {
                    $fill[$k] = (int) ($row[$k] ?? 0);
                }
                // Tanggal bayar otomatis = tanggal pembayaran periode bila status dibayar tapi tanggal kosong.
                if ($fill['pay_status'] !== 'belum' && ! $fill['paid_on']) {
                    $fill['paid_on'] = $run->pay_date?->toDateString();
                }
                $item->fill($fill)->recalc()->save();
            }
        });

        $s = $run->summary();
        $this->audit->log('payroll.run_updated', 'Gaji '.$run->label().' disimpan · bersih '.self::rp($s['net']).' · sudah dibayar '.self::rp($s['paid']), null, $request->user());

        return back()->with('ok', 'Tabel gaji '.$run->label().' disimpan.');
    }

    public function destroy(Request $request, PayrollRun $run): RedirectResponse
    {
        $label = $run->label();
        $run->delete();
        $this->audit->log('payroll.run_deleted', "Periode gaji $label dihapus", null, $request->user());

        return redirect()->route('panel.payroll')->with('ok', "Periode $label dihapus.");
    }

    public function addItems(Request $request, PayrollRun $run): RedirectResponse
    {
        $d = $request->validate(['employee_ids' => ['required', 'array', 'min:1'], 'employee_ids.*' => ['integer', 'exists:employees,id']], [], ['employee_ids' => 'karyawan']);
        $have = $run->items()->pluck('employee_id')->filter()->all();
        $n = 0;
        foreach (Employee::whereIn('id', $d['employee_ids'])->whereNotIn('id', $have)->get() as $e) {
            $this->itemFromEmployee($run, $e)->save();
            $n++;
        }

        return back()->with('ok', "$n karyawan ditambahkan ke periode {$run->label()}.");
    }

    public function removeItem(PayrollRun $run, PayrollItem $item): RedirectResponse
    {
        abort_unless($item->payroll_run_id === $run->id, 404);
        $item->delete();

        return back()->with('ok', "{$item->name} dihapus dari periode {$run->label()}.");
    }

    /** Impor baris dari file Template Tabel Penggajian (sheet "Penggajian", data mulai baris 9). */
    public function import(Request $request, PayrollRun $run): RedirectResponse
    {
        $request->validate(['file' => ['required', 'file', 'max:5120']], [], ['file' => 'file Excel']);
        // Ekstensi diperiksa langsung (deteksi MIME .xlsx tidak konsisten antar-server: kadang terbaca application/zip).
        if (strtolower($request->file('file')->getClientOriginalExtension()) !== 'xlsx') {
            return back()->withErrors(['file' => 'Pilih file Excel .xlsx (Template Tabel Penggajian Karyawan).']);
        }
        $reader = new \OpenSpout\Reader\XLSX\Reader;
        try {
            $reader->open($request->file('file')->getRealPath());
        } catch (\Throwable) {
            return back()->withErrors(['file' => 'File Excel tidak bisa dibaca. Simpan ulang sebagai .xlsx lalu coba lagi.']);
        }
        $rows = [];
        foreach ($reader->getSheetIterator() as $sheet) {
            // Data dimulai setelah baris judul kolom ("Nama Karyawan" di kolom C); cadangan: baris ke-9 dst.
            $started = false;
            foreach ($sheet->getRowIterator() as $n => $row) {
                $v = array_map(fn ($c) => $c instanceof \DateTimeInterface ? $c->format('Y-m-d') : $c, $row->toArray());
                $name = trim((string) ($v[2] ?? ''));
                if (! $started) {
                    $started = mb_strtolower($name) === 'nama karyawan';
                    if (! $started && $n >= 9 && $name !== '' && is_numeric($v[7] ?? null)) {
                        $started = true; // tanpa baris judul: anggap baris data
                    } else {
                        continue;
                    }
                }
                if ($name === '') {
                    continue; // kolom C = nama karyawan
                }
                $rows[] = $v;
            }
            break; // hanya sheet pertama ("Penggajian")
        }
        $reader->close();
        if (! $rows) {
            return back()->withErrors(['file' => 'Tidak ada data karyawan di file. Pastikan memakai Template Tabel Penggajian (nama karyawan di kolom C mulai baris 9).']);
        }

        $statusOf = fn ($s) => match (mb_strtolower(trim((string) $s))) {
            'dibayar', 'lunas' => 'dibayar', 'dp/parsial', 'dp', 'parsial' => 'parsial', default => 'belum',
        };
        $methodOf = fn ($m) => match (true) {
            str_contains(mb_strtolower((string) $m), 'transfer') => 'transfer', str_contains(mb_strtolower((string) $m), 'tunai') => 'tunai',
            trim((string) $m) === '' => null, default => 'lainnya',
        };
        $statusKerja = fn ($s) => collect(Employee::STATUSES)->search(fn ($l, $k) => mb_strtolower(trim((string) $s)) === mb_strtolower($l) || mb_strtolower(trim((string) $s)) === $k) ?: null;

        $n = 0;
        DB::transaction(function () use ($rows, $run, $statusOf, $methodOf, $statusKerja, &$n) {
            foreach ($rows as $v) {
                $nik = trim((string) ($v[1] ?? '')) ?: 'TMP-'.($n + 1);
                $emp = Employee::where('nik', $nik)->first();
                $item = $run->items()->where('nik', $nik)->first() ?? new PayrollItem(['payroll_run_id' => $run->id]);
                $item->fill([
                    'employee_id' => $emp?->id, 'nik' => mb_substr($nik, 0, 40), 'name' => mb_substr(trim((string) $v[2]), 0, 120),
                    'position' => mb_substr(trim((string) ($v[3] ?? '')), 0, 80) ?: null, 'work_status' => $statusKerja($v[4] ?? ''),
                    'work_days' => min(31, (int) ($v[5] ?? 0)), 'present_days' => min(31, (int) ($v[6] ?? 0)),
                    'base_salary' => self::money($v[7] ?? 0), 'allowance_position' => self::money($v[8] ?? 0), 'allowance_meal' => self::money($v[9] ?? 0),
                    'allowance_transport' => self::money($v[10] ?? 0), 'overtime' => self::money($v[11] ?? 0), 'bonus' => self::money($v[12] ?? 0),
                    'bpjs_tk' => self::money($v[14] ?? 0), 'bpjs_kes' => self::money($v[15] ?? 0), 'pph21' => self::money($v[16] ?? 0), 'other_deduction' => self::money($v[17] ?? 0),
                    'paid_on' => self::date($v[20] ?? null), 'method' => $methodOf($v[21] ?? ''), 'pay_status' => $statusOf($v[22] ?? ''),
                ]);
                $item->recalc()->save();
                $n++;
            }
        });
        $this->audit->log('payroll.imported', "Impor Excel ke gaji {$run->label()}: $n baris", null, $request->user());

        return back()->with('ok', "$n baris gaji diimpor dari Excel. Periksa lalu simpan bila ada perubahan.");
    }

    /** Unduh XLSX berformat Template Tabel Penggajian Karyawan (sheet Penggajian + Rekap). */
    public function export(Request $request, PayrollRun $run): BinaryFileResponse
    {
        $items = $run->items()->get();
        $s = $run->summary($items);
        $tmp = tempnam(sys_get_temp_dir(), 'gaji').'.xlsx';
        $w = new \OpenSpout\Writer\XLSX\Writer;
        $w->openToFile($tmp);
        $bold = (new \OpenSpout\Common\Entity\Style\Style)->setFontBold();
        $head = (new \OpenSpout\Common\Entity\Style\Style)->setFontBold()->setFontColor('FFFFFF')->setBackgroundColor('0B63E5')->setShouldWrapText(true);
        $title = (new \OpenSpout\Common\Entity\Style\Style)->setFontBold()->setFontSize(14);
        $row = fn (array $v, $st = null) => $w->addRow(\OpenSpout\Common\Entity\Row::fromValues($v, $st));

        $w->getCurrentSheet()->setName('Penggajian');
        $row(['PARAMAEDU.TECH'], $title);
        $row(['TABEL PENGGAJIAN KARYAWAN'], $bold);
        $row([]);
        $row(['Perusahaan', $run->company, '', '', '', 'Periode Bulan', PayrollRun::MONTHS[$run->month], '', '', 'Tahun', $run->year, '', '', 'Tanggal Pembayaran', $run->pay_date?->format('d-m-Y') ?? '']);
        $row([]);
        $row(['Dibuat dari Panel MyPresence · '.now(self::TZ)->format('d-m-Y H:i').' WIB. Data gaji bersifat rahasia.']);
        $row([]);
        $row(['No', 'NIK / ID Karyawan', 'Nama Karyawan', 'Jabatan', 'Status Kerja', 'Hari Kerja', 'Hari Hadir', 'Gaji Pokok', 'Tunjangan Jabatan', 'Tunjangan Makan',
            'Tunjangan Transport', 'Lembur', 'Bonus / Insentif', 'Total Pendapatan', 'BPJS Ketenagakerjaan', 'BPJS Kesehatan', 'PPh 21', 'Potongan Lain',
            'Total Potongan', 'Gaji Bersih', 'Tanggal Bayar', 'Metode Pembayaran', 'Status Pembayaran'], $head);
        foreach ($items->values() as $k => $i) {
            $row([$k + 1, $i->nik, $i->name, (string) $i->position, Employee::STATUSES[$i->work_status] ?? '', $i->work_days, $i->present_days,
                $i->base_salary, $i->allowance_position, $i->allowance_meal, $i->allowance_transport, $i->overtime, $i->bonus, $i->gross,
                $i->bpjs_tk, $i->bpjs_kes, $i->pph21, $i->other_deduction, $i->deductions, $i->net,
                $i->paid_on?->format('d-m-Y') ?? '', PayrollItem::METHODS[$i->method] ?? '', $i->statusLabel()]);
        }
        $row(['', '', 'TOTAL', '', '', '', '', ...array_map(fn ($c) => (int) $items->sum($c), ['base_salary', 'allowance_position', 'allowance_meal', 'allowance_transport', 'overtime', 'bonus', 'gross', 'bpjs_tk', 'bpjs_kes', 'pph21', 'other_deduction', 'deductions', 'net'])], $bold);

        $w->addNewSheetAndMakeItCurrent()->setName('Rekap');
        $row(['REKAP PENGGAJIAN'], $title);
        $row([]);
        foreach ([['Jumlah karyawan terdata', $s['count']], ['Total pendapatan bruto', $s['gross']], ['Total potongan', $s['deductions']], ['Total gaji bersih', $s['net']],
            ['Sudah dibayar', $s['paid']], ['Belum dibayar / parsial', $s['unpaid']], [], [], ['Periode', $run->label()]] as $r) {
            $row($r);
        }
        $w->close();
        $this->audit->log('payroll.exported', "Unduh Excel gaji {$run->label()}", null, $request->user());

        return response()->download($tmp, 'Penggajian_ParamaEduTech_'.$run->year.'_'.str_pad((string) $run->month, 2, '0', STR_PAD_LEFT).'.xlsx')->deleteFileAfterSend();
    }

    /** Slip gaji siap cetak (satu karyawan, atau semua bila $item kosong). */
    public function slip(PayrollRun $run, ?PayrollItem $item = null): View
    {
        abort_if($item && $item->payroll_run_id !== $run->id, 404);

        return view('panel.payroll.slip', ['run' => $run, 'items' => $item ? collect([$item]) : $run->items()->get(), 'seller' => config('billing.seller')]);
    }

    // ── Karyawan ────────────────────────────────────────────────────

    public function employees(Request $request): View
    {
        $edit = $request->integer('edit') ? Employee::find($request->integer('edit')) : null;

        return view('panel.payroll.employees', ['employees' => Employee::orderByDesc('active')->orderBy('name')->get(), 'edit' => $edit]);
    }

    public function saveEmployee(Request $request, ?Employee $employee = null): RedirectResponse
    {
        foreach (Employee::DEFAULTS as $k) {
            $request->merge([$k => self::money($request->input($k, 0))]);
        }
        $rules = [
            'nik' => ['required', 'string', 'max:40', Rule::unique('employees', 'nik')->ignore($employee?->id)],
            'name' => ['required', 'string', 'max:120'], 'position' => ['nullable', 'string', 'max:80'],
            'work_status' => ['required', Rule::in(array_keys(Employee::STATUSES))], 'join_date' => ['nullable', 'date'],
            'phone' => ['nullable', 'string', 'max:30'], 'bank_name' => ['nullable', 'string', 'max:60'],
            'bank_account' => ['nullable', 'string', 'max:40'], 'bank_holder' => ['nullable', 'string', 'max:120'],
            'active' => ['nullable', 'boolean'], 'notes' => ['nullable', 'string', 'max:200'],
        ];
        foreach (Employee::DEFAULTS as $k) {
            $rules[$k] = ['integer', 'min:0', 'max:10000000000'];
        }
        $d = $request->validate($rules, [], ['nik' => 'NIK/ID karyawan', 'name' => 'nama']);
        $d['active'] = $request->boolean('active');

        if ($employee) {
            $employee->update($d);
            $msg = "Data {$employee->name} diperbarui.";
        } else {
            $employee = Employee::create($d);
            $msg = "Karyawan {$employee->name} ditambahkan.";
        }
        $this->audit->log('payroll.employee_saved', "Data karyawan {$employee->name} ({$employee->nik}) disimpan", null, $request->user());

        return redirect()->route('panel.payroll.employees')->with('ok', $msg);
    }

    public function destroyEmployee(Request $request, Employee $employee): RedirectResponse
    {
        // Riwayat gaji tetap utuh (baris gaji menyimpan salinan nama/NIK).
        $employee->delete();
        $this->audit->log('payroll.employee_deleted', "Karyawan {$employee->name} ({$employee->nik}) dihapus", null, $request->user());

        return redirect()->route('panel.payroll.employees')->with('ok', "Karyawan {$employee->name} dihapus. Riwayat gajinya tetap tersimpan.");
    }

    // ── bantuan ──

    private function itemFromEmployee(PayrollRun $run, Employee $e): PayrollItem
    {
        $item = new PayrollItem(['payroll_run_id' => $run->id, 'employee_id' => $e->id, 'nik' => $e->nik, 'name' => $e->name, 'position' => $e->position,
            'work_status' => $e->work_status, 'work_days' => $run->work_days, 'present_days' => $run->work_days, 'pay_status' => 'belum']);
        foreach (Employee::DEFAULTS as $k) {
            $item->{$k} = $e->{$k};
        }

        return $item->recalc();
    }

    /** "1.500.000", "Rp 1.500.000", 1500000.0 → 1500000. */
    public static function money(mixed $v): int
    {
        if (is_int($v) || is_float($v)) {
            return max(0, (int) round($v));
        }

        return (int) preg_replace('/\D/', '', explode(',', (string) $v)[0]);
    }

    private static function date(mixed $v): ?string
    {
        if (! $v) {
            return null;
        }
        if (is_numeric($v)) { // nomor seri tanggal Excel
            return \Illuminate\Support\Carbon::create(1899, 12, 30)->addDays((int) $v)->toDateString();
        }
        try {
            return \Illuminate\Support\Carbon::parse(str_replace('/', '-', (string) $v))->toDateString();
        } catch (\Throwable) {
            return null;
        }
    }

    private static function rp(int $n): string
    {
        return 'Rp'.number_format($n, 0, ',', '.');
    }
}
