@php
    use Filament\Widgets\View\Components\ChartWidgetComponent;
    use Illuminate\View\ComponentAttributeBag;

    $color       = $this->getColor();
    $heading     = $this->getHeading();
    $description = $this->getDescription();
    $filters     = $this->getFilters();
    $type        = $this->getType();
    $summary     = $this->getConsumptionSummary();
    $hasData     = $summary['total'] > 0;
@endphp

<x-filament-widgets::widget class="fi-wi-chart">
    <x-filament::section>
        <x-slot name="heading">
            @if (filled($description))
                <span
                    x-data="{ open: false }"
                    @mouseenter="open = true"
                    @mouseleave="open = false"
                    class="owwa-widget-heading-tip"
                >
                    <span>{{ $heading }}</span>
                    <div x-show="open" x-cloak class="owwa-widget-tip-bubble">
                        {{ $description }}
                    </div>
                </span>
            @else
                <span>{{ $heading }}</span>
            @endif
        </x-slot>

        {{-- Filter controls --}}
        @if ($filters || method_exists($this, 'getFiltersSchema'))
            <x-slot name="afterHeader">
                @if ($filters)
                    <x-filament::input.wrapper inline-prefix wire:target="filter" class="fi-wi-chart-filter">
                        <x-filament::input.select inline-prefix wire:model.live="filter">
                            @foreach ($filters as $value => $label)
                                <option value="{{ $value }}">{{ $label }}</option>
                            @endforeach
                        </x-filament::input.select>
                    </x-filament::input.wrapper>
                @endif
                @if (method_exists($this, 'getFiltersSchema'))
                    <x-filament::dropdown placement="bottom-end" shift width="xs" class="fi-wi-chart-filter">
                        <x-slot name="trigger">{{ $this->getFiltersTriggerAction() }}</x-slot>
                        <div class="fi-wi-chart-filter-content">
                            {{ $this->getFiltersSchema() }}
                            @if (method_exists($this, 'hasDeferredFilters') && $this->hasDeferredFilters())
                                <div class="fi-wi-chart-filter-content-actions-ctn">
                                    {{ $this->getFiltersApplyAction() }}
                                    {{ $this->getFiltersResetAction() }}
                                </div>
                            @endif
                        </div>
                    </x-filament::dropdown>
                @endif
            </x-slot>
        @endif

        {{-- KPI row --}}
        <div class="owwa-kpi-row">
            <div class="owwa-kpi owwa-kpi-accent">
                <span class="owwa-kpi-label">Total Consumption</span>
                <span class="owwa-kpi-value">
                    {{ number_format($summary['total']) }}
                    <span class="owwa-kpi-unit">units</span>
                </span>
                <span class="owwa-kpi-meta">In selected period</span>
            </div>
            @if($this->getShowDepartmentStats())
            <div class="owwa-kpi">
                <span class="owwa-kpi-label">Top Department</span>
                <span class="owwa-kpi-value owwa-kpi-value-text">
                    {{ $hasData ? ($summary['top_department_name'] ?? '—') : '—' }}
                </span>
                <span class="owwa-kpi-meta">
                    {{ $summary['top_department_quantity'] > 0
                        ? number_format($summary['top_department_quantity']) . ' units consumed'
                        : 'No issuances recorded' }}
                </span>
            </div>
            @endif
            <div class="owwa-kpi">
                <span class="owwa-kpi-label">Avg / Month</span>
                <span class="owwa-kpi-value">
                    {{ number_format($summary['avg_per_period'], 1) }}
                    <span class="owwa-kpi-unit">units</span>
                </span>
                <span class="owwa-kpi-meta">
                    @if($summary['growth_percent'] !== null)
                        {{ $summary['growth_percent'] > 0 ? '+' : '' }}{{ number_format($summary['growth_percent'], 1) }}% change · trend {{ $summary['trend_slope'] > 0 ? 'up' : ($summary['trend_slope'] < 0 ? 'down' : 'flat') }}
                    @else
                        Consumption rate
                    @endif
                </span>
            </div>
        </div>

        {{-- Chart or empty state --}}
        @if (!$hasData)
            <div class="owwa-empty-state">
                <svg class="owwa-empty-state-icon" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5"
                        d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z" />
                </svg>
                <h3 class="owwa-empty-state-title">No consumption data yet</h3>
                <p class="owwa-empty-state-text">
                    Consumption is based on <strong>issuance records</strong> (items issued out to departments). No issuances for your scope in the selected period, or issuances may not have a department set.
                    @if($this->getShowDepartmentStats())
                    Adjust the date range or add issuances under <strong>Inventory → Issuances</strong> (ensure Office and Department are set).
                    @else
                    Adjust the date range or add issuances via <strong>Inventory → Issuances</strong> and set the department so they appear here.
                    @endif
                </p>
            </div>
        @else
            <div @if ($pollingInterval = $this->getPollingInterval()) wire:poll.{{ $pollingInterval }}="updateChartData" @endif>
                <div
                    x-load
                    x-load-src="{{ \Filament\Support\Facades\FilamentAsset::getAlpineComponentSrc('chart', 'filament/widgets') }}"
                    wire:ignore
                    data-chart-type="{{ $type }}"
                    x-data="chart({
                        cachedData: @js($this->getCachedData()),
                        maxHeight: @js($maxHeight = $this->getMaxHeight()),
                        options: @js($this->getOptions()),
                        type: @js($type),
                    })"
                    {{ (new ComponentAttributeBag)->color(ChartWidgetComponent::class, $color)->class([
                        'fi-wi-chart-canvas-ctn',
                        'fi-wi-chart-canvas-ctn-no-aspect-ratio' => filled($maxHeight),
                    ]) }}
                >
                    <canvas x-ref="canvas" @if ($maxHeight) style="max-height: {{ $maxHeight }}" @endif></canvas>
                    <span x-ref="backgroundColorElement" class="fi-wi-chart-bg-color"></span>
                    <span x-ref="borderColorElement" class="fi-wi-chart-border-color"></span>
                    <span x-ref="gridColorElement" class="fi-wi-chart-grid-color"></span>
                    <span x-ref="textColorElement" class="fi-wi-chart-text-color"></span>
                </div>
            </div>
        @endif

    </x-filament::section>
</x-filament-widgets::widget>
