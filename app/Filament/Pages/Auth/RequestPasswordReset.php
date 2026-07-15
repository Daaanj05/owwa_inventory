<?php

namespace App\Filament\Pages\Auth;

use App\Services\PasswordResetRequestService;
use App\Support\FriendlyMessages;
use DanHarrin\LivewireRateLimiting\Exceptions\TooManyRequestsException;
use Filament\Auth\Pages\PasswordReset\RequestPasswordReset as BaseRequestPasswordReset;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Component;
use Illuminate\Support\Facades\Password;

class RequestPasswordReset extends BaseRequestPasswordReset
{
    protected string $view = 'filament.pages.auth.request-password-reset';

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

    protected function getEmailFormComponent(): Component
    {
        return TextInput::make('email')
            ->label(__('filament-panels::auth/pages/password-reset/request-password-reset.form.email.label'))
            ->placeholder("\u{200B}")
            ->email()
            ->required()
            ->autocomplete()
            ->autofocus();
    }
}
