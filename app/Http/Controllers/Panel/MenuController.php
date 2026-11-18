<?php

namespace App\Http\Controllers\Panel;

use App\Http\Controllers\Controller;
use App\Models\MenuTemplate;
use App\Models\School;
use App\Services\Platform\AuditLogger;
use App\Support\SchoolMenu;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Panel: menu & istilah per sekolah, dan template menu & istilah yang diisi manual oleh super-admin.
 */
class MenuController extends Controller
{
    public function __construct(private AuditLogger $audit) {}

    /** Simpan nama menu per portal & menu yang disembunyikan untuk satu sekolah. */
    public function saveSchool(Request $request, School $school): RedirectResponse
    {
        $request->validate(['names' => ['array'], 'show' => ['array']]);
        $new = $this->fromForm($request);
        $old = SchoolMenu::of($school);
        $changes = SchoolMenu::diff($old, $new);

        $settings = (array) ($school->settings ?? []);
        if ($new['names'] || $new['hidden']) {
            $settings['menu'] = $new;
        } else {
            unset($settings['menu']);
        }
        $school->forceFill(['settings' => $settings])->save();

        if ($changes) {
            $this->audit->log('school.menu_updated', 'Menu & istilah '.$school->name.' diubah: '.implode('; ', array_slice($changes, 0, 6))
                .(count($changes) > 6 ? ' (+'.(count($changes) - 6).' lainnya)' : ''), $school, $request->user(), ['changes' => $changes]);
        }

        return redirect()->to(route('panel.schools.show', $school->id).'#menu-sekolah')
            ->with('ok', $changes ? count($changes).' perubahan menu disimpan. Guru & siswa melihatnya setelah aplikasi dimuat ulang.' : 'Tidak ada perubahan.');
    }

    // ── Template ────────────────────────────────────────────────────

    public function templates(Request $request): View
    {
        $edit = $request->integer('edit') ? MenuTemplate::find($request->integer('edit')) : null;

        return view('panel.menu-templates', [
            'templates' => MenuTemplate::query()->orderBy('name')->get(),
            'edit' => $edit,
            'catalog' => SchoolMenu::catalogForUi(),
            'value' => $edit ? $edit->menu() : ['names' => [], 'hidden' => []],
            'usage' => $this->usage(),
        ]);
    }

    public function saveTemplate(Request $request, ?MenuTemplate $template = null): RedirectResponse
    {
        $d = $request->validate([
            'name' => ['required', 'string', 'max:60', Rule::unique('menu_templates', 'name')->ignore($template?->id)],
            'description' => ['nullable', 'string', 'max:200'],
            'names' => ['array'], 'show' => ['array'],
        ], [], ['name' => 'nama template', 'description' => 'keterangan']);
        $data = $this->fromForm($request);

        if ($template) {
            $template->update(['name' => $d['name'], 'description' => $d['description'] ?? null, 'data' => $data]);
        } else {
            $template = MenuTemplate::create(['name' => $d['name'], 'description' => $d['description'] ?? null, 'data' => $data, 'created_by' => $request->user()->id]);
        }
        $this->audit->log('plan.menu_template_saved', "Template menu & istilah \"{$template->name}\" disimpan ({$template->changeCount()} pengaturan)", null, $request->user());

        return redirect()->route('panel.menu.templates', ['edit' => $template->id])->with('ok', "Template \"{$template->name}\" disimpan. Terapkan ke sekolah lewat halaman detail sekolah.");
    }

    public function destroyTemplate(Request $request, MenuTemplate $template): RedirectResponse
    {
        $template->delete();
        $this->audit->log('plan.menu_template_deleted', "Template menu & istilah \"{$template->name}\" dihapus", null, $request->user());

        return redirect()->route('panel.menu.templates')->with('ok', "Template \"{$template->name}\" dihapus. Sekolah yang sudah memakainya tidak berubah.");
    }

    // ── bantuan ──

    /** names[rute][peran] + show[] (menu fitur yang dicentang tampil) → format tersimpan. */
    private function fromForm(Request $request): array
    {
        $shown = array_map('strval', (array) $request->input('show', []));
        $hidden = array_values(array_filter(array_keys(SchoolMenu::CATALOG), fn ($k) => SchoolMenu::hideable($k) && ! in_array($k, $shown, true)));

        return SchoolMenu::clean(['names' => (array) $request->input('names', []), 'hidden' => $hidden]);
    }

    /** Berapa sekolah yang sudah punya pengaturan menu sendiri. */
    private function usage(): int
    {
        return School::query()->withoutGlobalScopes()->whereNotNull('settings')->get(['id', 'settings'])
            ->filter(fn ($s) => ! empty(((array) $s->settings)['menu'] ?? null))->count();
    }
}
