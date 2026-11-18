<?php

namespace Tests\Feature\Platform;

use Tests\TestCase;

class PanelPwaTest extends TestCase
{
    private function panel(string $path = ''): string
    {
        return 'http://'.config('tenancy.central_domain').'/panel'.($path ? '/'.ltrim($path, '/') : '');
    }

    public function test_manifest_sw_dan_offline_tersedia_tanpa_login(): void
    {
        $this->tenant()->forget();
        $m = $this->get($this->panel('manifest.webmanifest'))->assertOk()->assertHeader('Content-Type', 'application/manifest+json');
        $this->assertSame('/panel', $m->json('scope'));
        $this->assertSame('standalone', $m->json('display'));
        $this->assertCount(3, $m->json('icons'));
        foreach ($m->json('icons') as $i) {
            $this->assertFileExists(public_path(ltrim($i['src'], '/')));
        }

        $sw = $this->get($this->panel('sw.js'))->assertOk()->assertHeader('Service-Worker-Allowed', '/panel');
        $this->assertStringContainsString("r.mode === 'navigate'", $sw->getContent());
        $this->assertStringContainsString('/css/portal.css?v='.\App\Http\Controllers\Panel\PwaController::cssVersion(), $sw->getContent());
        $this->assertNotSame('1', \App\Http\Controllers\Panel\PwaController::cssVersion());

        $this->get($this->panel('offline'))->assertOk()->assertSee('Kamu sedang offline');
        $this->get($this->panel('masuk'))->assertOk()->assertSee('/panel/manifest.webmanifest', false)->assertSee('/panel/sw.js', false);
    }
}
