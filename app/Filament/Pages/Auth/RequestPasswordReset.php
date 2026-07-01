<?php

namespace App\Filament\Pages\Auth;

use App\Services\PasswordResetRequestService;
use App\Support\FriendlyMessages;
use DanHarrin\LivewireRateLimiting\Exceptions\TooManyRequestsException;
use Filament\Auth\Pages\PasswordReset\RequestPasswordReset as BaseRequestPasswordReset;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Password;

class RequestPasswordReset extends BaseRequestPasswordReset
{
    public function request(): void
    {
        try {
            $this->rateLimit(2);
        } catch (TooManyRequestsException $exception) {
            $this->getRateLimitedNotification($exception)?->send();

            return;
        }

        $data = $this->form->getState();
        $email = $data['email'] ?? '';

        app(PasswordResetRequestService::class)->submitRequest($email);

        Notification::make()
            ->title(__(Password::RESET_LINK_SENT))
            ->body(FriendlyMessages::passwordResetRequestSubmitted())
            ->success()
            ->send();

        $this->form->fill();
    }

    protected function getSentNotification(string $status): ?Notification
    {
        return null;
    }
}
