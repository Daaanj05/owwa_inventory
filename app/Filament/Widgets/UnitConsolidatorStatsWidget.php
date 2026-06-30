<?php

namespace App\Filament\Widgets;

use App\Filament\Concerns\ListensForRequisitionBroadcasts;
use App\Models\Distribution;
use App\Models\Requisition;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Database\Eloquent\Builder;

class UnitConsolidatorStatsWidget extends StatsOverviewWidget
{
    use ListensForRequisitionBroadcasts;

    protected static ?int $sort = 0;

    protected static bool $isLazy = true;

    public static function canView(): bool
    {
        $user = Filament::auth()->user();

        return $user?->isUnitConsolidator() ?? false;
    }

    protected function getStats(): array
    {
        $user = Filament::auth()->user();
        if (! $user) {
            return [];
        }

        $yearStart = now()->startOfYear();
        $yearEnd = now()->endOfYear();

        $pendingEmployeeRequests = Requisition::query()
            ->where('status', Requisition::STATUS_PENDING)
            ->whereHas('requestedBy', fn (Builder $q) => $q->where('role', User::ROLE_EMPLOYEE))
            ->when($user->office_id, fn ($q) => $q->where('office_id', $user->office_id))
            ->when($user->department_id, fn ($q) => $q->where('department_id', $user->department_id))
            ->whereBetween('created_at', [$yearStart, $yearEnd])
            ->count();

        $myPendingToSc = Requisition::query()
            ->where('status', Requisition::STATUS_PENDING)
            ->where('requested_by', $user->id)
            ->whereBetween('created_at', [$yearStart, $yearEnd])
            ->count();

        $acceptedBySc = Requisition::query()
            ->where('status', Requisition::STATUS_ACCEPTED)
            ->where('requested_by', $user->id)
            ->whereBetween('created_at', [$yearStart, $yearEnd])
            ->count();

        $nearingEul = app(\App\Services\OfficePropertyRegisterService::class)->countNearingExpiryForUser($user);

        $distributed = Distribution::query()
            ->where('distributed_by', $user->id)
            ->whereBetween('distribution_date', [$yearStart, $yearEnd])
            ->sum('quantity');

        return [
            Stat::make('Pending employee requests', $pendingEmployeeRequests)
                ->description($pendingEmployeeRequests > 0 ? 'Employee requests awaiting action' : 'No pending requests')
                ->descriptionIcon($pendingEmployeeRequests > 0 ? 'heroicon-o-clock' : 'heroicon-o-check-circle')
                ->color($pendingEmployeeRequests > 0 ? 'warning' : 'success'),

            Stat::make('Sent to Supply Custodian', $myPendingToSc)
                ->description($myPendingToSc > 0 ? 'Pending approval from SC' : 'All requisitions actioned')
                ->descriptionIcon('heroicon-o-paper-airplane')
                ->color($myPendingToSc > 0 ? 'warning' : 'gray'),

            Stat::make('Accepted by SC', $acceptedBySc)
                ->description('Accepted requisitions this year')
                ->descriptionIcon('heroicon-o-check-badge')
                ->color('success'),

            Stat::make('Property nearing EUL', $nearingEul)
                ->description($nearingEul > 0 ? 'Semi-expendable useful life review' : 'No semi property nearing expiry')
                ->descriptionIcon($nearingEul > 0 ? 'heroicon-o-clock' : 'heroicon-o-check-circle')
                ->color($nearingEul > 0 ? 'warning' : 'gray'),

            Stat::make('Items distributed', number_format($distributed))
                ->description('Total items distributed this year')
                ->descriptionIcon('heroicon-o-gift')
                ->color('info'),
        ];
    }
}
