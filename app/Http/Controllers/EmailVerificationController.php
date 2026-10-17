<?php

namespace App\Http\Controllers;

use App\Services\EmailVerificationCodeService;
use Filament\Facades\Filament;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class EmailVerificationController extends Controller
{
    public function notice(): View
    {
        return view('welcome');
    }

    public function send(Request $request): RedirectResponse
    {
        if ($request->user()->hasVerifiedEmail()) {
            return redirect()->intended(Filament::getPanel('admin')->getUrl());
        }

        app(EmailVerificationCodeService::class)->send($request->user());

        return back()->with('status', 'verification-code-sent');
    }
}
