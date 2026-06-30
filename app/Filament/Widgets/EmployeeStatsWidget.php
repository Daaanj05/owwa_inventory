<?php

namespace App\Filament\Widgets;

use App\Filament\Concerns\ListensForRequisitionBroadcasts;
use App\Models\Distribution;
use App\Models\Requisition;
use Filament\Facades\Filament;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class EmployeeStatsWidget extends StatsOverviewWidget
{
    use ListensForRequisitionBroadcasts;

    protected static ?int $sort = 0;

    protected static bool $isLazy = true;

    public static function canView(): bool
    {
        $user = Filament::auth()->user();

        return $user?->isEmployee() ?? false;
    }

    protected function getStats(): array
    {
        $user = Filament::auth()->user();
        if (! $user) {
            return [];
        }

        $yearStart = now()->startOfYear();
        $yearEnd = now()->endOfYear();

        $baseQuery = fn () => Requisition::query()
            ->where('requested_by', $user->id)
            ->whereBetween('created_at', [$yearStart, $yearEnd]);

        $totalRequests = $baseQuery()->count();
        $pending = $baseQuery()->where('status', Requisition::STATUS_PENDING)->count();

        $received = Distribution::query()
            ->where('distributed_to', $user->id)
            ->whereBetween('distribution_date', [$yearStart, $yearEnd])
            ->sum('quantity');

        $distinctItems = Distribution::query()
            ->where('distributed_to', $user->id)
            ->whereBetween('distribution_date', [$yearStart, $yearEnd])
            ->distinct('item_id')
            ->count('item_id');

        return [
            Stat::make('Requests sent', $totalRequests)
                ->description('Total requests this year')
                ->descriptionIcon('heroicon-o-document-text')
                ->color('primary'),

            Stat::make('Pending', $pending)
                ->description($pending > 0 ? 'Awaiting consolidator review' : 'All reviewed')
                ->descriptionIcon($pending > 0 ? 'heroicon-o-clock' : 'heroicon-o-check-circle')
                ->color($pending > 0 ? 'warning' : 'success'),

            Stat::make('Items received', number_format($received))
                ->description("{$distinctItems} distinct ".($distinctItems === 1 ? 'item' : 'items').' distributed to you')
                ->descriptionIcon('heroicon-o-inbox-arrow-down')
                ->color('info'),
        ];
    }
}
