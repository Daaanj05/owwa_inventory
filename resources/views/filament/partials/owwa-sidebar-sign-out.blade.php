@php
    use App\Models\User;
    use Filament\Support\Icons\Heroicon;

    $user = filament()->auth()->user();
    $showSignOut = $user instanceof User
        && ($user->isEmployee() || $user->isUnitConsolidator());
@endphp

@if ($showSignOut)
    <div class="owwa-sidebar-sign-out">
        <ul class="fi-sidebar-nav-groups">
            <li class="fi-sidebar-group">
                <ul class="fi-sidebar-group-items">
                    <li class="fi-sidebar-item fi-sidebar-item-has-url">
                        <form method="post" action="{{ filament()->getLogoutUrl() }}" class="owwa-sidebar-sign-out-form">
                            @csrf
                            <button type="submit" class="fi-sidebar-item-btn owwa-sidebar-sign-out-btn">
                                <x-filament::icon
                                    :icon="Heroicon::OutlinedArrowLeftEndOnRectangle"
                                    class="fi-icon fi-size-lg fi-sidebar-item-icon owwa-sidebar-sign-out-icon"
                                />
                                <span class="fi-sidebar-item-label owwa-sidebar-sign-out-label">
                                    {{ __('filament-panels::layout.actions.logout.label') }}
                                </span>
                            </button>
                        </form>
                    </li>
                </ul>
            </li>
        </ul>
    </div>
@endif
