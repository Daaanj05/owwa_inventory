<?php

namespace App\Support;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Throwable;

class MailDelivery
{
    public static function notify(object $notifiable, Notification $notification): MailDeliveryResult
    {
        try {
            $notifiable->notify($notification);

            $wasQueued = $notification instanceof ShouldQueue
                && config('queue.default') !== 'sync';

            return new MailDeliveryResult(true, $wasQueued);
        } catch (Throwable $exception) {
            if (! self::isMailTransportFailure($exception)) {
                throw $exception;
            }

            Log::warning('Mail delivery failed.', [
                'exception' => $exception->getMessage(),
            ]);

            return new MailDeliveryResult(false, false);
        }
    }

    public static function attempt(callable $send): bool
    {
        try {
            $send();

            return true;
        } catch (Throwable $exception) {
            if (! self::isMailTransportFailure($exception)) {
                throw $exception;
            }

            Log::warning('Mail delivery failed.', [
                'exception' => $exception->getMessage(),
            ]);

            return false;
        }
    }

    public static function isMailTransportFailure(Throwable $exception): bool
    {
        if ($exception instanceof TransportExceptionInterface) {
            return true;
        }

        $message = $exception->getMessage();

        return str_contains($message, 'Unable to connect')
            || str_contains($message, 'Connection could not be established')
            || str_contains($message, 'Connection timed out');
    }
}
