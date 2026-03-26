<?php

namespace App\Filament\Widgets;

use App\Models\Department;
use App\Models\Issuance;
use App\Models\Item;
use App\Services\FiscalYearService;
use App\Services\InventoryStockService;
use Filament\Facades\Filament;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class LowStockWidget extends StatsOverviewWidget
{
    protected static ?int $sort = 1;

    protected int | array | null $columns = 4;

    public static function canView(): bool
    {
        $user = Filament::auth()->user();

        return $user && ! $user->isSystemAdmin();
    }

    protected function getStats(): array
    {
        $user = Filament::auth()->user();
        $officeIds = null;
        $scopeLabel = null;
        if ($user && ! $user->isSupplyCustodian() && $user->office_id) {
            $officeIds = [(int) $user->office_id];
            $scopeLabel = ' (your office)';
        }

        $fiscal = app(FiscalYearService::class);
        $fyId = $fiscal->current()?->id;

        $stockService = app(InventoryStockService::class);
        $lowStockCount = $stockService->lowStockCount($officeIds, $fyId);

        $issuancesQuery = Issuance::query();
        $issuancesQuery->whereMonth('issuance_date', now()->month)
            ->whereYear('issuance_date', now()->year);
        if ($officeIds !== null) {
            $issuancesQuery->whereIn('office_id', $officeIds);
        }
        $issuancesThisMonth = $issuancesQuery->sum('quantity');

        $totalItems = Item::forFiscalYear($fyId)->active()->count();

        $stats = [
            Stat::make('Low stock', $lowStockCount)
                ->description(($lowStockCount > 0 ? 'Below reorder point' : 'All stocks healthy') . ($scopeLabel ?? ''))
                ->descriptionIcon($lowStockCount > 0 ? 'heroicon-o-exclamation-triangle' : 'heroicon-o-check-circle')
                ->color($lowStockCount > 0 ? 'warning' : 'success'),

            Stat::make('Total items', number_format($totalItems))
                ->description('Items in catalog')
                ->descriptionIcon('heroicon-o-archive-box')
                ->color('primary'),

            Stat::make('Issued this month', number_format($issuancesThisMonth))
                ->description(now()->format('M Y') . ' issuances' . ($scopeLabel ?? ''))
                ->descriptionIcon('heroicon-o-arrow-up-tray')
                ->color('info'),
        ];

        if ($officeIds === null) {
            $departments = Department::forFiscalYear($fyId)->active()->count();
            $stats[] = Stat::make('Departments', number_format($departments))
                ->description('Active departments')
                ->descriptionIcon('heroicon-o-building-office-2')
                ->color('gray');
        }

        return $stats;
    }
}
