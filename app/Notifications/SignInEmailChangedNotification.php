<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class SignInEmailChangedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public string $newEmail,
        public string $userName,
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
            ->subject('Your sign-in email was changed')
            ->markdown('mail.sign-in-email-changed', [
                'userName' => $this->userName,
                'newEmail' => $this->newEmail,
            ]);
    }
}
