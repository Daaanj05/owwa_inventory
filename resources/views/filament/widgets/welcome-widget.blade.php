@php
    $user = filament()->auth()->user();
    $hour = now()->hour;
    $greeting = match (true) {
        $hour < 12 => 'Good morning',
        $hour < 17 => 'Good afternoon',
        default    => 'Good evening',
    };
    $role = match ($user->role ?? '') {
        'supply_custodian'     => 'Supply Custodian',
        'unit_consolidator' => 'Unit Consolidator',
        'employee'             => 'Employee',
        default                => 'Staff',
    };
    $dateLabel = now()->format('l, F j, Y');
    $initial = strtoupper(substr($user->name ?? 'U', 0, 1));
@endphp

<x-filament-widgets::widget>
    <div class="owwa-welcome">
        <div class="owwa-welcome-body">
            <div class="owwa-welcome-left">
                <div class="owwa-welcome-avatar-placeholder">{{ $initial }}</div>
                <div>
                    <div class="owwa-welcome-greeting">{{ $greeting }}, {{ $user->name }}.</div>
                    <div class="owwa-welcome-role">{{ $role }} &middot; OWWA-4A Inventory System</div>
                </div>
            </div>
            <div class="owwa-welcome-right">
                <span class="owwa-welcome-date">
                    <svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:-1px;margin-right:4px;opacity:0.7"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect><line x1="16" y1="2" x2="16" y2="6"></line><line x1="8" y1="2" x2="8" y2="6"></line><line x1="3" y1="10" x2="21" y2="10"></line></svg>
                    {{ $dateLabel }}
                </span>
            </div>
        </div>
    </div>
</x-filament-widgets::widget>
