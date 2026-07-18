<?php

namespace App\Filament\Widgets;

use App\Filament\Widgets\Concerns\ConfiguresConsumptionFilters;
use App\Services\ConsumptionAnalyticsService;
use Filament\Facades\Filament;
use Filament\Schemas\Schema;
use Filament\Widgets\ChartWidget;
use Filament\Widgets\ChartWidget\Concerns\HasFiltersSchema;
use Illuminate\Support\Arr;
use Livewire\Attributes\On;

class ConsumptionSharePieWidget extends ChartWidget
{
    use ConfiguresConsumptionFilters;
    use HasFiltersSchema;

    protected static ?int $sort = 3;

    protected static bool $isLazy = false;

    protected string $view = 'filament.widgets.consumption-share-pie-widget';

    protected int|string|array $columnSpan = [
        'default' => 2,
        'md' => 1,
    ];

    protected ?string $heading = 'Consumption share';

    protected ?string $description = 'Share of total issued units per office. Includes all offices (regional and satellite) when All offices is selected.';

    protected bool $hasDeferredFilters = false;

    protected ?string $pollingInterval = null;

    protected ?string $maxHeight = null;

    public static function canView(): bool
    {
        $user = Filament::auth()->user();

        return $user?->isSupplyCustodian() ?? false;
    }

    public function getDescription(): ?string
    {
        if ($this->isConsumptionItemScoped()) {
            return 'Share of total issued units by office and item for the selected filters.';
        }

        return 'Share of total issued units per office. Includes all offices (regional and satellite) when All offices is selected.';
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
        $this->bootConsumptionFilterDefaults();
    }

    public function filtersSchema(Schema $schema): Schema
    {
        return $this->configureConsumptionFiltersSchema($schema);
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    #[On('consumption-trend-filters-updated')]
    public function syncConsumptionTrendFilters(array $filters): void
    {
        $this->filters = Arr::only($filters, [
            'date_from',
            'date_to',
            'office_ids',
            'department_ids',
            'item_category_ids',
            'base_names',
            'item_ids',
        ]);
        $this->filters['item_category_ids'] = $this->normalizeEmptySelectValue($this->filters['item_category_ids'] ?? null);
        $this->filters['base_names'] = $this->normalizeEmptySelectValue($this->filters['base_names'] ?? null);
        $this->filters['item_ids'] = $this->normalizeEmptySelectValue($this->filters['item_ids'] ?? null);
        $this->cachedData = null;
    }

    protected function getData(): array
    {
        $resolved = $this->resolveConsumptionFilters();
        $service = app(ConsumptionAnalyticsService::class);

        $result = $resolved['item_ids'] !== []
            ? $service->getConsumptionTotalsByOfficeAndItem(
                $resolved['from'],
                $resolved['to'],
                $resolved['department_ids'],
                $resolved['office_ids'],
                $resolved['includeYearInLabels'],
                $resolved['item_ids'],
            )
            : $service->getConsumptionTotalsByOffice(
                $resolved['from'],
                $resolved['to'],
                $resolved['department_ids'],
                $resolved['office_ids'],
                $resolved['includeYearInLabels'],
                $resolved['item_ids'],
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
            'maintainAspectRatio' => false,
            'animation' => false,
            'resizeDelay' => 200,
            'cutout' => '55%',
            'layout' => [
                'padding' => [
                    'top' => 4,
                    'right' => 8,
                    'bottom' => 4,
                    'left' => 8,
                ],
            ],
            'plugins' => [
                'legend' => [
                    'display' => true,
                    'position' => 'bottom',
                    'align' => 'center',
                    'labels' => [
                        'boxWidth' => 8,
                        'boxHeight' => 8,
                        'borderRadius' => 4,
                        'padding' => 8,
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
