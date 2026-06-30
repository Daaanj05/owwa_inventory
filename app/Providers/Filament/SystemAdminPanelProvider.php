<?php

namespace App\Providers\Filament;

use App\Filament\Pages\Auth\EditProfile;
use App\Filament\Pages\Auth\Login;
use App\Filament\Pages\Auth\ResetPassword;
use App\Filament\Pages\Dashboard;
use App\Filament\Widgets\WelcomeWidget;
use App\Http\Middleware\AdminExecutionTimeLimit;
use App\Http\Middleware\EnsurePasswordChanged;
use App\Http\Middleware\TouchUserSessionActivity;
use App\Livewire\OwwaNotificationDropdown;
use App\Support\FilamentSessionAudit;
use Filament\Enums\ThemeMode;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\View\PanelsRenderHook;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class SystemAdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->id('system-admin')
            ->path('system-admin')
            ->brandName('OWWA Inventory System — System Admin')
            ->favicon(asset('images/owwa-4a_logo_transparent.png'))
            ->login(Login::class)
            ->emailVerification()
            ->passwordReset(resetAction: ResetPassword::class)
            ->profile(EditProfile::class, isSimple: false)
            ->colors([
                'primary' => Color::Indigo,
            ])
            ->defaultThemeMode(ThemeMode::Light)
            ->darkMode(false)
            ->databaseNotifications(livewireComponent: OwwaNotificationDropdown::class, isLazy: false)
            ->databaseNotificationsPolling('30s')
            ->renderHook(PanelsRenderHook::STYLES_AFTER, function (): string {
                return '<link rel="stylesheet" href="'.asset('css/filament/admin/owwa-theme.css').'">';
            })
            ->renderHook(PanelsRenderHook::BODY_END, function (): string {
                return FilamentSessionAudit::idleLogoutMonitorHtml();
            }, scopes: ['authenticated'])
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\Filament\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\Filament\Pages')
            ->pages([
                Dashboard::class,
            ])
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\Filament\Widgets')
            ->widgets([
                WelcomeWidget::class,
            ])
            ->middleware([
                AdminExecutionTimeLimit::class,
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                VerifyCsrfToken::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
                TouchUserSessionActivity::class,
            ])
            ->authMiddleware([
                Authenticate::class,
                EnsurePasswordChanged::class,
            ]);
    }
}
