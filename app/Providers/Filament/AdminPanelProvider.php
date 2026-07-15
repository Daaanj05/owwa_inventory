<?php

namespace App\Providers\Filament;

use App\Filament\Pages\Auth\AccountSettings;
use App\Filament\Pages\Auth\EditProfile;
use App\Filament\Pages\Auth\Login;
use App\Filament\Pages\Auth\RequestPasswordReset;
use App\Filament\Pages\Auth\ResetPassword;
use App\Filament\Pages\Dashboard;
use App\Filament\Pages\EmployeeCustody;
use App\Filament\Pages\InventoryCategoryDashboard;
use App\Filament\Pages\OfficePropertyRegister;
use App\Filament\Resources\Distributions\DistributionResource;
use App\Filament\Resources\IncidentReports\IncidentReportResource;
use App\Filament\Widgets\ConsumptionSharePieWidget;
use App\Filament\Widgets\ConsumptionTrendsWidget;
use App\Filament\Widgets\EmployeeStatsWidget;
use App\Filament\Widgets\LowStockWidget;
use App\Filament\Widgets\RecentAcquisitionsWidget;
use App\Filament\Widgets\SystemAdminStatsWidget;
use App\Filament\Widgets\TopAcquiredProductsWidget;
use App\Filament\Widgets\UnitConsolidatorStatsWidget;
use App\Filament\Widgets\WelcomeWidget;
use App\Http\Middleware\AdminExecutionTimeLimit;
use App\Http\Middleware\AuthenticateFilamentPanel;
use App\Http\Middleware\EnsurePasswordChanged;
use App\Http\Middleware\TouchUserSessionActivity;
use App\Livewire\OwwaNotificationDropdown;
use App\Models\ItemCategory;
use App\Support\FilamentSessionAudit;
use App\Support\InventoryCategoryOptions;
use Filament\Actions\Action;
use Filament\Enums\ThemeMode;
use Filament\Facades\Filament;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Navigation\NavigationGroup;
use Filament\Navigation\NavigationItem;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Icons\Heroicon;
use Filament\View\PanelsRenderHook;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Schema;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        $panel = $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->brandName('OWWA Region IV-A Inventory System')
            ->favicon('/images/owwa-4a_logo_transparent.png')
            ->login(Login::class)
            ->emailVerification()
            ->passwordReset(requestAction: RequestPasswordReset::class, resetAction: ResetPassword::class)
            ->profile(EditProfile::class, isSimple: false)
            ->userMenuItems([
                'profile' => fn (Action $action): Action => $action->label('Profile'),
                'settings' => fn (): Action => Action::make('settings')
                    ->label('Settings')
                    ->icon(Heroicon::OutlinedCog6Tooth)
                    ->url(fn (): string => AccountSettings::getUrl()),
            ])
            ->colors([
                // Filament\Support\Colors\Color::Blue (inlined to avoid IDE false positives).
                'primary' => [
                    50 => 'oklch(0.97 0.014 254.604)',
                    100 => 'oklch(0.932 0.032 255.585)',
                    200 => 'oklch(0.882 0.059 254.128)',
                    300 => 'oklch(0.809 0.105 251.813)',
                    400 => 'oklch(0.707 0.165 254.624)',
                    500 => 'oklch(0.623 0.214 259.815)',
                    600 => 'oklch(0.546 0.245 262.881)',
                    700 => 'oklch(0.488 0.243 264.376)',
                    800 => 'oklch(0.424 0.199 265.638)',
                    900 => 'oklch(0.379 0.146 265.522)',
                    950 => 'oklch(0.282 0.091 267.935)',
                ],
            ])
            ->defaultThemeMode(ThemeMode::Light)
            ->darkMode(false)
            ->breadcrumbs(false);

        if (Schema::hasTable('notifications')) {
            $panel = $panel
                ->databaseNotifications(livewireComponent: OwwaNotificationDropdown::class, isLazy: false)
                ->databaseNotificationsPolling('30s');
        }

        return $panel
            ->renderHook(PanelsRenderHook::STYLES_AFTER, function (): string {
                return '<link rel="stylesheet" href="'.asset('css/filament/admin/owwa-theme.css').'">';
            })
            ->renderHook(PanelsRenderHook::BODY_END, function (): string {
                return FilamentSessionAudit::idleLogoutMonitorHtml();
            }, scopes: ['authenticated'])
            ->navigationGroups([
                NavigationGroup::make('My items'),
                NavigationGroup::make('Office'),
                NavigationGroup::make('Regional supply'),
                NavigationGroup::make('Requisitions'),
                NavigationGroup::make('Compliance'),
                NavigationGroup::make('Analytics'),
                NavigationGroup::make('Setup'),
            ])
            ->navigationItems($this->getNavigationItems())
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\Filament\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\Filament\Pages')
            ->pages([
                Dashboard::class,
            ])
            ->widgets([
                WelcomeWidget::class,
                UnitConsolidatorStatsWidget::class,
                EmployeeStatsWidget::class,
                SystemAdminStatsWidget::class,
                LowStockWidget::class,
                ConsumptionTrendsWidget::class,
                ConsumptionSharePieWidget::class,
                RecentAcquisitionsWidget::class,
                TopAcquiredProductsWidget::class,
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
                AuthenticateFilamentPanel::class,
                EnsurePasswordChanged::class,
            ]);
    }

    /** @return array<int, NavigationItem> */
    protected function getNavigationItems(): array
    {
        $items = [];

        if (! Schema::hasTable('item_categories')) {
            return $items;
        }

        $categoryItems = ItemCategory::query()
            ->whereNull('archived_at')
            ->whereNotIn('name', ['PPE', 'Power Plant Equipment'])
            ->get(['id', 'name'])
            ->sort(function (ItemCategory $left, ItemCategory $right): int {
                $leftWeight = $this->getCategoryNavigationWeight($left->name);
                $rightWeight = $this->getCategoryNavigationWeight($right->name);

                if ($leftWeight !== $rightWeight) {
                    return $leftWeight <=> $rightWeight;
                }

                return strcasecmp($left->name, $right->name);
            })
            ->values()
            ->map(
                fn (ItemCategory $category): NavigationItem => NavigationItem::make($category->name)
                    ->group('Regional supply')
                    ->icon(Heroicon::OutlinedArchiveBox)
                    ->sort(20 + $this->getCategoryNavigationWeight($category->name))
                    ->url(fn (): string => InventoryCategoryDashboard::getUrl(['category' => $category->id]))
                    ->visible(fn (): bool => Filament::auth()->check()
                        && ! Filament::auth()->user()?->isSystemAdmin()
                        && ! Filament::auth()->user()?->isEmployee()
                        && ! Filament::auth()->user()?->isUnitConsolidator())
                    ->isActiveWhen(
                        fn (): bool => request()->routeIs('filament.admin.pages.inventory-category-dashboard')
                        && (int) request()->query('category') === $category->id
                    )
            )
            ->all();

        $incidentNav = NavigationItem::make('Incident reports')
            ->group('Compliance')
            ->icon(Heroicon::OutlinedExclamationTriangle)
            ->sort(50)
            ->url(fn (): string => IncidentReportResource::getUrl())
            ->visible(fn (): bool => Filament::auth()->check()
                && (Filament::auth()->user()?->isSupplyCustodian() ?? false))
            ->isActiveWhen(fn (): bool => request()->routeIs('filament.admin.resources.incident-reports.*'));

        $defaultConsumablesCategoryId = InventoryCategoryOptions::defaultConsumablesCategoryId();

        $ucDistributionsNav = NavigationItem::make('Distributions')
            ->group('Office')
            ->icon(Heroicon::OutlinedGift)
            ->sort(10)
            ->url(fn (): string => DistributionResource::getUrl('index', ['category' => $defaultConsumablesCategoryId]))
            ->visible(fn (): bool => Filament::auth()->check()
                && (Filament::auth()->user()?->isUnitConsolidator() ?? false))
            ->isActiveWhen(fn (): bool => request()->routeIs('filament.admin.resources.distributions.*'));

        $ucRegistryNav = NavigationItem::make('Office Property Registry')
            ->group('Office')
            ->icon(Heroicon::OutlinedClipboardDocumentList)
            ->sort(11)
            ->url(fn (): string => OfficePropertyRegister::getUrl())
            ->visible(fn (): bool => Filament::auth()->check()
                && (Filament::auth()->user()?->isUnitConsolidator() ?? false))
            ->isActiveWhen(fn (): bool => request()->routeIs('filament.admin.pages.office-property-register'));

        $ucEmployeeCustodyNav = NavigationItem::make('Employee Custody')
            ->group('Office')
            ->icon(Heroicon::OutlinedUsers)
            ->sort(12)
            ->url(fn (): string => EmployeeCustody::getUrl())
            ->visible(fn (): bool => Filament::auth()->check()
                && (Filament::auth()->user()?->isUnitConsolidator() ?? false))
            ->isActiveWhen(fn (): bool => request()->routeIs('filament.admin.pages.employee-custody'));

        return [
            ...$items,
            ...$categoryItems,
            $ucDistributionsNav,
            $ucRegistryNav,
            $ucEmployeeCustodyNav,
            $incidentNav,
        ];
    }

    protected function getCategoryNavigationWeight(string $name): int
    {
        $normalized = strtolower(trim($name));

        return match (true) {
            in_array($normalized, ['consumables', 'consumable'], true) => 1,
            in_array($normalized, ['semi-expendable', 'semi expendable', 'semi_expendable'], true) => 2,
            in_array($normalized, [
                'ppe',
                'power plant equipment',
                'power_plant_equipment',
                'property, plant and equipment',
                'property plant and equipment',
            ], true) => 3,
            default => 10,
        };
    }
}
