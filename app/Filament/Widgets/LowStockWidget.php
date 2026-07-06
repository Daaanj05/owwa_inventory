<?php

namespace App\Filament\Widgets;

use App\Models\Issuance;
use App\Models\Item;
use App\Models\Requisition;
use App\Models\User;
use App\Services\InventoryStockService;
use Filament\Facades\Filament;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Database\Eloquent\Builder;

class LowStockWidget extends StatsOverviewWidget
{
    protected static ?int $sort = 1;

    protected static bool $isLazy = false;

    protected int|array|null $columns = 2;

    public static function canView(): bool
    {
        $user = Filament::auth()->user();

        return $user && ! $user->isSystemAdmin() && ! $user->isEmployee();
    }

    /**
     * @return int|array<string, ?int>|null
     */
    protected function getColumns(): int|array|null
    {
        $user = Filament::auth()->user();

        if ($user?->isSupplyCustodian()) {
            return 4;
        }

        return $this->columns;
    }

    protected function getStats(): array
    {
        /** @var User|null $user */
        $user = Filament::auth()->user();
        if (! $user) {
            return [];
        }

        $officeIds = $user?->office_id ? [(int) $user->office_id] : null;
        $scopeLabel = ($user && ! $user->isSupplyCustodian() && $user->office_id) ? ' (your office)' : '';

        $stockService = app(InventoryStockService::class);
        $lowStockCount = $stockService->lowStockCount($officeIds);

        if ($user->isSupplyCustodian()) {
            return $this->buildSupplyCustodianStats($stockService, $lowStockCount, $scopeLabel);
        }

        return [
            Stat::make('Low stock', $lowStockCount)
                ->description(($lowStockCount > 0 ? 'Below reorder point' : 'All stocks healthy').$scopeLabel)
                ->descriptionIcon($lowStockCount > 0 ? 'heroicon-o-exclamation-triangle' : 'heroicon-o-check-circle')
                ->color($lowStockCount > 0 ? 'warning' : 'success')
                ->extraAttributes(['class' => 'owwa-kpi-square'], true),

            $this->buildIssuedThisMonthStat($scopeLabel, $officeIds),
        ];
    }

    /**
     * @return array<Stat>
     */
    protected function buildSupplyCustodianStats(InventoryStockService $stockService, int $lowStockCount, string $scopeLabel): array
    {
        $itemsInTotal = Item::query()->active()->count();
        $stocksInHand = (int) $stockService->getStockLevelsList()->sum('stock');

        return [
            Stat::make('Items in total', number_format($itemsInTotal))
                ->description('Registered items in catalog')
                ->descriptionIcon('heroicon-o-cube')
                ->color('primary')
                ->extraAttributes(['class' => 'owwa-kpi-square'], true),

            Stat::make('Stocks in hand', number_format($stocksInHand))
                ->description('Total quantity on hand')
                ->descriptionIcon('heroicon-o-archive-box')
                ->color('info')
                ->extraAttributes(['class' => 'owwa-kpi-square'], true),

            Stat::make('Low stock', $lowStockCount)
                ->description(($lowStockCount > 0 ? 'Below reorder point' : 'All stocks healthy').$scopeLabel)
                ->descriptionIcon($lowStockCount > 0 ? 'heroicon-o-exclamation-triangle' : 'heroicon-o-check-circle')
                ->color($lowStockCount > 0 ? 'warning' : 'success')
                ->extraAttributes(['class' => 'owwa-kpi-square'], true),

            $this->buildPendingRequisitionsStat(),
        ];
    }

    protected function buildPendingRequisitionsStat(): Stat
    {
        $pendingCount = Requisition::query()
            ->where('status', Requisition::STATUS_PENDING)
            ->whereHas('requestedBy', function (Builder $q): void {
                $q->where('role', User::ROLE_UNIT_CONSOLIDATOR);
            })
            ->count();

        if ($pendingCount > 0) {
            return Stat::make('Pending requisitions', $pendingCount)
                ->description($pendingCount.' '.str('requisition')->plural($pendingCount).' awaiting your action')
                ->descriptionIcon('heroicon-o-bell-alert')
                ->color('warning')
                ->extraAttributes(['class' => 'owwa-kpi-square'], true);
        }

        return Stat::make('Pending requisitions', 0)
            ->description('No pending requisitions')
            ->descriptionIcon('heroicon-o-check-circle')
            ->color('success')
            ->extraAttributes(['class' => 'owwa-kpi-square'], true);
    }

    protected function buildIssuedThisMonthStat(string $scopeLabel, ?array $officeIds): Stat
    {
        $issuancesQuery = Issuance::query();
        $issuancesQuery->whereMonth('issuance_date', now()->month)
            ->whereYear('issuance_date', now()->year);
        if ($officeIds !== null) {
            $issuancesQuery->whereIn('office_id', $officeIds);
        }
        $issuancesThisMonth = $issuancesQuery->sum('quantity');

        return Stat::make('Issued this month', number_format($issuancesThisMonth))
            ->description(now()->format('M Y').' issuances'.$scopeLabel)
            ->descriptionIcon('heroicon-o-arrow-up-tray')
            ->color('info')
            ->extraAttributes(['class' => 'owwa-kpi-square'], true);
    }
}
