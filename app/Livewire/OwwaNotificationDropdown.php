<?php

namespace App\Livewire;

use Filament\Facades\Filament;
use Filament\Livewire\DatabaseNotifications as BaseDatabaseNotifications;
use Filament\Notifications\Notification;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Collection;
use Livewire\Attributes\On;

class OwwaNotificationDropdown extends BaseDatabaseNotifications
{
    public static bool $isPaginated = false;

    public string $tab = 'all';

    public function getPollingInterval(): ?string
    {
        return Filament::getDatabaseNotificationsPollingInterval();
    }

    public function setTab(string $tab): void
    {
        $this->tab = in_array($tab, ['all', 'unread'], true) ? $tab : 'all';
    }

    public function openNotification(string $id): void
    {
        /** @var DatabaseNotification|null $notification */
        $notification = $this->getNotificationsQuery()->where('id', $id)->first();

        if (! $notification) {
            return;
        }

        $url = $this->resolveNotificationUrl($notification);

        if ($notification->unread()) {
            $notification->markAsRead();
        }

        if (filled($url)) {
            $this->redirect($url, navigate: true);
        }
    }

    /**
     * @return Collection<int, DatabaseNotification>
     */
    public function getVisibleNotifications(): Collection
    {
        $query = $this->getNotificationsQuery()->latest();

        if ($this->tab === 'unread') {
            $query->unread();
        }

        /** @var EloquentCollection<int, DatabaseNotification> $notifications */
        $notifications = $query->limit(50)->get();

        return $notifications;
    }

    /**
     * @return array<string, Collection<int, DatabaseNotification>>
     */
    public function getGroupedNotifications(): array
    {
        $new = collect();
        $earlier = collect();
        $cutoff = now()->subDay();

        foreach ($this->getVisibleNotifications() as $notification) {
            if ($notification->created_at?->gte($cutoff)) {
                $new->push($notification);
            } else {
                $earlier->push($notification);
            }
        }

        return [
            'new' => $new,
            'earlier' => $earlier,
        ];
    }

    public function getFilamentNotification(DatabaseNotification $notification): Notification
    {
        return $this->getNotification($notification);
    }

    protected function resolveNotificationUrl(DatabaseNotification $notification): ?string
    {
        $actions = $notification->data['actions'] ?? [];

        foreach ($actions as $action) {
            if (filled($action['url'] ?? null)) {
                return $action['url'];
            }
        }

        return null;
    }

    #[On('databaseNotificationsSent')]
    public function refresh(): void {}

    public function render(): View
    {
        return view('livewire.owwa-notification-dropdown');
    }
}
