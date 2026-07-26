<?php

namespace App\Listeners;

use App\Services\UserActivityLogger;
use Illuminate\Auth\Events\Failed;

class LogFailedLogin
{
    public function handle(Failed $event): void
    {
        $email = (string) ($event->credentials['email'] ?? $event->credentials['username'] ?? '');
        $user = $event->user;

        $summary = filled($email)
            ? 'Failed login attempt for '.$email
            : 'Failed login attempt';

        app(UserActivityLogger::class)->record(
            $user instanceof \App\Models\User ? $user : null,
            'login_failed',
            $summary,
            $user instanceof \App\Models\User ? $user : null,
            [
                'email' => $email !== '' ? $email : null,
                'guard' => $event->guard,
            ],
        );
    }
}
