<?php

namespace App\Notifications;

use App\Notifications\Concerns\InteractsWithFilamentDatabase;
use Filament\Actions\Action;
use Filament\Notifications\Notification as FilamentNotification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class StockCardExportReadyDatabaseNotification extends Notification
{
    use InteractsWithFilamentDatabase;
    use Queueable;

    public function __construct(
        public string $title,
        public string $body,
        public ?string $downloadUrl = null,
        public bool $failed = false,
        public ?string $previewUrl = null,
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
        if ($this->failed || ($this->downloadUrl === null && $this->previewUrl === null)) {
            return $this->filamentDatabaseMessage(
                $this->title,
                $this->body,
                null,
                'Download export',
                $this->failed ? Heroicon::OutlinedExclamationTriangle : Heroicon::OutlinedArrowDownTray,
                $this->failed ? 'danger' : 'success',
            );
        }

        $notification = FilamentNotification::make()
            ->title($this->title)
            ->body($this->body)
            ->icon($this->failed ? Heroicon::OutlinedExclamationTriangle : Heroicon::OutlinedArrowDownTray)
            ->iconColor($this->failed ? 'danger' : 'success');

        $actions = [];

        if (filled($this->previewUrl)) {
            $actions[] = Action::make('preview')
                ->label('Preview')
                ->url($this->previewUrl)
                ->openUrlInNewTab()
                ->markAsRead();
        }

        if (filled($this->downloadUrl)) {
            $actions[] = Action::make('download')
                ->label('Download')
                ->url($this->downloadUrl)
                ->markAsRead();
        }

        if ($actions !== []) {
            $notification->actions($actions);
        }

        return $notification->getDatabaseMessage();
    }
}
