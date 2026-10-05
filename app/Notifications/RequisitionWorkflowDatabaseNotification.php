<?php

namespace App\Notifications;

use App\Filament\Resources\PropertyActionRequests\PropertyActionRequestResource;
use App\Filament\Resources\Requisitions\RequisitionResource;
use App\Models\Requisition;
use App\Models\User;
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
            ? $this->requisitionViewUrl($notifiable)
            : RequisitionResource::getUrl('index');

        return $this->filamentDatabaseMessage(
            $this->title,
            $this->body,
            $url,
            'View requisition',
        );
    }

    /**
     * @return array<string, mixed>
     */
    protected function requisitionViewUrl(object $notifiable): string
    {
        $params = [];

        if ($notifiable instanceof User && $notifiable->isUnitConsolidator()) {
            $requisition = Requisition::query()->find($this->requisitionId);

            if ($requisition instanceof Requisition) {
                $officeId = (int) $requisition->office_id;
                $departmentId = (int) $requisition->department_id;
                $isOwnSentRequisition = (int) $requisition->requested_by === (int) $notifiable->id;

                if ($isOwnSentRequisition || $notifiable->coversOfficeDepartment($officeId, $departmentId)) {
                    $params['uc'] = $isOwnSentRequisition ? 'sent' : 'received';

                    if ($officeId > 0) {
                        $params['uc_office'] = $officeId;
                    }

                    if ($departmentId > 0) {
                        $params['uc_dept'] = $departmentId;
                    }
                }
            }
        }

        return RequisitionResource::viewModalUrl((int) $this->requisitionId, $params);
    }
}
