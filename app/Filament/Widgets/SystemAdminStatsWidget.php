<?php

namespace App\Filament\Widgets;

use App\Models\Department;
use App\Models\Office;
use App\Models\User;
use App\Models\UserLog;
use Filament\Facades\Filament;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class SystemAdminStatsWidget extends StatsOverviewWidget
{
    protected static ?int $sort = 0;

    protected static bool $isLazy = true;

    protected int|array|null $columns = 4;

    public static function canView(): bool
    {
        $user = Filament::auth()->user();

        return $user?->isSystemAdmin() ?? false;
    }

    protected function getStats(): array
    {
        $totalUsers = User::count();
        $totalOffices = Office::query()->active()->count();
        $totalDepartments = Department::query()->active()->count();

        $recentLogins = UserLog::where('logged_in_at', '>=', now()->subDays(7))->count();

        $roleBreakdown = User::selectRaw('role, count(*) as total')
            ->groupBy('role')
            ->pluck('total', 'role');

        $custodians = $roleBreakdown[User::ROLE_SUPPLY_CUSTODIAN] ?? 0;
        $unitConsolidators = $roleBreakdown[User::ROLE_UNIT_CONSOLIDATOR] ?? 0;
        $employees = $roleBreakdown[User::ROLE_EMPLOYEE] ?? 0;

        return [
            Stat::make('Total users', number_format($totalUsers))
                ->description("{$custodians} custodian, {$unitConsolidators} unit consolidator, {$employees} employee")
                ->descriptionIcon('heroicon-o-users')
                ->color('primary'),

            Stat::make('Offices', number_format($totalOffices))
                ->description('Active offices')
                ->descriptionIcon('heroicon-o-building-office-2')
                ->color('success'),

            Stat::make('Departments', number_format($totalDepartments))
                ->description('Active departments')
                ->descriptionIcon('heroicon-o-user-group')
                ->color('info'),

            Stat::make('Logins (7 days)', number_format($recentLogins))
                ->description('Recent authenticated sessions')
                ->descriptionIcon('heroicon-o-shield-check')
                ->color('gray'),
        ];
    }
}
