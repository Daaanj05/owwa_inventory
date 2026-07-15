<?php

namespace App\Notifications;

use App\Filament\Resources\PropertyActionRequests\PropertyActionRequestResource;
use App\Models\Issuance;
use App\Models\PropertyActionRequest;
use App\Notifications\Concerns\InteractsWithFilamentDatabase;
use App\Support\SemiExpendableUsefulLife;
use Filament\Support\Icons\Heroicon;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class UsefulLifeReminderNotification extends Notification
{
    use InteractsWithFilamentDatabase;
    use Queueable;

    public function __construct(
        public Issuance $issuance,
        public string $reminderType,
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
        $propertyNumber = $this->issuance->property_number ?? '—';
        $itemName = $this->issuance->item?->name ?? 'Item';
        $expires = $this->issuance->eul_expires_at?->format('M j, Y') ?? '—';

        $title = match ($this->reminderType) {
            'expired' => "Useful life expired — {$propertyNumber}",
            'warning' => "Useful life ending soon — {$propertyNumber}",
            default => "Useful life review — {$propertyNumber}",
        };

        $body = "{$itemName} — estimated useful life ends {$expires}.";

        if ($this->reminderType === 'expired') {
            $body .= ' For SPLV items, consider return, disposal, or an approved extension.';
        }

        $actionType = in_array(
            SemiExpendableUsefulLife::statusForIssuance($this->issuance),
            [SemiExpendableUsefulLife::STATUS_NEARING, SemiExpendableUsefulLife::STATUS_EXPIRED],
            true,
        )
            ? PropertyActionRequest::ACTION_REPLACEMENT
            : PropertyActionRequest::ACTION_DISPOSAL;

        return $this->filamentDatabaseMessage(
            $title,
            $body,
            PropertyActionRequestResource::createUrlForIssuance($this->issuance->id, $actionType),
            'Start property action',
            Heroicon::OutlinedClock,
            $this->reminderType === 'expired' ? 'danger' : 'warning',
        );
    }
}
