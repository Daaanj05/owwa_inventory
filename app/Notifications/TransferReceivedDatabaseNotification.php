<?php

namespace App\Notifications;

use App\Filament\Pages\OfficePropertyRegister;
use App\Notifications\Concerns\InteractsWithFilamentDatabase;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class TransferReceivedDatabaseNotification extends Notification
{
    use InteractsWithFilamentDatabase;
    use Queueable;

    public function __construct(
        public string $title,
        public string $body,
        public ?int $categoryId = null,
        public ?string $referenceCode = null,
    ) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * @return array<string, mixed>
     */
    public function toDatabase(object $notifiable): array
    {
        $url = OfficePropertyRegister::getUrl(array_filter([
            'tab' => 'transfers',
            'category' => $this->categoryId,
            'direction' => 'incoming',
            'highlight' => $this->referenceCode,
        ]));

        return $this->filamentDatabaseMessage(
            $this->title,
            $this->body,
            $url,
            'View transfers',
        );
    }
}
