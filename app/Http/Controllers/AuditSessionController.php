<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class AuditSessionController extends Controller
{
    public function idleLogout(Request $request): RedirectResponse
    {
        $request->session()->put('audit_logout_reason', 'idle_timeout');

        Auth::guard('web')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        $loginUrl = $request->query('redirect');

        if (! is_string($loginUrl) || $loginUrl === '') {
            $loginUrl = url('/');
        }

        return redirect()->to($loginUrl);
    }
}
