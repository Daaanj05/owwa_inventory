<?php

namespace App\Support;

use Filament\Facades\Filament;
use Illuminate\Support\Facades\View;

class FilamentSessionAudit
{
    public static function idleLogoutMonitorHtml(): string
    {
        if (! auth()->check()) {
            return '';
        }

        $panel = Filament::getCurrentPanel();

        if ($panel === null) {
            return '';
        }

        return View::make('filament.partials.idle-logout-monitor', [
            'loginUrl' => $panel->getLoginUrl(),
        ])->render();
    }
}
