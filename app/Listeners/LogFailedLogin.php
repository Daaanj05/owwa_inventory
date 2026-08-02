<?php

namespace App\Listeners;

use App\Services\UserActivityLogger;
use Illuminate\Auth\Events\Failed;
use Illuminate\Support\Facades\Log;
use Throwable;

class LogFailedLogin
{
    public function handle(Failed $event): void
    {
        $email = (string) ($event->credentials['email'] ?? $event->credentials['username'] ?? '');
        $user = $event->user;

        $summary = filled($email)
            ? 'Failed login attempt for '.$email
            : 'Failed login attempt';

        try {
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
        } catch (Throwable $exception) {
            Log::warning('Failed to persist login_failed activity log.', [
                'email' => $email !== '' ? $email : null,
                'message' => $exception->getMessage(),
            ]);
        }
    }
}
