@php
    use Filament\Support\Icons\Heroicon;

    $unreadCount = $this->getUnreadNotificationsCount();
    $grouped = $this->getGroupedNotifications();
    $hasNotifications = $this->getVisibleNotifications()->isNotEmpty();
    $pollingInterval = $this->getPollingInterval();
@endphp

<div
    class="owwa-notif-root"
    x-data="{ open: false }"
    x-on:keydown.escape.window="open = false"
    @if ($pollingInterval)
        wire:poll.{{ $pollingInterval }}
    @endif
>
    <button
        type="button"
        class="owwa-notif-trigger fi-topbar-item-btn"
        x-on:click="open = ! open"
        aria-label="Notifications"
        aria-haspopup="true"
        :aria-expanded="open"
    >
        <x-filament::icon :icon="Heroicon::OutlinedBell" class="owwa-notif-trigger-icon" />
        @if ($unreadCount > 0)
            <span class="owwa-notif-badge">{{ $unreadCount > 99 ? '99+' : $unreadCount }}</span>
        @endif
    </button>

    <div
        x-show="open"
        x-cloak
        x-transition:enter="owwa-notif-panel-enter"
        x-transition:leave="owwa-notif-panel-leave"
        class="owwa-notif-dropdown"
        x-on:click.outside="open = false"
    >
        <div class="owwa-notif-header">
            <h2 class="owwa-notif-title">Notifications</h2>
            @if ($unreadCount > 0)
                <button
                    type="button"
                    class="owwa-notif-mark-all"
                    wire:click="markAllNotificationsAsRead"
                >
                    Mark all as read
                </button>
            @endif
        </div>

        <div class="owwa-notif-tabs">
            <button
                type="button"
                class="owwa-notif-tab {{ $tab === 'all' ? 'owwa-notif-tab--active' : '' }}"
                wire:click="setTab('all')"
            >
                All
            </button>
            <button
                type="button"
                class="owwa-notif-tab {{ $tab === 'unread' ? 'owwa-notif-tab--active' : '' }}"
                wire:click="setTab('unread')"
            >
                Unread
                @if ($unreadCount > 0)
                    <span class="owwa-notif-tab-count">{{ $unreadCount }}</span>
                @endif
            </button>
        </div>

        <div class="owwa-notif-list">
            @if (! $hasNotifications)
                <div class="owwa-notif-empty">
                    <x-filament::icon :icon="Heroicon::OutlinedBellSlash" class="owwa-notif-empty-icon" />
                    <p class="owwa-notif-empty-title">
                        {{ $tab === 'unread' ? 'No unread notifications' : 'No notifications yet' }}
                    </p>
                    <p class="owwa-notif-empty-text">
                        {{ $tab === 'unread' ? 'You are all caught up.' : 'Alerts about requisitions and inventory counts will appear here.' }}
                    </p>
                </div>
            @else
                @foreach (['new' => 'New', 'earlier' => 'Earlier'] as $groupKey => $groupLabel)
                    @if ($grouped[$groupKey]->isNotEmpty())
                        <div class="owwa-notif-group">
                            <div class="owwa-notif-group-header">
                                <span>{{ $groupLabel }}</span>
                            </div>

                            @foreach ($grouped[$groupKey] as $notification)
                                @php
                                    $payload = $this->getFilamentNotification($notification);
                                    $icon = $notification->data['icon'] ?? Heroicon::OutlinedBell->value;
                                    $isUnread = $notification->unread();
                                @endphp

                                <button
                                    type="button"
                                    class="owwa-notif-row {{ $isUnread ? 'owwa-notif-row--unread' : '' }}"
                                    wire:click="openNotification('{{ $notification->id }}')"
                                    wire:key="owwa-notif-{{ $notification->id }}"
                                >
                                    <div class="owwa-notif-row-icon">
                                        <x-filament::icon :icon="$icon" class="owwa-notif-row-icon-svg" />
                                    </div>
                                    <div class="owwa-notif-row-body">
                                        <p class="owwa-notif-row-title">{{ $payload->getTitle() }}</p>
                                        @if (filled($payload->getBody()))
                                            <p class="owwa-notif-row-text">{{ $payload->getBody() }}</p>
                                        @endif
                                        <p class="owwa-notif-row-time">{{ $notification->created_at?->diffForHumans(short: true) }}</p>
                                    </div>
                                    @if ($isUnread)
                                        <span class="owwa-notif-row-dot" aria-hidden="true"></span>
                                    @endif
                                </button>
                            @endforeach
                        </div>
                    @endif
                @endforeach
            @endif
        </div>
    </div>
</div>
