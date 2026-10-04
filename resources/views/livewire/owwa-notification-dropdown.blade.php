@php
    use Filament\Support\Icons\Heroicon;

    $unreadCount = $this->getUnreadNotificationsCount();
    $visibleNotifications = $this->getVisibleNotifications();
    $grouped = $this->getGroupedNotifications();
    $hasNotifications = $visibleNotifications->isNotEmpty();
    $hasMoreNotifications = $this->hasMoreNotifications();
    $pollingInterval = $this->getPollingInterval();
    $broadcastChannel = $this->getBroadcastChannel();
@endphp

<div
    class="owwa-notif-root"
    x-data="{ open: false }"
    x-on:keydown.escape.window="open = false"
    @if ($pollingInterval)
        wire:poll.{{ $pollingInterval }}
    @endif
>
    @include('livewire.partials.owwa-notification-bell', [
        'interactive' => true,
        'unreadCount' => $unreadCount,
    ])

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
            <button
                type="button"
                class="owwa-notif-mark-all {{ $unreadCount === 0 ? 'owwa-notif-mark-all--disabled' : '' }}"
                wire:click.stop="markAllNotificationsAsRead"
                @disabled($unreadCount === 0)
                aria-disabled="{{ $unreadCount === 0 ? 'true' : 'false' }}"
            >
                Read all
            </button>
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
                                    $actions = collect($notification->data['actions'] ?? [])
                                        ->filter(fn (mixed $action): bool => is_array($action) && filled($action['url'] ?? null))
                                        ->values();
                                    $hasExportActions = $actions->count() > 1
                                        || $actions->contains(fn (array $action): bool => in_array(
                                            strtolower((string) ($action['label'] ?? '')),
                                            ['preview', 'download', 'download export'],
                                            true,
                                        ));
                                @endphp

                                @if ($hasExportActions)
                                    <div
                                        class="owwa-notif-row {{ $isUnread ? 'owwa-notif-row--unread' : '' }}"
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
                                            <div class="owwa-notif-row-actions">
                                                @foreach ($actions as $action)
                                                    @php
                                                        $label = (string) ($action['label'] ?? 'Open');
                                                        $url = (string) $action['url'];
                                                        $isPreview = strtolower($label) === 'preview';
                                                    @endphp
                                                    <a
                                                        href="{{ $url }}"
                                                        class="owwa-notif-row-action"
                                                        @if ($isPreview) target="_blank" rel="noopener noreferrer" @endif
                                                        wire:click.stop="markNotificationRead('{{ $notification->id }}')"
                                                    >{{ $label }}</a>
                                                @endforeach
                                            </div>
                                        </div>
                                        @if ($isUnread)
                                            <span class="owwa-notif-row-dot" aria-hidden="true"></span>
                                        @endif
                                    </div>
                                @else
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
                                @endif
                            @endforeach
                        </div>
                    @endif
                @endforeach
            @endif
        </div>

        @if ($hasMoreNotifications)
            <div class="owwa-notif-load-more-wrap">
                <button
                    type="button"
                    class="owwa-notif-load-more"
                    wire:click.stop="loadMoreNotifications"
                >
                    See previous notifications
                </button>
            </div>
        @endif
    </div>

    @if (filled($broadcastChannel) && blank($pollingInterval))
        @script
            <script>
                window.addEventListener('EchoLoaded', () => {
                    window.Echo.private(@js($broadcastChannel)).listen(
                        '.database-notifications.sent',
                        () => {
                            setTimeout(
                                () => $wire.call('$refresh'),
                                500,
                            )
                        },
                    )
                })

                if (window.Echo) {
                    window.dispatchEvent(new CustomEvent('EchoLoaded'))
                }
            </script>
        @endscript
    @endif
</div>
