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
    use HasFiltersSchema;

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

        return 'Monthly issuance trend per office. Includes all offices (regional and satellite) when All offices is selected.';
    }

    public function getShowOfficeStats(): bool
    {
        $user = Filament::auth()->user();

        return $user?->isSupplyCustodian() ?? true;
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
        return $this->configureConsumptionFiltersSchema($schema, includeMovingAverage: true);
    }

    protected function getData(): array
    {
        $resolved = $this->resolveConsumptionFilters();
        $showMovingAverage = (bool) (($this->filters ?? [])['show_moving_average'] ?? false);

        $service = app(ConsumptionAnalyticsService::class);
        $result = $service->getConsumptionByOfficeAndPeriod(
            $resolved['from'],
            $resolved['to'],
            $resolved['department_ids'],
            $resolved['office_ids'],
            $resolved['includeYearInLabels'],
            $resolved['item_ids'],
        );

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

        foreach ($series as $officeName => $values) {
            $color = $colors[$index % count($colors)];
            $datasets[] = [
                'label' => $officeName,
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
            foreach ($maSeries as $officeName => $maValues) {
                $color = $colors[$index % count($colors)];
                $datasets[] = [
                    'label' => $officeName.' (MA)',
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
     * @return array{total: int, top_office_name: string|null, top_office_quantity: int, periods_count: int, avg_per_period: float}
     */
    public function getConsumptionSummary(): array
    {
        $resolved = $this->resolveConsumptionFilters();

        return app(ConsumptionAnalyticsService::class)->getConsumptionSummaryByOffice(
            $resolved['from'],
            $resolved['to'],
            $resolved['department_ids'],
            $resolved['office_ids'],
            $resolved['includeYearInLabels'],
            $resolved['item_ids'],
        );
    }
}
