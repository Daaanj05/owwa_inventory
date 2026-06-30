<?php

namespace App\Filament\Widgets;

use App\Models\Department;
use App\Models\Office;
use App\Services\AnalyticsDateRangeService;
use App\Services\ConsumptionAnalyticsService;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Schemas\Schema;
use Filament\Widgets\ChartWidget;
use Filament\Widgets\ChartWidget\Concerns\HasFiltersSchema;

class ConsumptionSharePieWidget extends ChartWidget
{
    use HasFiltersSchema;

    protected static ?int $sort = 3;

    protected static bool $isLazy = true;

    protected string $view = 'filament.widgets.consumption-share-pie-widget';

    protected int|string|array $columnSpan = 1;

    protected ?string $heading = 'Consumption share';

    protected ?string $description = 'Share of total issued units per department. Includes all offices (regional and satellite) when All offices is selected.';

    protected bool $hasDeferredFilters = true;

    protected ?string $maxHeight = '320px';

    public static function canView(): bool
    {
        $user = Filament::auth()->user();

        return $user?->isSupplyCustodian() ?? false;
    }

    /**
     * Same palette as trend widget for consistency.
     *
     * @var array<int, string>
     */
    protected static array $chartColors = [
        '#1e6fbe',
        '#b5192f',
        '#0e9c6d',
        '#d97706',
        '#7c3aed',
        '#0284c7',
        '#be123c',
        '#059669',
        '#b45309',
        '#6d28d9',
    ];

    public function mount(): void
    {
        parent::mount();
        $this->mountHasFiltersSchema();
    }

    public function filtersSchema(Schema $schema): Schema
    {
        $user = Filament::auth()->user();
        $scope = $user?->getConsumptionScope() ?? ['office_ids' => [], 'department_ids' => []];
        $officeBase = Office::query()->active()->orderBy('name');
        $officeOptions = $scope['office_ids'] !== []
            ? (clone $officeBase)->whereIn('id', $scope['office_ids'])->pluck('name', 'id')
            : $officeBase->pluck('name', 'id');
        $departmentBase = Department::query()->active()->orderBy('name');
        $departmentOptions = $scope['department_ids'] !== []
            ? (clone $departmentBase)->whereIn('id', $scope['department_ids'])->pluck('name', 'id')
            : $departmentBase->pluck('name', 'id');

        $dateRange = app(AnalyticsDateRangeService::class)->getRangeForScope(AnalyticsDateRangeService::SCOPE_CURRENT_YEAR);

        return $schema->components([
            Select::make('analytics_scope')
                ->label('Analysis scope')
                ->options([
                    AnalyticsDateRangeService::SCOPE_CURRENT_YEAR => 'Current calendar year',
                    AnalyticsDateRangeService::SCOPE_LONG_VIEW => 'Multi-year (up to 5 years)',
                ])
                ->default(AnalyticsDateRangeService::SCOPE_CURRENT_YEAR)
                ->live()
                ->afterStateUpdated(function ($state, callable $set): void {
                    $range = app(AnalyticsDateRangeService::class)->getRangeForScope($state ?: AnalyticsDateRangeService::SCOPE_CURRENT_YEAR);
                    $set('date_from', $range['from']->toDateString());
                    $set('date_to', $range['to']->toDateString());
                }),
            DatePicker::make('date_from')
                ->label('From')
                ->default($dateRange['from']->toDateString())
                ->maxDate(fn () => $this->deferredFilters['date_to'] ?? now()),
            DatePicker::make('date_to')
                ->label('To')
                ->default($dateRange['to']->toDateString())
                ->minDate(fn () => $this->deferredFilters['date_from'] ?? now()->subMonths(11)),
            Select::make('department_ids')
                ->label('Departments')
                ->multiple()
                ->options($departmentOptions)
                ->placeholder('All departments'),
            Select::make('office_ids')
                ->label('Offices')
                ->multiple()
                ->options($officeOptions)
                ->placeholder('All offices'),
        ]);
    }

    protected function getData(): array
    {
        $f = $this->filters;
        $resolved = app(AnalyticsDateRangeService::class)->resolveFromWidgetFilters($f);
        $from = $resolved['from'];
        $to = $resolved['to'];
        $includeYearLabels = $resolved['includeYearInLabels'];
        $departmentIds = array_filter($f['department_ids'] ?? []);
        $officeIds = array_filter($f['office_ids'] ?? []);

        $user = Filament::auth()->user();
        if ($user) {
            $scope = $user->getConsumptionScope();
            if ($scope['office_ids'] !== [] || $scope['department_ids'] !== []) {
                $officeIds = $scope['office_ids'];
                $departmentIds = $scope['department_ids'];
            }
        }

        $result = app(ConsumptionAnalyticsService::class)->getConsumptionTotalsByDepartment(
            $from,
            $to,
            $departmentIds,
            $officeIds,
            $includeYearLabels
        );

        if (empty($result['labels']) || $result['total'] === 0) {
            return [
                'datasets' => [['data' => [], 'backgroundColor' => [], 'borderColor' => '#e5e7eb']],
                'labels' => [],
            ];
        }

        $colors = self::$chartColors;
        $backgroundColors = [];
        $borderColors = [];
        foreach (array_keys($result['labels']) as $i) {
            $c = $colors[$i % count($colors)];
            $backgroundColors[] = $c;
            $borderColors[] = '#ffffff';
        }

        return [
            'datasets' => [
                [
                    'data' => $result['values'],
                    'backgroundColor' => $backgroundColors,
                    'borderColor' => $borderColors,
                    'borderWidth' => 3,
                    'hoverOffset' => 6,
                ],
            ],
            'labels' => $result['labels'],
        ];
    }

    protected function getType(): string
    {
        return 'doughnut';
    }

    protected function getOptions(): ?array
    {
        return [
            'cutout' => '62%',
            'plugins' => [
                'legend' => [
                    'display' => true,
                    'position' => 'bottom',
                    'align' => 'center',
                    'labels' => [
                        'boxWidth' => 8,
                        'boxHeight' => 8,
                        'borderRadius' => 4,
                        'padding' => 16,
                        'usePointStyle' => true,
                        'pointStyle' => 'circle',
                        'color' => '#475569',
                        'font' => ['size' => 11, 'weight' => '500'],
                    ],
                ],
                'tooltip' => [
                    'backgroundColor' => 'rgba(15,23,42,0.88)',
                    'titleColor' => '#f8fafc',
                    'bodyColor' => '#cbd5e1',
                    'borderColor' => 'rgba(255,255,255,0.08)',
                    'borderWidth' => 1,
                    'padding' => ['x' => 12, 'y' => 8],
                    'cornerRadius' => 8,
                ],
            ],
        ];
    }
}
