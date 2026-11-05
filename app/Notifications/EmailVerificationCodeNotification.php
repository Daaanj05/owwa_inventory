<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class EmailVerificationCodeNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public string $code,
        public int $expiresInMinutes = 1440,
    ) {
        $this->onQueue('mail');
    }

    public function expiresLabel(): string
    {
        if ($this->expiresInMinutes >= 60 && $this->expiresInMinutes % 60 === 0) {
            $hours = intdiv($this->expiresInMinutes, 60);

            return $hours === 1 ? '1 hour' : $hours.' hours';
        }

        return $this->expiresInMinutes === 1
            ? '1 minute'
            : $this->expiresInMinutes.' minutes';
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
            ->subject('Your verification code')
            ->markdown('mail.email-verification-code', [
                'user' => $notifiable,
                'code' => $this->code,
                'expiresLabel' => $this->expiresLabel(),
            ]);
    }
}
