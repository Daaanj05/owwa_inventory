<?php

namespace App\Notifications;

use App\Models\Requisition;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class RequisitionRejectedMailNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public Requisition $requisition,
        public string $title,
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
        $this->requisition->loadMissing(['office']);

        return (new MailMessage)
            ->subject($this->title)
            ->markdown('mail.requisition-rejected', [
                'requisition' => $this->requisition,
                'recipient' => $notifiable,
            ]);
    }
}
