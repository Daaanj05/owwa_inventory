<?php

namespace App\Filament\Widgets;

use App\Filament\Widgets\Concerns\ConfiguresConsumptionFilters;
use App\Services\ConsumptionAnalyticsService;
use Filament\Facades\Filament;
use Filament\Schemas\Schema;
use Filament\Widgets\ChartWidget;
use Filament\Widgets\ChartWidget\Concerns\HasFiltersSchema;

class ConsumptionTrendsWidget extends ChartWidget
{
    use ConfiguresConsumptionFilters;
    use HasFiltersSchema {
        updatedFilters as protected updatedChartFilters;
    }

    protected string $view = 'filament.widgets.consumption-trends-widget';

    protected static ?int $sort = 2;

    protected static bool $isLazy = false;

    // Dashboard 2-column grid: each chart takes half width on md+.
    protected int|string|array $columnSpan = [
        'default' => 2,
        'md' => 1,
    ];

    protected ?string $heading = 'Consumption trend';

    protected ?string $description = 'Monthly issuance trend per office in the selected period.';

    protected bool $hasDeferredFilters = false;

    protected ?string $pollingInterval = null;

    public static function canView(): bool
    {
        $user = Filament::auth()->user();

        return $user?->isSupplyCustodian() ?? false;
    }

    public function getDescription(): ?string
    {
        $user = Filament::auth()->user();
        if ($user && ! $user->isSupplyCustodian()) {
            return 'Monthly issuance trend for your office. Based on issuance records (items issued out); only issuances with an office set are included.';
        }

        if ($this->isConsumptionDepartmentMode()) {
            return 'Monthly issuance trend by department within the selected office.';
        }

        if ($this->isConsumptionItemScoped()) {
            return 'Monthly issuance trend per office, filtered to the selected category or item.';
        }

        return 'Monthly issuance trend per office. Includes all offices when All offices is selected.';
    }

    public function getShowOfficeStats(): bool
    {
        $user = Filament::auth()->user();

        return $user?->isSupplyCustodian() ?? true;
    }

    public function isDepartmentChartMode(): bool
    {
        return $this->isConsumptionDepartmentMode();
    }

    protected ?string $maxHeight = '210px';

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
        $this->bootConsumptionFilterDefaults();
    }

    public function filtersSchema(Schema $schema): Schema
    {
        return $this->configureConsumptionFiltersSchema($schema);
    }

    public function updatedFilters(): void
    {
        $this->updatedChartFilters();

        $this->dispatch(
            'consumption-trend-filters-updated',
            filters: $this->filters ?? [],
        );
    }

    protected function getData(): array
    {
        $resolved = $this->resolveConsumptionFilters();
        $service = app(ConsumptionAnalyticsService::class);

        if ($this->isConsumptionDepartmentMode()) {
            $result = $service->getConsumptionByDepartmentAndPeriod(
                $resolved['from'],
                $resolved['to'],
                $resolved['department_ids'],
                $resolved['office_ids'],
                $resolved['includeYearInLabels'],
                $resolved['item_ids'],
            );
        } else {
            $result = $service->getConsumptionByOfficeAndPeriod(
                $resolved['from'],
                $resolved['to'],
                $resolved['department_ids'],
                $resolved['office_ids'],
                $resolved['includeYearInLabels'],
                $resolved['item_ids'],
            );
        }

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

        foreach ($series as $seriesName => $values) {
            $color = $colors[$index % count($colors)];
            $datasets[] = [
                'label' => $seriesName,
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
        $resolved = app(\App\Services\AnalyticsDateRangeService::class)->resolveFromWidgetFilters($this->filters ?? []);
        $longView = $resolved['includeYearInLabels'];

        return [
            'maintainAspectRatio' => false,
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
                    'display' => false,
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
     * HTML legend outside the canvas so plot height stays fixed when many offices are listed.
     *
     * @return array<int, array{label: string, color: string}>
     */
    public function getChartLegendItems(): array
    {
        $data = $this->getCachedData();
        $items = [];

        foreach ($data['datasets'] ?? [] as $dataset) {
            $label = (string) ($dataset['label'] ?? '');
            if ($label === '') {
                continue;
            }

            $items[] = [
                'label' => $label,
                'color' => (string) ($dataset['borderColor'] ?? $dataset['pointBackgroundColor'] ?? '#64748b'),
            ];
        }

        return $items;
    }

    /**
     * @return array{total: int, top_name: string|null, top_quantity: int, periods_count: int, avg_per_period: float, growth_percent: float|null, trend_slope: float, mode: string}
     */
    public function getConsumptionSummary(): array
    {
        $resolved = $this->resolveConsumptionFilters();
        $service = app(ConsumptionAnalyticsService::class);

        if ($this->isConsumptionDepartmentMode()) {
            $summary = $service->getConsumptionSummary(
                $resolved['from'],
                $resolved['to'],
                $resolved['department_ids'],
                $resolved['office_ids'],
                $resolved['includeYearInLabels'],
                $resolved['item_ids'],
            );

            return [
                'total' => $summary['total'],
                'top_name' => $summary['top_department_name'],
                'top_quantity' => $summary['top_department_quantity'],
                'periods_count' => $summary['periods_count'],
                'avg_per_period' => $summary['avg_per_period'],
                'growth_percent' => $summary['growth_percent'],
                'trend_slope' => $summary['trend_slope'],
                'mode' => 'department',
            ];
        }

        $summary = $service->getConsumptionSummaryByOffice(
            $resolved['from'],
            $resolved['to'],
            $resolved['department_ids'],
            $resolved['office_ids'],
            $resolved['includeYearInLabels'],
            $resolved['item_ids'],
        );

        return [
            'total' => $summary['total'],
            'top_name' => $summary['top_office_name'],
            'top_quantity' => $summary['top_office_quantity'],
            'periods_count' => $summary['periods_count'],
            'avg_per_period' => $summary['avg_per_period'],
            'growth_percent' => $summary['growth_percent'],
            'trend_slope' => $summary['trend_slope'],
            'mode' => 'office',
        ];
    }
}
