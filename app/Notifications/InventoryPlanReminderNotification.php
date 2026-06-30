<?php

namespace App\Notifications;

use App\Filament\Resources\PhysicalInventoryPlans\PhysicalInventoryPlanResource;
use App\Models\PhysicalInventoryPlanLine;
use Filament\Notifications\Notification as FilamentNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class InventoryPlanReminderNotification extends Notification
{
    use Queueable;

    public function __construct(
        public PhysicalInventoryPlanLine $line,
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
        $officeName = $this->line->office?->name ?? 'office';

        $title = match ($this->reminderType) {
            'd7' => "Inventory count in 7 days — {$officeName}",
            'd1' => "Inventory count tomorrow — {$officeName}",
            'due' => "Inventory count today — {$officeName}",
            default => "Overdue inventory count — {$officeName}",
        };

        $body = sprintf(
            '%s — planned %s.',
            $this->line->plan?->title ?? 'Inventory Schedule',
            $this->line->planned_date?->format('M j, Y') ?? '—',
        );

        return FilamentNotification::make()
            ->title($title)
            ->body($body)
            ->icon(\Filament\Support\Icons\Heroicon::OutlinedCalendarDays)
            ->iconColor('warning')
            ->actions([
                \Filament\Actions\Action::make('view')
                    ->label('View schedule')
                    ->url(PhysicalInventoryPlanResource::viewModalUrl($this->line->physical_inventory_plan_id))
                    ->markAsRead(),
            ])
            ->getDatabaseMessage();
    }
}
