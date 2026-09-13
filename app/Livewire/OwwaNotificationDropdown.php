<?php

namespace App\Livewire;

use App\Support\AiProcurementSummaryRestore;
use Filament\Facades\Filament;
use Filament\Livewire\DatabaseNotifications as BaseDatabaseNotifications;
use Filament\Notifications\Notification;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\On;

class OwwaNotificationDropdown extends BaseDatabaseNotifications
{
    public static bool $isPaginated = false;

    public string $tab = 'all';

    public int $notificationLimit = 15;

    public const NOTIFICATION_PAGE_SIZE = 15;

    public function getPollingInterval(): ?string
    {
        return Filament::getDatabaseNotificationsPollingInterval();
    }

    public function setTab(string $tab): void
    {
        $this->tab = in_array($tab, ['all', 'unread'], true) ? $tab : 'all';
        $this->notificationLimit = self::NOTIFICATION_PAGE_SIZE;
    }

    public function loadMoreNotifications(): void
    {
        $this->notificationLimit += self::NOTIFICATION_PAGE_SIZE;
    }

    public function hasMoreNotifications(): bool
    {
        return $this->getVisibleNotifications()->count() < $this->getTotalNotificationsCount();
    }

    public function getTotalNotificationsCount(): int
    {
        $query = $this->getNotificationsQuery();

        if ($this->tab === 'unread') {
            $query->unread();
        }

        return (int) $query->count();
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
        $notifications = $query->limit($this->notificationLimit)->get();

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
                return $this->sanitizeNotificationActionUrl((string) $action['url']);
            }
        }

        return null;
    }

    /**
     * Legacy AI completion links used ?ai_run=N. Strip that query and queue a one-shot summary restore.
     */
    protected function sanitizeNotificationActionUrl(string $url): string
    {
        $parts = parse_url($url);
        if ($parts === false) {
            return $url;
        }

        $query = [];
        if (! empty($parts['query'])) {
            parse_str($parts['query'], $query);
        }

        if (isset($query['ai_run']) && is_numeric($query['ai_run']) && Auth::id() !== null) {
            AiProcurementSummaryRestore::remember((int) Auth::id(), (int) $query['ai_run']);
        }

        unset($query['ai_run']);

        $path = $parts['path'] ?? '';
        $rebuilt = '';

        if (isset($parts['scheme'], $parts['host'])) {
            $rebuilt = $parts['scheme'].'://'.$parts['host'];
            if (isset($parts['port'])) {
                $rebuilt .= ':'.$parts['port'];
            }
        }

        $rebuilt .= $path;

        if ($query !== []) {
            $rebuilt .= '?'.http_build_query($query);
        }

        if (isset($parts['fragment'])) {
            $rebuilt .= '#'.$parts['fragment'];
        }

        return $rebuilt !== '' ? $rebuilt : $url;
    }

    #[On('databaseNotificationsSent')]
    public function refresh(): void {}

    public function markAllNotificationsAsRead(): void
    {
        $user = Filament::auth()->user();

        if ($user === null) {
            return;
        }

        $user->unreadNotifications->markAsRead();
    }

    public function render(): View
    {
        return view('livewire.owwa-notification-dropdown');
    }
}
