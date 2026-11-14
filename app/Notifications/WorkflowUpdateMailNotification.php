<?php

namespace App\Notifications;

use App\Support\WorkflowEmailContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class WorkflowUpdateMailNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public int $workflowEmailNotificationId,
        public string $title,
        public string $body,
        public ?WorkflowEmailContext $context = null,
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
            ->subject($this->title)
            ->markdown('mail.workflow-update', [
                'title' => $this->title,
                'body' => $this->body,
                'recipient' => $notifiable,
                'context' => $this->context,
            ]);
    }
}
