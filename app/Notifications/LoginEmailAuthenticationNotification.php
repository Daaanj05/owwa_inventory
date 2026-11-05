<?php

namespace App\Notifications;

use Filament\Auth\MultiFactor\Email\Notifications\VerifyEmailAuthentication;

class LoginEmailAuthenticationNotification extends VerifyEmailAuthentication
{
    public function __construct(string $code, int $codeExpiryMinutes)
    {
        parent::__construct($code, $codeExpiryMinutes);

        $this->onQueue('mail');
    }
}
