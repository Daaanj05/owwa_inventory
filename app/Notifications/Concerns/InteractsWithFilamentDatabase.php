<?php

namespace App\Notifications\Concerns;

use BackedEnum;
use Filament\Actions\Action;
use Filament\Notifications\Notification as FilamentNotification;
use Filament\Support\Icons\Heroicon;

trait InteractsWithFilamentDatabase
{
    /**
     * @return array<string, mixed>
     */
    protected function filamentDatabaseMessage(
        string $title,
        string $body,
        ?string $actionUrl = null,
        string $actionLabel = 'View',
        string|BackedEnum|null $icon = Heroicon::OutlinedDocumentText,
        ?string $iconColor = 'primary',
    ): array {
        $notification = FilamentNotification::make()
            ->title($title)
            ->body($body)
            ->icon($icon)
            ->iconColor($iconColor);

        if (filled($actionUrl)) {
            $notification->actions([
                Action::make('view')
                    ->label($actionLabel)
                    ->url($actionUrl)
                    ->markAsRead(),
            ]);
        }

        return $notification->getDatabaseMessage();
    }
}
