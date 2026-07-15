<div class="owwa-account-shell">
    <div class="owwa-account-header">
        <nav
            class="owwa-account-tabs"
            aria-label="Account navigation"
            data-active-tab="{{ $activeTab }}"
        >
            <span class="owwa-account-tabs-glider" aria-hidden="true"></span>
            <a
                href="{{ $profileUrl }}"
                @class(['owwa-account-tab', 'owwa-account-tab--active' => $activeTab === 'profile'])
                wire:navigate
                @if ($activeTab === 'profile') aria-current="page" @endif
                @click="$el.closest('.owwa-account-tabs').dataset.activeTab = 'profile'"
            >
                Profile
            </a>
            <a
                href="{{ $settingsUrl }}"
                @class(['owwa-account-tab', 'owwa-account-tab--active' => $activeTab === 'settings'])
                wire:navigate
                @if ($activeTab === 'settings') aria-current="page" @endif
                @click="$el.closest('.owwa-account-tabs').dataset.activeTab = 'settings'"
            >
                Settings
            </a>
        </nav>

        <div class="owwa-account-header-main">
            <div class="owwa-account-header-copy">
                @if (filled($heading))
                    <h1 class="owwa-account-header-title">{{ $heading }}</h1>
                @endif
                @if (filled($subheading))
                    <p class="owwa-account-header-subtitle">{{ $subheading }}</p>
                @endif
            </div>

            @if (! empty($actions))
                <div class="owwa-account-header-actions">
                    @foreach ($actions as $action)
                        {{ $action }}
                    @endforeach
                </div>
            @endif
        </div>
    </div>
</div>
