<?php

namespace App\Http\Controllers;

use App\Models\UserLog;
use Filament\Facades\Filament;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class AuditSessionController extends Controller
{
    /**
     * Return the current session CSRF token so the login page can keep
     * Livewire's meta/data-csrf in sync with the browser session cookie.
     */
    public function csrf(Request $request): JsonResponse
    {
        return response()->json([
            'token' => $request->session()->token(),
        ]);
    }

    /**
     * End the current login without requiring a CSRF POST.
     * Used when the page token is already dead (419) or for idle timeout.
     */
    public function recover(Request $request): RedirectResponse
    {
        if ($request->headers->get('Sec-Fetch-Site') === 'cross-site') {
            abort(403);
        }

        $reason = $this->resolveLogoutReason($request);

        if (Auth::guard('web')->check()) {
            $this->endSession($request, $reason);
        }

        $query = $reason === UserLog::LOGOUT_IDLE_TIMEOUT
            ? ['logged_out' => '1']
            : ['reauth' => '1'];

        return redirect()->to($this->defaultLoginUrl().'?'.http_build_query($query));
    }

    public function idleLogout(Request $request): RedirectResponse
    {
        $this->endSession($request, UserLog::LOGOUT_IDLE_TIMEOUT);

        return redirect()->to($this->safeRedirectUrl($request));
    }

    protected function endSession(Request $request, string $reason): void
    {
        $request->session()->put('audit_logout_reason', $reason);

        Auth::guard('web')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();
    }

    protected function resolveLogoutReason(Request $request): string
    {
        $reason = $request->query('reason');

        return match ($reason) {
            UserLog::LOGOUT_IDLE_TIMEOUT => UserLog::LOGOUT_IDLE_TIMEOUT,
            default => UserLog::LOGOUT_SESSION_EXPIRED,
        };
    }

    protected function defaultLoginUrl(): string
    {
        return Filament::getPanel('admin')?->getLoginUrl()
            ?? Filament::getCurrentOrDefaultPanel()?->getLoginUrl()
            ?? url('/login');
    }

    protected function safeRedirectUrl(Request $request): string
    {
        $candidate = $request->input('redirect', $request->query('redirect'));
        $fallback = $this->defaultLoginUrl();

        if (! is_string($candidate) || blank($candidate)) {
            return $fallback;
        }

        if (str_starts_with($candidate, '/')) {
            return url($candidate);
        }

        $appUrl = rtrim((string) config('app.url'), '/');

        if ($candidate === $appUrl || str_starts_with($candidate, $appUrl.'/')) {
            return $candidate;
        }

        return $fallback;
    }
}
