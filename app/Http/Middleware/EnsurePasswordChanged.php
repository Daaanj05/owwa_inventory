<?php

namespace App\Http\Middleware;

use App\Filament\Pages\Auth\ChangePassword;
use App\Models\User;
use Closure;
use Filament\Facades\Filament;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsurePasswordChanged
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User || ! $user->mustChangePassword()) {
            return $next($request);
        }

        $routeName = $request->route()?->getName() ?? '';

        if ($this->isAllowedRoute($routeName)) {
            return $next($request);
        }

        $panel = Filament::getCurrentOrDefaultPanel();

        return redirect()->to(ChangePassword::getUrl(panel: $panel?->getId()));
    }

    protected function isAllowedRoute(string $routeName): bool
    {
        if ($routeName === '') {
            return false;
        }

        if (str_starts_with($routeName, 'livewire.')) {
            return true;
        }

        return str_ends_with($routeName, '.pages.change-password')
            || str_ends_with($routeName, '.auth.logout');
    }
}
