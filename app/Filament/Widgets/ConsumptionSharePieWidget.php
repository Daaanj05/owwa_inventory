<?php

namespace App\Filament\Widgets;

use App\Models\Department;
use App\Models\Office;
use App\Services\ConsumptionAnalyticsService;
use App\Services\FiscalYearService;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Schemas\Schema;
use Filament\Widgets\ChartWidget;
use Filament\Widgets\ChartWidget\Concerns\HasFiltersSchema;
use Illuminate\Support\Carbon;

class ConsumptionSharePieWidget extends ChartWidget
{
    use HasFiltersSchema;

    protected static ?int $sort = 3;

    protected string $view = 'filament.widgets.consumption-share-pie-widget';

    protected int | string | array $columnSpan = 1;

    protected ?string $heading = 'Consumption share';

    protected ?string $description = 'Overall share of total issued units per department in the selected period.';

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
        $fyId = app(FiscalYearService::class)->current()?->id;
        $scope = $user?->getConsumptionScope() ?? ['office_ids' => [], 'department_ids' => []];
        $officeBase = Office::forFiscalYear($fyId)->active()->orderBy('name');
        $officeOptions = $scope['office_ids'] !== []
            ? (clone $officeBase)->whereIn('id', $scope['office_ids'])->pluck('name', 'id')
            : $officeBase->pluck('name', 'id');
        $departmentBase = Department::forFiscalYear($fyId)->active()->orderBy('name');
        $departmentOptions = $scope['department_ids'] !== []
            ? (clone $departmentBase)->whereIn('id', $scope['department_ids'])->pluck('name', 'id')
            : $departmentBase->pluck('name', 'id');

        return $schema->components([
            DatePicker::make('date_from')
                ->label('From')
                ->default(now()->subMonths(11)->startOfMonth())
                ->maxDate(fn () => $this->deferredFilters['date_to'] ?? now()),
            DatePicker::make('date_to')
                ->label('To')
                ->default(now())
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
        $from = isset($f['date_from']) ? Carbon::parse($f['date_from'])->startOfDay() : now()->subMonths(11)->startOfMonth();
        $to = isset($f['date_to']) ? Carbon::parse($f['date_to'])->endOfDay() : now();
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
            $officeIds
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
                    'data'            => $result['values'],
                    'backgroundColor' => $backgroundColors,
                    'borderColor'     => $borderColors,
                    'borderWidth'     => 3,
                    'hoverOffset'     => 6,
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
                    'display'  => true,
                    'position' => 'bottom',
                    'align'    => 'center',
                    'labels'   => [
                        'boxWidth'      => 8,
                        'boxHeight'     => 8,
                        'borderRadius'  => 4,
                        'padding'       => 16,
                        'usePointStyle' => true,
                        'pointStyle'    => 'circle',
                        'color'         => '#475569',
                        'font'          => ['size' => 11, 'weight' => '500'],
                    ],
                ],
                'tooltip' => [
                    'backgroundColor' => 'rgba(15,23,42,0.88)',
                    'titleColor'      => '#f8fafc',
                    'bodyColor'       => '#cbd5e1',
                    'borderColor'     => 'rgba(255,255,255,0.08)',
                    'borderWidth'     => 1,
                    'padding'         => ['x' => 12, 'y' => 8],
                    'cornerRadius'    => 8,
                ],
            ],
        ];
    }
}
