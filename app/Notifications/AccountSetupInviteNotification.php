<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class AccountSetupInviteNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public string $setupUrl,
        public string $panelLoginUrl,
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
            ->subject('Set up your account — '.config('owwa_mail.brand_name'))
            ->markdown('mail.account-setup-invite', [
                'user' => $notifiable,
                'setupUrl' => $this->setupUrl,
                'panelLoginUrl' => $this->panelLoginUrl,
            ]);
    }
}
