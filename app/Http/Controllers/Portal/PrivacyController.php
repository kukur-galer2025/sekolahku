<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Jobs\ExportSchoolData;
use App\Models\Classroom;
use App\Models\DataExport;
use App\Models\Student;
use App\Services\Platform\AuditLogger;
use App\Services\Platform\Legal;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantFiles;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Data & Privasi (admin sekolah): persetujuan DPA, persetujuan orang tua per siswa,
 * ekspor seluruh data, dan permintaan penghapusan data.
 */
class PrivacyController extends Controller
{
    public function __construct(private TenantContext $tenant, private AuditLogger $audit) {}

    // ---------- Perjanjian Pemrosesan Data ----------

    public function dpa(): View|RedirectResponse
    {
        $s = $this->tenant->get();

        return view('portal.privacy.dpa', ['school' => $s, 'accepted' => Legal::accepted($s->id, 'dpa'), 'version' => Legal::version('dpa')]);
    }

    public function acceptDpa(Request $request): RedirectResponse
    {
        $d = $request->validate([
            'signer_name' => ['required', 'string', 'max:120'],
            'signer_position' => ['required', 'string', 'max:120'],
            'authorized' => ['accepted'],
        ], ['authorized.accepted' => 'Centang pernyataan bahwa kamu berwenang mewakili sekolah.'], ['signer_name' => 'nama', 'signer_position' => 'jabatan']);

        $s = $this->tenant->get();
        Legal::record('dpa', $s, $request->user(), $d['signer_name'], $d['signer_position']);
        $this->audit->log('legal.dpa', "Perjanjian Pemrosesan Data v".Legal::version('dpa')." disetujui oleh {$d['signer_name']} ({$d['signer_position']})", $s);

        return redirect()->route('portal.dashboard')->with('ok', 'Terima kasih. Perjanjian Pemrosesan Data tercatat.');
    }

    // ---------- halaman Data & Privasi ----------

    public function index(TenantFiles $files): View
    {
        $s = $this->tenant->get();
        $byClass = Classroom::query()->orderBy('name')->get(['id', 'name'])->map(function ($c) {
            $q = Student::query()->where('classroom_id', $c->id)->where('is_active', true);

            return ['id' => $c->id, 'name' => $c->name, 'total' => (clone $q)->count(), 'consented' => (clone $q)->whereNotNull('parent_consent_at')->count()];
        })->filter(fn ($c) => $c['total'] > 0)->values();

        return view('portal.privacy.index', [
            'school' => $s,
            'dpa' => Legal::accepted($s->id, 'dpa'),
            'exports' => DataExport::query()->with('user:id,name')->latest()->limit(10)->get(),
            'byClass' => $byClass,
            'consentTotal' => [$byClass->sum('consented'), $byClass->sum('total')],
            'retention' => \App\Console\Commands\LifecycleDaily::retentionDays($s),
            'leftCount' => Student::query()->whereIn('status', ['lulus', 'mutasi_keluar', 'keluar'])->count(),
            'archivedCount' => Student::query()->where('status', 'arsip')->count(),
        ]);
    }

    /** Masa simpan data pribadi siswa yang sudah lulus/mutasi/keluar sebelum dianonimkan otomatis. */
    public function retention(Request $request): RedirectResponse
    {
        $d = $request->validate(['days' => ['required', 'integer', Rule::in(self::RETENTION_OPTIONS)]]);
        $s = $this->tenant->get();
        $s->forceFill(['settings' => array_replace_recursive($s->settings ?? [], ['lifecycle' => ['retention_days' => (int) $d['days']]])])->save();
        $this->audit->log('privacy.retention', "Masa simpan data siswa keluar/lulus diubah menjadi {$d['days']} hari", $s, $request->user());

        return back()->with('ok', "Masa simpan diubah: {$d['days']} hari setelah siswa keluar/lulus.");
    }

    public const RETENTION_OPTIONS = [180, 365, 730, 1095];

    public function consentForm(Request $request): View
    {
        $classroom = Classroom::findOrFail($request->integer('kelas'));

        return view('portal.privacy.consent', [
            'classroom' => $classroom,
            'students' => Student::query()->where('classroom_id', $classroom->id)->where('is_active', true)->orderBy('name')->get(),
        ]);
    }

    /** Catat bukti persetujuan orang tua/wali untuk beberapa siswa sekaligus. */
    public function recordConsent(Request $request): RedirectResponse
    {
        $d = $request->validate([
            'student_ids' => ['required', 'array', 'min:1'],
            'student_ids.*' => ['integer'],
            'method' => ['required', Rule::in(['kertas', 'elektronik'])],
            'ref' => ['required', 'string', 'max:200'],
            'consent_date' => ['required', 'date', 'before_or_equal:today'],
            'action' => ['required', Rule::in(['record', 'revoke'])],
        ], [], ['student_ids' => 'siswa', 'ref' => 'keterangan bukti', 'consent_date' => 'tanggal persetujuan']);

        $students = Student::query()->whereIn('id', $d['student_ids'])->get(); // ter-scope ke sekolah ini
        foreach ($students as $s) {
            $d['action'] === 'record'
                ? $s->update(['parent_consent_at' => $d['consent_date'], 'parent_consent_method' => $d['method'], 'parent_consent_ref' => $d['ref'], 'parent_consent_by' => $request->user()->id])
                : $s->update(['parent_consent_at' => null, 'parent_consent_method' => null, 'parent_consent_ref' => 'Ditarik: '.$d['ref'], 'parent_consent_by' => $request->user()->id]);
        }
        $this->audit->log('consent.'.$d['action'], ($d['action'] === 'record' ? 'Persetujuan orang tua dicatat' : 'Persetujuan orang tua ditarik').' untuk '.$students->count().' siswa ('.$d['method'].': '.$d['ref'].')', $this->tenant->get());

        return redirect()->route('portal.privacy')->with('ok', 'Persetujuan orang tua '.($d['action'] === 'record' ? 'dicatat' : 'ditandai ditarik').' untuk '.$students->count().' siswa.');
    }

    public function export(Request $request): RedirectResponse
    {
        abort_if(DataExport::query()->where('status', 'queued')->exists(), 422, 'Ekspor sebelumnya masih diproses.');
        $e = DataExport::create(['user_id' => $request->user()->id, 'status' => 'queued']);
        ExportSchoolData::dispatch($e->id, $this->tenant->id());
        $this->audit->log('data.exported', 'Ekspor seluruh data sekolah diminta', $this->tenant->get());

        return back()->with('ok', 'Ekspor sedang disiapkan. Muat ulang halaman ini dalam beberapa saat.');
    }

    public function downloadExport(DataExport $dataExport, TenantFiles $files)
    {
        abort_unless($dataExport->status === 'done' && $files->owns($dataExport->file_path), 404, 'Berkas ekspor sudah tidak tersedia.');

        return $files->disk()->download($dataExport->file_path, 'ekspor-'.$this->tenant->get()->subdomain.'-'.$dataExport->created_at->format('Ymd-His').'.xlsx');
    }

    public function requestDeletion(Request $request): RedirectResponse
    {
        $d = $request->validate(['reason' => ['required', 'string', 'max:255'], 'confirm' => ['accepted']], ['confirm.accepted' => 'Centang pernyataan bahwa data sudah diekspor bila diperlukan.']);
        $s = $this->tenant->get();
        $s->forceFill(['deletion_requested_at' => now(), 'deletion_reason' => $d['reason']])->save();
        $this->audit->log('data.deletion_requested', 'Admin meminta penghapusan seluruh data sekolah. Alasan: '.$d['reason'], $s);

        return back()->with('ok', 'Permintaan penghapusan tercatat. Tim Parama Tech akan menghubungi admin untuk konfirmasi sebelum data dihapus.');
    }

    public function cancelDeletion(): RedirectResponse
    {
        $s = $this->tenant->get();
        $s->forceFill(['deletion_requested_at' => null, 'deletion_reason' => null])->save();
        $this->audit->log('data.deletion_cancelled', 'Permintaan penghapusan data dibatalkan', $s);

        return back()->with('ok', 'Permintaan penghapusan dibatalkan.');
    }
}
