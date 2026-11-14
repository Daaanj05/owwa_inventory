<?php

namespace App\Notifications;

use App\Filament\Resources\PhysicalInventoryPlans\PhysicalInventoryPlanResource;
use App\Models\PhysicalInventoryPlanLine;
use Filament\Notifications\Notification as FilamentNotification;
use Filament\Support\Icons\Heroicon;
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

    public function title(): string
    {
        $officeName = $this->line->office?->name ?? 'office';

        return match ($this->reminderType) {
            'd7' => "Inventory count in 7 days — {$officeName}",
            'd1' => "Inventory count tomorrow — {$officeName}",
            'due' => "Inventory count today — {$officeName}",
            default => "Overdue inventory count — {$officeName}",
        };
    }

    public function body(): string
    {
        return sprintf(
            '%s — planned %s.',
            $this->line->plan?->title ?? 'Inventory Schedule',
            $this->line->planned_date?->format('M j, Y') ?? '—',
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toDatabase(object $notifiable): array
    {
        return FilamentNotification::make()
            ->title($this->title())
            ->body($this->body())
            ->icon(Heroicon::OutlinedCalendarDays)
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
