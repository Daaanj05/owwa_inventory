<?php

namespace App\Notifications;

use App\Models\Issuance;
use App\Notifications\Concerns\InteractsWithFilamentDatabase;
use App\Services\UsefulLifeConditionReportService;
use Filament\Support\Icons\Heroicon;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class StillUsableUsefulLifeNotification extends Notification
{
    use InteractsWithFilamentDatabase;
    use Queueable;

    public function __construct(
        public Issuance $issuance,
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
        $note = trim((string) $this->issuance->useful_life_condition_note);

        $body = "{$itemName} is still usable.";
        if ($note !== '') {
            $body .= ' '.$note;
        }

        return $this->filamentDatabaseMessage(
            "Still usable — {$propertyNumber}",
            $body,
            app(UsefulLifeConditionReportService::class)->custodianExtendUrl($this->issuance),
            'Extend useful life',
            Heroicon::OutlinedClock,
            'warning',
        );
    }
}
