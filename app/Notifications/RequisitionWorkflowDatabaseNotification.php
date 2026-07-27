<?php

namespace App\Notifications;

use App\Filament\Resources\PropertyActionRequests\PropertyActionRequestResource;
use App\Filament\Resources\Requisitions\RequisitionResource;
use App\Notifications\Concerns\InteractsWithFilamentDatabase;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class RequisitionWorkflowDatabaseNotification extends Notification
{
    use InteractsWithFilamentDatabase;
    use Queueable;

    public function __construct(
        public string $title,
        public string $body,
        public ?int $requisitionId = null,
        public ?int $propertyActionRequestId = null,
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
        if ($this->propertyActionRequestId !== null) {
            return $this->filamentDatabaseMessage(
                $this->title,
                $this->body,
                PropertyActionRequestResource::viewModalUrl($this->propertyActionRequestId),
                'View property return',
            );
        }

        $url = $this->requisitionId !== null
            ? RequisitionResource::viewModalUrl($this->requisitionId)
            : RequisitionResource::getUrl('index');

        return $this->filamentDatabaseMessage(
            $this->title,
            $this->body,
            $url,
            'View requisition',
        );
    }
}
