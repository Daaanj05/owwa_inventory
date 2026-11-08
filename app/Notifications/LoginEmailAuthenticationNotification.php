<?php

namespace App\Notifications;

use Filament\Auth\MultiFactor\Email\Notifications\VerifyEmailAuthentication;
use Illuminate\Notifications\Messages\MailMessage;

class LoginEmailAuthenticationNotification extends VerifyEmailAuthentication
{
    public function __construct(string $code, int $codeExpiryMinutes)
    {
        parent::__construct($code, $codeExpiryMinutes);

        $this->onQueue('mail');
    }

    public function toMail(object $notifiable): MailMessage
    {
        $minutes = max(1, $this->codeExpiryMinutes);

        return (new MailMessage)
            ->subject('Your sign-in code')
            ->markdown('mail.sign-in-code', [
                'user' => $notifiable,
                'code' => $this->code,
                'expiresLabel' => $minutes === 1 ? '1 minute' : $minutes.' minutes',
            ]);
    }
}
