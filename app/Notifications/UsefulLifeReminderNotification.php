<?php

namespace App\Notifications;

use App\Models\Issuance;
use Filament\Notifications\Notification as FilamentNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class UsefulLifeReminderNotification extends Notification
{
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

        return FilamentNotification::make()
            ->title($title)
            ->body($body)
            ->icon(\Filament\Support\Icons\Heroicon::OutlinedClock)
            ->iconColor($this->reminderType === 'expired' ? 'danger' : 'warning')
            ->getDatabaseMessage();
    }
}
