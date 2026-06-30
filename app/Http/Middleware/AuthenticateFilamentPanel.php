<?php

namespace App\Http\Middleware;

use Filament\Facades\Filament;
use Filament\Http\Middleware\Authenticate as FilamentAuthenticate;
use Filament\Models\Contracts\FilamentUser;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\Auth;

class AuthenticateFilamentPanel extends FilamentAuthenticate
{
    /**
     * @param  array<string>  $guards
     */
    protected function authenticate($request, array $guards): void
    {
        $guard = Filament::auth();

        if (! $guard->check()) {
            $this->unauthenticated($request, $guards);

            return; /** @phpstan-ignore-line */
        }

        $this->auth->shouldUse(Filament::getAuthGuard());

        /** @var Model $user */
        $user = $guard->user();

        $panel = Filament::getCurrentOrDefaultPanel();

        $cannotAccess = $user instanceof FilamentUser
            ? ! $user->canAccessPanel($panel)
            : (config('app.env') !== 'local');

        if (! $cannotAccess) {
            return;
        }

        Auth::logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        $message = $panel->getId() === 'system-admin'
            ? 'This portal is for system administrators only. Sign in at the operations portal if you are a supply custodian, unit consolidator, or employee.'
            : 'This portal is for supply custodian, unit consolidator, and employee accounts. System administrators must use the system administrator portal.';

        throw new HttpResponseException(
            redirect()->to($panel->getLoginUrl())
                ->with('panel_access_error', $message)
        );
    }
}
