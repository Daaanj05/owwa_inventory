@php
    use Filament\Support\Icons\Heroicon;

    $interactive = $interactive ?? false;
    $unreadCount = $unreadCount ?? 0;
    $wrap = $wrap ?? false;
@endphp

@if ($wrap)
    <div class="owwa-notif-root">
@endif
<button
    type="button"
    class="owwa-notif-trigger fi-topbar-item-btn"
    aria-label="Notifications"
    @if ($interactive)
        x-on:click="open = ! open"
        aria-haspopup="true"
        :aria-expanded="open"
    @else
        tabindex="-1"
    @endif
>
    <x-filament::icon :icon="Heroicon::OutlinedBell" class="owwa-notif-trigger-icon" />
    @if ($unreadCount > 0)
        <span class="owwa-notif-badge">{{ $unreadCount > 99 ? '99+' : $unreadCount }}</span>
    @endif
</button>
@if ($wrap)
    </div>
@endif
