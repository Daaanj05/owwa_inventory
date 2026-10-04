<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class AdminRedirectController extends Controller
{
    public function __invoke(Request $request, ?string $path = null): RedirectResponse
    {
        $target = '/'.ltrim((string) $path, '/');
        $query = $request->getQueryString();

        return redirect($query ? $target.'?'.$query : $target);
    }
}
