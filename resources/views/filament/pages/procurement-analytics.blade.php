@php
    use App\Filament\Resources\AiProcurementRunResource;
    use Illuminate\Support\Str;

    $selectedRange = $this->getSelectedDateRange();
    $rangeKey = $selectedRange['from'].'_'.$selectedRange['to'];
    $atRiskRows = $this->getAtRiskPreviewRows();
    $activePreset = $this->getActivePeriodPreset();
    $allAtRiskCount = $this->getAtRiskPreviewCount();
    $stockoutCount = $this->getStockoutPreviewCount();
@endphp

<x-filament-panels::page>
    {{-- Filter bar --}}
    <div class="owwa-pa-context-bar">
        <div class="owwa-pa-filters-left">
            <div class="owwa-pa-presets" role="group" aria-label="Period presets">
                <button type="button" wire:click="applyPeriodPreset('3m')" class="owwa-pa-preset-btn {{ $activePreset === '3m' ? 'is-active' : '' }}">Last 3 months</button>
                <button type="button" wire:click="applyPeriodPreset('6m')" class="owwa-pa-preset-btn {{ $activePreset === '6m' ? 'is-active' : '' }}">Last 6 months</button>
                <button type="button" wire:click="applyPeriodPreset('12m')" class="owwa-pa-preset-btn {{ $activePreset === '12m' ? 'is-active' : '' }}">Last 12 months</button>
                <button type="button" wire:click="applyPeriodPreset('ytd')" class="owwa-pa-preset-btn {{ $activePreset === 'ytd' ? 'is-active' : '' }}">Year to date</button>
            </div>
            <div class="owwa-pa-filters-left">
                <div class="owwa-pa-filter">
                    <label for="pa-from" class="owwa-pa-filter-label">From</label>
                    <input type="date" id="pa-from" wire:model.live="from" class="owwa-pa-filter-select" />
                </div>
                <div class="owwa-pa-filter">
                    <label for="pa-to" class="owwa-pa-filter-label">To</label>
                    <input type="date" id="pa-to" wire:model.live="to" class="owwa-pa-filter-select" />
                </div>
            </div>
        </div>
        <div class="owwa-pa-filter owwa-pa-filter--right">
            <label for="pa-category" class="owwa-pa-filter-label">Category</label>
            <select id="pa-category" wire:model.live="categoryId" class="owwa-pa-filter-select">
                <option value="">All categories</option>
                @foreach($this->getItemCategories() as $cat)
                    <option value="{{ $cat->id }}">{{ $cat->name }}</option>
                @endforeach
            </select>
        </div>
    </div>

    <div class="owwa-pa-filter-chips" aria-label="Active filters">
        <span class="owwa-pa-chip">{{ $this->getFilterSummary() }}</span>
        <details class="owwa-pa-how-it-works">
            <summary>How this works</summary>
            <p>
                The system compares issuances in the selected period to current stock for your office.
                Forecasts use the last 12 months of issuance history; the date preset scopes reporting context.
                <strong>Months of cover</strong> estimates how long stock lasts at the usual issuing pace.
                <strong>High</strong> means under ~1 month of cover or below reorder. <strong>Medium</strong> means ~1–3 months.
            </p>
        </details>
    </div>

    {{-- KPIs --}}
    <div class="owwa-pa-widgets owwa-pa-widgets--stack" wire:key="pa-coverage-{{ $rangeKey }}-{{ $categoryId }}">
        @livewire(\App\Filament\Widgets\CoverageOverviewWidget::class, [
            'from' => $this->from,
            'to' => $this->to,
            'categoryId' => $this->categoryId,
        ], key('coverage-overview-'.$rangeKey.'-'.$categoryId))
    </div>

    {{-- At-risk items (hero) --}}
    @include('filament.partials.at-risk-procurement-preview', [
        'rows' => $atRiskRows,
        'heading' => 'At-risk items & suggested reorders',
        'intro' => 'Deterministic signals from issuance trends, stock levels, and reorder points.',
        'atRiskView' => $atRiskView,
        'sortColumn' => $sortColumn,
        'sortDirection' => $sortDirection,
        'allAtRiskCount' => $allAtRiskCount,
        'stockoutCount' => $stockoutCount,
    ])

    {{-- Procurement summary + optional AI recommendation --}}
    <div
        class="owwa-pa-card fi-section rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10"
        @if($processingRunId) wire:poll.5s="syncProcessingRun" @endif
    >
        <div class="fi-section-header-ctn px-5 py-3 border-b border-gray-200 dark:border-white/10">
            <div class="owwa-pa-ai-header">
                <div>
                    <h2 class="fi-section-header-heading">Procurement summary</h2>
                    <p class="fi-section-header-description mt-1">
                        Click <strong>Generate recommendation</strong> to build a reorder summary from the table above.
                        The AI narrative runs in the background via the operation device worker.
                        Runs are saved under
                        <a href="{{ AiProcurementRunResource::getUrl('index') }}" class="owwa-pa-section-desc-link">AI procurement runs</a>.
                    </p>
                </div>
                <button
                    type="button"
                    wire:click="generateAiRecommendation"
                    wire:loading.attr="disabled"
                    wire:target="generateAiRecommendation"
                    class="owwa-pa-generate-btn owwa-pa-generate-btn--secondary"
                    @disabled($loading)
                >
                    <span wire:loading.remove wire:target="generateAiRecommendation">Generate recommendation</span>
                    <span wire:loading wire:target="generateAiRecommendation">Generating…</span>
                </button>
            </div>
        </div>
        <div class="px-5 py-3 owwa-pa-summary-body">
            @if($loading || filled($actionSummary) || filled($recommendation))
                <span class="owwa-pa-chip owwa-pa-chip--inline">{{ $this->getFilterSummary() }}</span>

                @if(filled($actionSummary))
                    <div class="owwa-pa-action-panel">
                        <p class="owwa-pa-action-headline">{{ $actionSummary['headline'] }}</p>

                        @if(! empty($actionSummary['priority_actions']))
                            <div class="owwa-pa-action-group">
                                <h3 class="owwa-pa-action-group-title">High priority</h3>
                                <ul class="owwa-pa-action-list">
                                    @foreach($actionSummary['priority_actions'] as $action)
                                        <li>
                                            <strong>{{ $action['item'] }}</strong>
                                            — stock {{ number_format($action['stock']) }}
                                            @if($action['suggested'] !== null)
                                                · order {{ number_format($action['suggested']) }}
                                            @endif
                                            @if($action['stock_url'])
                                                · <a href="{{ $action['stock_url'] }}" class="owwa-pa-inline-link">Stock</a>
                                            @endif
                                        </li>
                                    @endforeach
                                </ul>
                            </div>
                        @endif

                        @if(! empty($actionSummary['reorder_suggestions']))
                            <div class="owwa-pa-action-group">
                                <h3 class="owwa-pa-action-group-title">Medium priority reorders</h3>
                                <ul class="owwa-pa-action-list">
                                    @foreach($actionSummary['reorder_suggestions'] as $action)
                                        <li>
                                            <strong>{{ $action['item'] }}</strong>
                                            — suggested {{ number_format($action['suggested']) }}
                                            @if($action['stock_url'])
                                                · <a href="{{ $action['stock_url'] }}" class="owwa-pa-inline-link">Stock</a>
                                            @endif
                                        </li>
                                    @endforeach
                                </ul>
                            </div>
                        @endif

                        @if(empty($actionSummary['priority_actions']) && empty($actionSummary['reorder_suggestions']) && $actionSummary['headline'] !== 'No at-risk pairs in this filter')
                            <p class="owwa-pa-action-empty">Review the at-risk table for details on each item.</p>
                        @endif
                    </div>
                @endif

                @if($loading)
                    <div class="owwa-pr-ai-loading owwa-pr-ai-loading--inset" role="status" aria-live="polite">
                        <div class="owwa-pr-ai-spinner" aria-hidden="true"></div>
                        <div>
                            <p class="owwa-pr-ai-loading-title">Generating recommendation…</p>
                            <p class="owwa-pr-ai-loading-sub">Queued for the device worker.</p>
                            @if($lastAiRunId)
                                <p class="owwa-pr-ai-loading-sub">
                                    <a href="{{ AiProcurementRunResource::getUrl('view', ['record' => $lastAiRunId]) }}" class="owwa-pa-inline-link">View run #{{ $lastAiRunId }}</a>
                                </p>
                            @endif
                        </div>
                    </div>
                @elseif($recommendation === '__OLLAMA_UNAVAILABLE__')
                    <p class="owwa-pa-ai-unavailable">
                        AI recommendation unavailable (Ollama not running). The summary above uses deterministic data from the at-risk table.
                    </p>
                @elseif(filled($recommendation) && ! str_starts_with($recommendation, 'Cannot connect') && ! str_starts_with($recommendation, 'An error occurred') && ! str_starts_with($recommendation, 'The request took too long'))
                    <div class="owwa-pa-ai-recommendation">
                        <div class="owwa-pa-ai-recommendation-head">
                            <h3 class="owwa-pa-ai-recommendation-title">
                                <span class="owwa-pa-ai-recommendation-icon" aria-hidden="true">✦</span>
                                AI recommendation
                            </h3>
                            @if($lastAiRunId)
                                <a href="{{ AiProcurementRunResource::getUrl('view', ['record' => $lastAiRunId]) }}" class="owwa-pa-ai-recommendation-meta-link">
                                    View saved run #{{ $lastAiRunId }} →
                                </a>
                            @endif
                        </div>
                        <div class="owwa-pa-ai-recommendation-body">
                            {!! Str::markdown($this->formatAiNarrativeMarkdown($recommendation)) !!}
                        </div>
                        <p class="owwa-pr-ai-foot">AI-generated text can be inaccurate. Validate against the at-risk table.</p>
                    </div>
                @elseif(filled($recommendation))
                    <div class="owwa-pa-callout owwa-pa-callout--info" role="alert">
                        <p class="owwa-pa-callout-body">{{ $recommendation }}</p>
                    </div>
                @endif
            @else
                <div class="owwa-pr-ai-idle">
                    <p class="owwa-pr-ai-idle-text">Generate a summary from the current at-risk table to see reorder priorities and an optional AI narrative.</p>
                </div>
            @endif
        </div>
    </div>
</x-filament-panels::page>
