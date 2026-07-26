<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\Departments\DepartmentResource;
use App\Filament\Resources\Offices\OfficeResource;
use App\Filament\Resources\UserLogs\UserLogResource;
use App\Filament\Resources\Users\UserResource;
use App\Models\Department;
use App\Models\Office;
use App\Models\User;
use App\Models\UserLog;
use Filament\Actions\Action;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Facades\Filament;
use Filament\Support\Enums\Width;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\HtmlString;

class SystemAdminStatsWidget extends StatsOverviewWidget implements HasActions
{
    use InteractsWithActions;

    protected static ?int $sort = 0;

    protected static bool $isLazy = false;

    protected ?string $pollingInterval = null;

    protected int|array|null $columns = 4;

    protected string $view = 'filament.widgets.employee-stats-widget';

    public int $kpiUsersPage = 1;

    public int $kpiOfficesPage = 1;

    public int $kpiDepartmentsPage = 1;

    public int $kpiLoginsPage = 1;

    protected const int KPI_PER_PAGE = 15;

    public static function canView(): bool
    {
        $user = Filament::auth()->user();

        return $user?->isSystemAdmin() ?? false;
    }

    public function setKpiPage(string $key, int $page): void
    {
        $page = max(1, $page);

        match ($key) {
            'users' => $this->kpiUsersPage = $page,
            'offices' => $this->kpiOfficesPage = $page,
            'departments' => $this->kpiDepartmentsPage = $page,
            'logins' => $this->kpiLoginsPage = $page,
            default => null,
        };
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
                ->color('primary')
                ->extraAttributes([
                    'class' => 'cursor-pointer owwa-stat-clickable',
                    'wire:click' => "mountAction('viewTotalUsers')",
                    'title' => 'Click to view details',
                ], merge: true),

            Stat::make('Offices', number_format($totalOffices))
                ->description('Active offices')
                ->descriptionIcon('heroicon-o-building-office-2')
                ->color('success')
                ->extraAttributes([
                    'class' => 'cursor-pointer owwa-stat-clickable',
                    'wire:click' => "mountAction('viewOffices')",
                    'title' => 'Click to view details',
                ], merge: true),

            Stat::make('Sub-Office/Departments', number_format($totalDepartments))
                ->description('Active sub-offices/departments')
                ->descriptionIcon('heroicon-o-user-group')
                ->color('info')
                ->extraAttributes([
                    'class' => 'cursor-pointer owwa-stat-clickable',
                    'wire:click' => "mountAction('viewDepartments')",
                    'title' => 'Click to view details',
                ], merge: true),

            Stat::make('Logins (7 days)', number_format($recentLogins))
                ->description('Recent authenticated sessions')
                ->descriptionIcon('heroicon-o-shield-check')
                ->color('gray')
                ->extraAttributes([
                    'class' => 'cursor-pointer owwa-stat-clickable',
                    'wire:click' => "mountAction('viewRecentLogins')",
                    'title' => 'Click to view details',
                ], merge: true),
        ];
    }

    public function viewTotalUsersAction(): Action
    {
        return $this->detailModalAction(
            'viewTotalUsers',
            'Total users',
            fn (): array => $this->usersDetail(),
            UserResource::getUrl('index'),
            'Open Users',
        );
    }

    public function viewOfficesAction(): Action
    {
        return $this->detailModalAction(
            'viewOffices',
            'Active offices',
            fn (): array => $this->officesDetail(),
            OfficeResource::getUrl('index'),
            'Open Offices',
        );
    }

    public function viewDepartmentsAction(): Action
    {
        return $this->detailModalAction(
            'viewDepartments',
            'Active sub-offices/departments',
            fn (): array => $this->departmentsDetail(),
            DepartmentResource::getUrl('index'),
            'Open Departments',
        );
    }

    public function viewRecentLoginsAction(): Action
    {
        return $this->detailModalAction(
            'viewRecentLogins',
            'Logins (last 7 days)',
            fn (): array => $this->loginsDetail(),
            UserLogResource::getUrl('index'),
            'Open Login Audit Logs',
        );
    }

    /**
     * @param  callable(): array<string, mixed>  $detailResolver
     */
    protected function detailModalAction(
        string $name,
        string $heading,
        callable $detailResolver,
        string $pageUrl,
        string $pageLabel,
    ): Action {
        return Action::make($name)
            ->modalWidth(Width::FiveExtraLarge)
            ->extraModalWindowAttributes(['class' => 'owwa-view-record-modal'])
            ->modalSubmitAction(false)
            ->modalCancelActionLabel('Close')
            ->modalHeading($heading)
            ->modalContent(fn (): HtmlString => new HtmlString(view(
                'filament.widgets.partials.employee-stats-detail-modal',
                ['detail' => $detailResolver()],
            )->render()))
            ->extraModalFooterActions([
                Action::make('openPage')
                    ->label($pageLabel)
                    ->url($pageUrl)
                    ->color('primary')
                    ->icon('heroicon-m-arrow-top-right-on-square'),
            ]);
    }

    /**
     * @return array<string, mixed>
     */
    protected function usersDetail(): array
    {
        $paginator = User::query()
            ->with(['office', 'department'])
            ->orderBy('name')
            ->paginate(self::KPI_PER_PAGE, page: $this->kpiUsersPage);

        return [
            'summary' => number_format($paginator->total()).' user'.($paginator->total() === 1 ? '' : 's').'.',
            'empty_title' => 'No users',
            'empty_desc' => 'No user accounts have been created yet.',
            'columns' => [
                'name' => 'Name',
                'email' => 'Email',
                'role' => 'Role',
                'office' => 'Office',
            ],
            'numeric_keys' => [],
            'rows' => collect($paginator->items())->map(fn (User $user): array => [
                'name' => $user->name,
                'email' => $user->email,
                'role' => match ($user->role) {
                    User::ROLE_SYSTEM_ADMIN => 'System Admin',
                    User::ROLE_SUPPLY_CUSTODIAN => 'Supply Custodian',
                    User::ROLE_UNIT_CONSOLIDATOR => 'Unit Consolidator',
                    User::ROLE_EMPLOYEE => 'Employee',
                    default => $user->role,
                },
                'office' => $user->assignmentOfficesSummary() ?? $user->office?->name,
                'record_url' => UserResource::viewModalUrl($user),
            ])->all(),
            'pagination' => [
                'key' => 'users',
                'current' => $paginator->currentPage(),
                'last' => max(1, $paginator->lastPage()),
                'total' => $paginator->total(),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function officesDetail(): array
    {
        $paginator = Office::query()
            ->active()
            ->orderBy('name')
            ->paginate(self::KPI_PER_PAGE, page: $this->kpiOfficesPage);

        return [
            'summary' => number_format($paginator->total()).' active office'.($paginator->total() === 1 ? '' : 's').'.',
            'empty_title' => 'No offices',
            'empty_desc' => 'No active offices found.',
            'columns' => [
                'name' => 'Office',
                'code' => 'Code',
            ],
            'numeric_keys' => [],
            'rows' => collect($paginator->items())->map(fn (Office $office): array => [
                'name' => $office->name,
                'code' => $office->code,
                'record_url' => OfficeResource::viewModalUrl($office),
            ])->all(),
            'pagination' => [
                'key' => 'offices',
                'current' => $paginator->currentPage(),
                'last' => max(1, $paginator->lastPage()),
                'total' => $paginator->total(),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function departmentsDetail(): array
    {
        $paginator = Department::query()
            ->active()
            ->with('office')
            ->orderBy('name')
            ->paginate(self::KPI_PER_PAGE, page: $this->kpiDepartmentsPage);

        return [
            'summary' => number_format($paginator->total()).' active sub-office/department'.($paginator->total() === 1 ? '' : 's').'.',
            'empty_title' => 'No departments',
            'empty_desc' => 'No active sub-offices/departments found.',
            'columns' => [
                'name' => 'Sub-Office/Department',
                'office' => 'Office',
                'code' => 'Code',
            ],
            'numeric_keys' => [],
            'rows' => collect($paginator->items())->map(fn (Department $department): array => [
                'name' => $department->name,
                'office' => $department->office?->name,
                'code' => $department->code,
                'record_url' => DepartmentResource::viewModalUrl($department),
            ])->all(),
            'pagination' => [
                'key' => 'departments',
                'current' => $paginator->currentPage(),
                'last' => max(1, $paginator->lastPage()),
                'total' => $paginator->total(),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function loginsDetail(): array
    {
        $paginator = UserLog::query()
            ->with('user')
            ->where('logged_in_at', '>=', now()->subDays(7))
            ->latest('logged_in_at')
            ->paginate(self::KPI_PER_PAGE, page: $this->kpiLoginsPage);

        return [
            'summary' => number_format($paginator->total()).' login'.($paginator->total() === 1 ? '' : 's').' in the last 7 days.',
            'empty_title' => 'No recent logins',
            'empty_desc' => 'No authenticated sessions were recorded in the last 7 days.',
            'columns' => [
                'user' => 'User',
                'panel' => 'Panel',
                'logged_in' => 'Logged in',
                'ip' => 'IP',
            ],
            'numeric_keys' => [],
            'rows' => collect($paginator->items())->map(fn (UserLog $log): array => [
                'user' => $log->user?->name ?? $log->user?->email,
                'panel' => $log->panel,
                'logged_in' => optional($log->logged_in_at)?->format('M j, Y g:i A'),
                'ip' => $log->ip_address,
                'record_url' => UserLogResource::viewModalUrl($log),
            ])->all(),
            'pagination' => [
                'key' => 'logins',
                'current' => $paginator->currentPage(),
                'last' => max(1, $paginator->lastPage()),
                'total' => $paginator->total(),
            ],
        ];
    }
}
