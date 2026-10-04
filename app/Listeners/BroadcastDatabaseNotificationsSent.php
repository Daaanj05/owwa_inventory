<?php

namespace App\Listeners;

use App\Events\DatabaseNotificationsSentNow;
use App\Models\User;
use Illuminate\Notifications\Events\NotificationSent;
use Throwable;

class BroadcastDatabaseNotificationsSent
{
    public function handle(NotificationSent $event): void
    {
        if ($event->channel !== 'database') {
            return;
        }

        $notifiable = $event->notifiable;

        if (! $notifiable instanceof User) {
            return;
        }

        if (! filled(config('filament.broadcasting.echo.key'))) {
            return;
        }

        try {
            DatabaseNotificationsSentNow::dispatch($notifiable);
        } catch (Throwable $exception) {
            report($exception);
        }
    }
}
