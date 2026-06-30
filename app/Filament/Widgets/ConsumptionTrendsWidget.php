<?php

namespace App\Filament\Widgets;

use App\Models\Department;
use App\Models\Office;
use App\Services\AnalyticsDateRangeService;
use App\Services\ConsumptionAnalyticsService;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;
use Filament\Widgets\ChartWidget;
use Filament\Widgets\ChartWidget\Concerns\HasFiltersSchema;

class ConsumptionTrendsWidget extends ChartWidget
{
    use HasFiltersSchema;

    protected string $view = 'filament.widgets.consumption-trends-widget';

    protected static ?int $sort = 2;

    protected static bool $isLazy = true;

    // Dashboard default grid is 2 columns, so span 1 to allow side-by-side with
    // ConsumptionSharePieWidget (columnSpan = 1).
    protected int|string|array $columnSpan = 1;

    protected ?string $heading = 'Consumption trend';

    protected ?string $description = 'Monthly issuance trend per department in the selected period.';

    protected bool $hasDeferredFilters = true;

    public static function canView(): bool
    {
        $user = Filament::auth()->user();

        return $user?->isSupplyCustodian() ?? false;
    }

    public function getDescription(): ?string
    {
        $user = Filament::auth()->user();
        if ($user && ! $user->isSupplyCustodian()) {
            return 'Monthly issuance trend for your office/department. Based on issuance records (items issued out); only issuances with a department set are included.';
        }

        return 'Monthly issuance trend per department. Includes all offices (regional and satellite) when All offices is selected.';
    }

    public function getShowDepartmentStats(): bool
    {
        $user = Filament::auth()->user();

        return $user?->isSupplyCustodian() ?? true;
    }

    protected ?string $maxHeight = '320px';

    /**
     * Chart.js palette — OWWA brand blues/reds + neutral mid-tones for readability.
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
            Toggle::make('show_moving_average')
                ->label('Show 3‑month moving average')
                ->default(false),
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

        $showMovingAverage = (bool) ($f['show_moving_average'] ?? false);

        $service = app(ConsumptionAnalyticsService::class);
        $result = $service->getConsumptionByDepartmentAndPeriod($from, $to, $departmentIds, $officeIds, $includeYearLabels);

        $labels = $result['labels'];
        $series = $result['series'];

        if (empty($series)) {
            return [
                'datasets' => [],
                'labels' => $labels,
            ];
        }

        $datasets = [];
        $colors = self::$chartColors;
        $index = 0;

        foreach ($series as $deptName => $values) {
            $color = $colors[$index % count($colors)];
            $datasets[] = [
                'label' => $deptName,
                'data' => $values,
                'borderColor' => $color,
                'backgroundColor' => $color.'18',
                'pointBackgroundColor' => $color,
                'pointBorderColor' => '#ffffff',
                'pointBorderWidth' => 2,
                'pointRadius' => 4,
                'pointHoverRadius' => 6,
                'fill' => false,
                'tension' => 0.4,
                'borderWidth' => 2.5,
            ];
            $index++;
        }

        if ($showMovingAverage) {
            $maSeries = $service->applyMovingAverageToSeries($series, 3);
            foreach ($maSeries as $deptName => $maValues) {
                $color = $colors[$index % count($colors)];
                $datasets[] = [
                    'label' => $deptName.' (MA)',
                    'data' => $maValues,
                    'borderColor' => $color,
                    'backgroundColor' => 'transparent',
                    'borderDash' => [5, 5],
                    'fill' => false,
                    'tension' => 0.3,
                ];
                $index++;
            }
        }

        return [
            'datasets' => $datasets,
            'labels' => $labels,
        ];
    }

    protected function getType(): string
    {
        return 'line';
    }

    protected function getOptions(): ?array
    {
        $scope = $this->filters['analytics_scope'] ?? AnalyticsDateRangeService::SCOPE_CURRENT_YEAR;
        $longView = $scope === AnalyticsDateRangeService::SCOPE_LONG_VIEW;

        return [
            'scales' => [
                'y' => [
                    'beginAtZero' => true,
                    'grace' => '8%',
                    'ticks' => [
                        'precision' => 0,
                        'color' => '#94a3b8',
                        'font' => ['size' => 11],
                        'padding' => 6,
                    ],
                    'grid' => [
                        'color' => 'rgba(226,232,240,0.7)',
                        'drawBorder' => false,
                    ],
                    'border' => ['display' => false],
                ],
                'x' => [
                    'grid' => ['display' => false],
                    'border' => ['display' => false],
                    'ticks' => [
                        'maxRotation' => $longView ? 45 : 0,
                        'minRotation' => $longView ? 45 : 0,
                        'color' => '#94a3b8',
                        'font' => ['size' => $longView ? 9 : 11],
                        'padding' => 4,
                    ],
                ],
            ],
            'plugins' => [
                'legend' => [
                    'display' => true,
                    'position' => 'top',
                    'align' => 'end',
                    'labels' => [
                        'boxWidth' => 8,
                        'boxHeight' => 8,
                        'borderRadius' => 4,
                        'padding' => 14,
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
                    'mode' => 'index',
                    'intersect' => false,
                ],
            ],
            'interaction' => [
                'mode' => 'index',
                'intersect' => false,
            ],
        ];
    }

    /**
     * Summary stats for the current filters (for the stats row in the view).
     *
     * @return array{total: int, top_department_name: string|null, top_department_quantity: int, periods_count: int, avg_per_period: float}
     */
    public function getConsumptionSummary(): array
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

        return app(ConsumptionAnalyticsService::class)->getConsumptionSummary($from, $to, $departmentIds, $officeIds, $includeYearLabels);
    }
}
