@php
    use App\Filament\Resources\AiProcurementRunResource;
    use Illuminate\Support\Str;

    $selectedRange = $this->getSelectedDateRange();
    $rangeKey = $selectedRange['from'].'_'.$selectedRange['to'];
    $atRiskRows = $this->getAtRiskPreviewRows();
    $activePreset = $this->getActivePeriodPreset();
    $allAtRiskCount = $this->getAtRiskPreviewCount();
    $stockoutCount = $this->getStockoutPreviewCount();
    $hasSummaryOutput = $loading || filled($recommendation);
    $hasAiNarrative = filled($recommendation)
        && $recommendation !== '__OLLAMA_UNAVAILABLE__'
        && ! str_starts_with($recommendation, 'Cannot connect')
        && ! str_starts_with($recommendation, 'An error occurred')
        && ! str_starts_with($recommendation, 'The request took too long');
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
    </div>

    <div class="owwa-pa-filter-chips" aria-label="Active filters">
        <span class="owwa-pa-chip">{{ $this->getFilterSummary() }}</span>
        <details class="owwa-pa-how-it-works">
            <summary>How this works</summary>
            <p>
                Suggested reorders compare consumable issuances with current stock and the reorder point.
                Replacement due lists semi-expendable units that are nearing or past useful life.
                Use the left and right buttons to switch lists. A useful-life date alone does not create a purchase.
                <strong>High</strong> means under ~1 month of cover or below reorder. <strong>Medium</strong> means ~1–3 months.
            </p>
        </details>
    </div>

    {{-- KPIs --}}
    <div class="owwa-pa-widgets owwa-pa-widgets--stack" wire:key="pa-coverage-{{ $rangeKey }}">
        @livewire(\App\Filament\Widgets\CoverageOverviewWidget::class, [
            'from' => $this->from,
            'to' => $this->to,
            'categoryId' => null,
        ], key('coverage-overview-'.$rangeKey))
    </div>

    @php
        $analyticsSlides = $this->availableAnalyticsSlides();
        $showAnalyticsArrows = count($analyticsSlides) > 1;
    @endphp
    <div
        wire:key="pa-slides-{{ $rangeKey }}"
        x-data="{
            slide: @entangle('analyticsSlide'),
            order: @js($analyticsSlides),
            height: null,
            shift(step) {
                if (this.order.length < 2) {
                    return;
                }
                const index = Math.max(0, this.order.indexOf(this.slide));
                this.slide = this.order[(index + step + this.order.length) % this.order.length];
            },
            measure() {
                const panel = this.$root.querySelector('.owwa-pa-slide-panel[data-slide=\'' + this.slide + '\']');
                if (panel) {
                    this.height = panel.offsetHeight;
                }
            },
            init() {
                this.$watch('slide', () => this.measure());
                if (window.ResizeObserver) {
                    const observer = new ResizeObserver(() => this.measure());
                    this.$root.querySelectorAll('.owwa-pa-slide-panel').forEach((panel) => observer.observe(panel));
                }
                this.measure();
            },
        }"
    >
    <div class="owwa-pa-card fi-section rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
        <div class="owwa-pa-slide-viewport" x-bind:style="height ? ('height: ' + height + 'px') : ''">
            <div
                class="owwa-pa-slide-track {{ $showAnalyticsArrows ? 'is-dual' : '' }} {{ $analyticsSlide === 'replacement' ? 'is-replacement' : '' }}"
                x-bind:class="{ 'is-replacement': slide === 'replacement' }"
            >
                @if($this->shouldShowReorderSlide())
                    <section class="owwa-pa-slide-panel" data-slide="reorders">
                        <div class="owwa-pa-slide-copy">
                            <h2 class="fi-section-header-heading">Suggested reorders — consumables</h2>
                            <p class="fi-section-header-description mt-1">Consumable stock compared with issuance pace and the reorder point.</p>
                        </div>
                        @include('filament.partials.at-risk-procurement-preview', [
                            'rows' => $atRiskRows,
                            'atRiskView' => $atRiskView,
                            'sortColumn' => $sortColumn,
                            'sortDirection' => $sortDirection,
                            'allAtRiskCount' => $allAtRiskCount,
                            'stockoutCount' => $stockoutCount,
                            'embedded' => true,
                        ])
                    </section>
                @endif
                @if($this->shouldShowEulPanel())
                    <section class="owwa-pa-slide-panel" data-slide="replacement">
                        <div class="owwa-pa-slide-copy">
                            <h2 class="fi-section-header-heading">Replacement due — semi-expendable</h2>
                            <p class="fi-section-header-description mt-1">Issued semi-expendable units nearing or past useful life. A date alone does not create a purchase.</p>
                        </div>
                        @include('filament.partials.semi-expendable-eul-preview', [
                            'rows' => $this->getEulReviewRows(),
                            'embedded' => true,
                        ])
                    </section>
                @endif
            </div>
        </div>
    </div>
        <div class="owwa-pa-slide-footer">
            @if($showAnalyticsArrows)
                <div class="owwa-pa-slide-nav" role="group" aria-label="Switch list">
                    <button type="button" class="owwa-pa-slide-btn" aria-label="Previous list" x-on:click="shift(-1)">←</button>
                    <span class="owwa-pa-slide-label" x-text="(Math.max(0, order.indexOf(slide)) + 1) + ' of ' + order.length">{{ $this->analyticsSlidePositionLabel() }}</span>
                    <button type="button" class="owwa-pa-slide-btn" aria-label="Next list" x-on:click="shift(1)">→</button>
                </div>
            @endif
            <button type="button" wire:click="exportAtRiskPdf" class="owwa-pa-export-btn">Export PDF</button>
        </div>
    </div>

    {{-- Procurement summary + optional AI recommendation --}}
    <div
        id="procurement-summary"
        class="owwa-pa-card owwa-pa-card--summary fi-section rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10"
        @if($processingRunId) wire:poll.5s="syncProcessingRun" @endif
    >
        <div class="fi-section-header-ctn px-5 py-3 border-b border-gray-200 dark:border-white/10">
            <div class="owwa-pa-summary-intro">
                <h2 class="fi-section-header-heading">Procurement summary</h2>
                <p class="fi-section-header-description mt-1">
                    Build a recommendation from the consumable reorder list and the semi-expendable replacement list.
                    Runs are saved under
                    <a href="{{ AiProcurementRunResource::getUrl('index') }}" class="owwa-pa-section-desc-link">AI procurement runs</a>.
                </p>
                <div class="owwa-pa-summary-status" aria-live="polite">
                    <span class="owwa-pa-summary-status-scope">{{ $this->getFilterSummary() }}</span>
                    @if($lastGenerated = $this->getLastGeneratedLabel())
                        <span class="owwa-pa-summary-status-sep" aria-hidden="true">·</span>
                        <span class="owwa-pa-summary-status-generated">Last generated {{ $lastGenerated }}</span>
                    @endif
                </div>
            </div>
        </div>
        <div class="px-5 py-3 owwa-pa-summary-body">
            @if($hasSummaryOutput)
                @if($loading)
                    <div class="owwa-pr-ai-loading owwa-pr-ai-loading--inset owwa-pr-ai-loading--accent" role="status" aria-live="polite">
                        <div class="owwa-pr-ai-spinner" aria-hidden="true"></div>
                        <div>
                            <p class="owwa-pr-ai-loading-title">Generating recommendation…</p>
                            @if($lastAiRunId)
                                <p class="owwa-pr-ai-loading-sub">
                                    <a href="{{ AiProcurementRunResource::getUrl('view', ['record' => $lastAiRunId]) }}" class="owwa-pa-inline-link">View run #{{ $lastAiRunId }}</a>
                                </p>
                            @endif
                        </div>
                    </div>
                @else
                    <div class="owwa-pa-ai-recommendation owwa-pr-ai-result">
                        @if($hasAiNarrative)
                            <div class="owwa-pa-ai-recommendation-head">
                                <span class="owwa-pr-ai-pill">AI-generated</span>
                                @if($lastAiRunId)
                                    <a href="{{ AiProcurementRunResource::getUrl('view', ['record' => $lastAiRunId]) }}" class="owwa-pa-ai-recommendation-meta-link">
                                        View saved run #{{ $lastAiRunId }} →
                                    </a>
                                @endif
                            </div>
                            <div class="owwa-pr-ai-narrative owwa-pa-ai-narrative-body">
                                {!! Str::markdown($this->formatAiNarrativeMarkdown($this->splitRecommendation($recommendation)['narrative'])) !!}
                            </div>
                        @elseif($recommendation === '__OLLAMA_UNAVAILABLE__')
                            <p class="owwa-pa-ai-unavailable">
                                AI recommendation unavailable. Review the lists above.
                            </p>
                        @elseif(filled($recommendation))
                            <div class="owwa-pa-callout owwa-pa-callout--info" role="alert">
                                <p class="owwa-pa-callout-body">{{ $recommendation }}</p>
                            </div>
                        @endif

                        @include('filament.partials.procurement-recommendation-items-table', [
                            'reorderRows' => $this->getRecommendationReorderRows(),
                            'replacementRows' => $this->getRecommendationReplacementRows(),
                            'slides' => $this->availableAnalyticsSlides(),
                            'recommendationSlide' => $recommendationSlide,
                            'positionLabel' => $this->recommendationSlidePositionLabel(),
                        ])
                    </div>
                @endif
            @else
                <div class="owwa-pr-ai-idle">
                    <p class="owwa-pr-ai-idle-text">Generate a recommendation from the consumable reorder list and the replacement-due list.</p>
                </div>
            @endif
        </div>
        <div class="owwa-pa-summary-footer px-5 py-3 border-t border-gray-200 dark:border-white/10">
            <button
                type="button"
                wire:click="generateAiRecommendation"
                wire:loading.attr="disabled"
                wire:target="generateAiRecommendation"
                class="owwa-pa-generate-btn owwa-pa-generate-btn--footer"
                @disabled($loading)
            >
                <span wire:loading.remove wire:target="generateAiRecommendation">Generate recommendation</span>
                <span wire:loading wire:target="generateAiRecommendation">Generating…</span>
            </button>
            <p class="owwa-pa-generate-hint">Uses the consumable reorder list and the replacement-due list</p>
        </div>
    </div>
    <x-filament-actions::modals />
</x-filament-panels::page>
