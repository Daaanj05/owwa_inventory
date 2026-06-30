<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class UserWelcomeNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public string $temporaryPassword,
        public string $panelLoginUrl,
        public string $verificationUrl,
    ) {}

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
            ->subject('Welcome to the OWWA Region IV-A Inventory System — verify your email')
            ->markdown('mail.user-welcome', [
                'user' => $notifiable,
                'temporaryPassword' => $this->temporaryPassword,
                'panelLoginUrl' => $this->panelLoginUrl,
                'verificationUrl' => $this->verificationUrl,
            ]);
    }
}
