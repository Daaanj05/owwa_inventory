<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class PasswordResetCodeNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public string $code,
        public string $panelLoginUrl,
        public int $expiresInMinutes = 60,
    ) {
        $this->onQueue('mail');
    }

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Your password reset code')
            ->markdown('mail.password-reset-code', [
                'user' => $notifiable,
                'code' => $this->code,
                'panelLoginUrl' => $this->panelLoginUrl,
                'expiresInMinutes' => $this->expiresInMinutes,
            ]);
    }
}
