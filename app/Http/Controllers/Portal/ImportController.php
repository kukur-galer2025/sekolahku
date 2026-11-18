<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Models\DataImport;
use App\Services\Import\ImportManager;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ImportController extends Controller
{
    public function __construct(private ImportManager $imports) {}

    public function index(): View
    {
        return view('portal.imports.index', [
            'history' => DataImport::query()->with('user:id,name')->latest()->paginate(20),
        ]);
    }

    public function upload(Request $request, string $type): RedirectResponse
    {
        abort_unless(array_key_exists($type, DataImport::TYPES), 404);

        $pdf = in_array($type, ImportManager::PDF_TYPES, true);
        $request->validate([
            'file' => ['required', 'file', 'max:'.ImportManager::MAX_KB, 'extensions:'.($pdf ? 'xlsx,csv,pdf' : 'xlsx,csv')],
        ], [
            'file.extensions' => $pdf
                ? 'Hanya file .xlsx, .csv, atau PDF jadwal (cetakan aSc Timetables).'
                : 'Hanya file .xlsx atau .csv. File .xls lama: buka di Excel lalu Simpan Sebagai .xlsx.',
            'file.max' => 'Ukuran file maksimal 10 MB.',
        ], ['file' => 'file']);

        // Impor jadwal dari PDF aSc hanya untuk paket Pro & All Service.
        $school = app(\App\Tenancy\TenantContext::class)->get();
        if (strtolower((string) $request->file('file')->getClientOriginalExtension()) === 'pdf' && ! \App\Support\Plan::allows($school?->plan, 'pdf_import')) {
            return back()->withErrors(['file' => 'Impor dari PDF aSc tersedia mulai paket '.\App\Support\Plan::label(\App\Support\Plan::cheapestWith('pdf_import')).'. Gunakan format Excel, atau upgrade paket di menu Langganan.']);
        }

        $import = $this->imports->upload($request->file('file'), $type, $request->user());

        return redirect()->route('portal.imports.show', $import);
    }

    public function show(Request $request, DataImport $dataImport): View
    {
        if ($dataImport->status === 'preview' && $request->query('mode') && $dataImport->type === 'students') {
            $this->imports->refreshPreview($dataImport, $request->query('mode') === 'replace' ? 'replace' : 'add');
        }

        return view('portal.imports.show', ['import' => $dataImport->load('user:id,name')]);
    }

    public function confirm(Request $request, DataImport $dataImport): RedirectResponse
    {
        $data = $request->validate(['mode' => ['required', Rule::in(['add', 'replace'])]]);
        $mode = $dataImport->type === 'students' ? $data['mode'] : 'add';

        $this->imports->confirm($dataImport, $mode);

        return redirect()->route('portal.imports.show', $dataImport);
    }

    /** Daftar akun baru + password awal (CSV, titik koma agar langsung rapi di Excel Indonesia). */
    public function credentials(DataImport $dataImport): StreamedResponse
    {
        abort_unless($dataImport->credentials, 404, 'Daftar akun sudah tidak tersedia (dihapus otomatis setelah 7 hari).');
        $name = 'akun-'.$dataImport->type.'-'.$dataImport->created_at->format('Ymd-His').'.csv';

        return response()->streamDownload(function () use ($dataImport) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['Peran', 'Username', 'Nama', 'Kelas', 'Password awal'], ';');
            foreach ($dataImport->credentials as $row) {
                fputcsv($out, $row, ';');
            }
            fclose($out);
        }, $name, ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'no-store']);
    }

    /** Kartu aktivasi siap cetak (8 per A4): satu kartu per akun baru, dibagikan per orang — jangan difoto ke grup. */
    /** Excel Data Guru terisi dari PDF jadwal: admin tinggal mengisi NIK lalu mengunggahnya di kartu Data guru. */
    public function teacherSheet(DataImport $dataImport): \Symfony\Component\HttpFoundation\BinaryFileResponse
    {
        abort_unless($dataImport->type === 'teachers' && str_ends_with(strtolower($dataImport->file_path), '.pdf'), 404);

        return response()->download($this->imports->teacherSheet($dataImport), 'Data_Guru_dari_PDF.xlsx', ['Cache-Control' => 'no-store'])->deleteFileAfterSend();
    }

    public function cards(DataImport $dataImport, \App\Tenancy\TenantContext $tenant): \Illuminate\View\View
    {
        abort_unless($dataImport->credentials, 404, 'Daftar akun sudah tidak tersedia (dihapus otomatis setelah 7 hari).');
        $s = $tenant->get();
        $cards = collect($dataImport->credentials)->map(function ($r) use ($s) {
            $app = $r[0] === 'Guru' ? 'guru' : 'siswa';

            return ['role' => $r[0], 'username' => $r[1], 'name' => $r[2], 'class' => $r[3], 'password' => $r[4],
                'app' => \App\Http\Controllers\Portal\PwaController::APPS[$app]['label'], 'url' => $s->url($app)];
        });
        $qr = $cards->pluck('url')->unique()->mapWithKeys(fn ($u) => [$u => \App\Http\Controllers\Portal\PwaController::qr($u)]);

        return view('portal.imports.cards', ['school' => $s, 'cards' => $cards, 'qr' => $qr]);
    }

    public function template(string $type): BinaryFileResponse
    {
        $t = ImportManager::template($type);
        abort_unless($t && is_file($t[0]), 404);

        return response()->download($t[0], $t[1]);
    }
}
