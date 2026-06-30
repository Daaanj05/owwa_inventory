<?php

namespace App\Filament\Widgets;

use App\Services\ProcurementDecisionSupportService;
use Carbon\Carbon;
use Filament\Facades\Filament;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class CoverageOverviewWidget extends StatsOverviewWidget
{
    protected static ?int $sort = 2;

    protected static bool $isLazy = true;

    protected int|array|null $columns = 4;

    public ?string $from = null;

    public ?string $to = null;

    public ?string $categoryId = null;

    public static function canView(): bool
    {
        $user = Filament::auth()->user();

        return $user?->isSupplyCustodian() ?? false;
    }

    protected function getStats(): array
    {
        $user = Filament::auth()->user();
        $officeIds = $user?->office_id ? [(int) $user->office_id] : [];

        $from = $this->from
            ? Carbon::parse($this->from)->startOfDay()
            : now()->subMonths(11)->startOfMonth();

        $to = $this->to
            ? Carbon::parse($this->to)->endOfDay()
            : now()->endOfMonth();

        if ($from->gt($to)) {
            [$from, $to] = [$to->copy()->startOfDay(), $from->copy()->endOfDay()];
        }

        $categoryId = filled($this->categoryId) ? (int) $this->categoryId : null;

        $summary = app(ProcurementDecisionSupportService::class)->getCoverageSummary(
            $from,
            $to,
            $categoryId,
            $officeIds,
        );

        return [
            Stat::make('Total at-risk', number_format($summary['pairs']))
                ->description('Item×office pairs needing attention in this filter')
                ->icon('heroicon-o-queue-list')
                ->color($summary['pairs'] > 0 ? 'warning' : 'success'),
            Stat::make('High risk', number_format($summary['high_risk']))
                ->description('Under ~1 mo cover or at/below reorder')
                ->icon('heroicon-o-exclamation-triangle')
                ->color('danger'),
            Stat::make('Medium risk', number_format($summary['medium_risk']))
                ->description('About 1–3 months of cover')
                ->icon('heroicon-o-exclamation-circle')
                ->color('warning'),
            Stat::make('Avg cover', number_format($summary['avg_months_cover'], 1).' mo')
                ->description('Mean months of stock ÷ forecast (pairs with usage history)')
                ->icon('heroicon-o-clock')
                ->color($summary['avg_months_cover'] < 1 ? 'danger' : ($summary['avg_months_cover'] <= 3 ? 'warning' : 'success')),
        ];
    }
}
