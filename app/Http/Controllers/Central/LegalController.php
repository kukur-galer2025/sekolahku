<?php

namespace App\Http\Controllers\Central;

use App\Http\Controllers\Controller;
use Illuminate\View\View;

class LegalController extends Controller
{
    public function show(string $slug): View
    {
        $key = collect(config('legal.documents'))->search(fn ($d) => $d['slug'] === $slug);
        abort_unless($key, 404);

        return view('legal.'.$key, ['key' => $key]);
    }
}
