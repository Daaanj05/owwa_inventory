<?php

namespace App\Notifications;

use App\Filament\Resources\Issuances\IssuanceResource;
use App\Notifications\Concerns\InteractsWithFilamentDatabase;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class IssuanceCreatedDatabaseNotification extends Notification
{
    use InteractsWithFilamentDatabase;
    use Queueable;

    public function __construct(
        public string $title,
        public string $body,
        public ?int $issuanceId = null,
        public ?int $categoryId = null,
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
        $url = $this->issuanceId !== null
            ? IssuanceResource::viewModalUrl(
                $this->issuanceId,
                array_filter(['category' => $this->categoryId]),
            )
            : IssuanceResource::getUrl('index', array_filter(['category' => $this->categoryId]));

        return $this->filamentDatabaseMessage(
            $this->title,
            $this->body,
            $url,
            'View issuance',
        );
    }
}
