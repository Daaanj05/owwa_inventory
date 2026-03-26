<?php

namespace App\Listeners;

use App\Models\UserLog;
use Filament\Facades\Filament;
use Illuminate\Auth\Events\Login;

class LogUserLogin
{
    public function handle(Login $event): void
    {
        $user = $event->user;

        if (! $user) {
            return;
        }

        UserLog::create([
            'user_id' => $user->id,
            'ip_address' => request()->ip(),
            'user_agent' => (string) request()->userAgent(),
            'path' => request()->path(),
            'panel' => Filament::getCurrentPanel()?->getId(),
            'logged_in_at' => now(),
        ]);
    }
}

