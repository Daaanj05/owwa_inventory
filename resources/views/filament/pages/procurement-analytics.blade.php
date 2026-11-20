@php
    use App\Filament\Resources\AiProcurementRunResource;
    use Illuminate\Support\Str;

    $selectedRange = $this->getSelectedDateRange();
    $rangeKey = $selectedRange['from'].'_'.$selectedRange['to'];
    $allAtRiskCount = $this->getAtRiskPreviewCount();
    $stockoutCount = $this->getStockoutPreviewCount();
    $hasSummaryOutput = $loading || filled($recommendation);
    $aiNarrativeUnavailable = (bool) ($aiNarrativeUnavailable ?? false);
    $hasAiNarrative = filled($recommendation)
        && ! $aiNarrativeUnavailable
        && $recommendation !== '__OLLAMA_UNAVAILABLE__'
        && ! str_starts_with($recommendation, 'Cannot connect')
        && ! str_starts_with($recommendation, 'An error occurred')
        && ! str_starts_with($recommendation, 'The request took too long');
@endphp

<x-filament-panels::page>
    <div wire:key="pa-tour" x-data="procurementSpotlightTour(@js($this->availableAnalyticsSlides()))">
    <div class="owwa-pa-filter-chips" aria-label="Active filters">
        <div class="owwa-pa-period" data-tour="period">
            <span class="owwa-pa-chip">{{ $this->getFilterSummary() }}</span>
            <p class="owwa-pa-period-note">Suggestions use the last 12 months through today.</p>
        </div>
        <button type="button" class="owwa-pa-how-it-works" x-ref="startButton" x-on:click="start()" aria-haspopup="dialog">
            How this works
        </button>
    </div>

    {{-- KPIs --}}
    <div class="owwa-pa-widgets owwa-pa-widgets--stack" data-tour="kpis" wire:key="pa-coverage-{{ $rangeKey }}">
        @livewire(\App\Filament\Widgets\CoverageOverviewWidget::class, [
            'from' => $selectedRange['from'],
            'to' => $selectedRange['to'],
            'categoryId' => null,
        ], key('coverage-overview-'.$rangeKey))
    </div>

    @php
        $analyticsSlides = $this->availableAnalyticsSlides();
        $showAnalyticsArrows = count($analyticsSlides) > 1;
    @endphp
    <div
        wire:key="pa-slides-{{ $rangeKey }}"
        x-on:pa-tour-slide.window="showSlide($event.detail)"
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
            showSlide(name) {
                if (! this.order.includes(name) || this.slide === name) {
                    return;
                }
                this.slide = name;
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
                    <section class="owwa-pa-slide-panel" data-slide="reorders" data-tour="reorders">
                        <div class="owwa-pa-slide-copy">
                            <h2 class="fi-section-header-heading">Suggested Reorders - Consumables Only</h2>
                            <p class="fi-section-header-description mt-1">Consumable stock compared with issuance pace and the reorder point.</p>
                        </div>
                        @include('filament.partials.at-risk-procurement-preview', [
                            'allRows' => collect($this->getAtRiskInitialClientRows()),
                            'atRiskView' => $atRiskView,
                            'sortColumn' => $sortColumn,
                            'sortDirection' => $sortDirection,
                            'allAtRiskCount' => $allAtRiskCount,
                            'stockoutCount' => $stockoutCount,
                            'pageSize' => 25,
                            'stockoutWithinMonths' => 2.0,
                            'totalCount' => $atRiskView === 'stockouts' ? $stockoutCount : $allAtRiskCount,
                            'chunked' => true,
                            'embedded' => true,
                        ])
                    </section>
                @endif
                @if($this->shouldShowEulPanel())
                    <section class="owwa-pa-slide-panel" data-slide="replacement" data-tour="useful-life">
                        <div class="owwa-pa-slide-copy">
                            <h2 class="fi-section-header-heading">Replacement Due - Semi-Expendable Only</h2>
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
            <button
                type="button"
                class="owwa-pa-export-btn"
                data-tour="export"
                x-on:click="$wire.exportAtRiskPdf(($store.paAtRisk && $store.paAtRisk.view) ? $store.paAtRisk.view : 'all')"
            >Export PDF</button>
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
                <h2 class="fi-section-header-heading">Procurement Summary</h2>
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
                        @if($hasAiNarrative || $aiNarrativeUnavailable || filled($recommendation))
                            <div class="owwa-pa-ai-recommendation-head">
                                @if($hasAiNarrative)
                                    <span class="owwa-pr-ai-pill">AI-generated</span>
                                @else
                                    <span class="owwa-pr-ai-pill owwa-pr-ai-pill--muted">Saved recommendation</span>
                                @endif
                                @if($lastAiRunId)
                                    <a href="{{ AiProcurementRunResource::getUrl('view', ['record' => $lastAiRunId]) }}" class="owwa-pa-ai-recommendation-meta-link">
                                        View saved run #{{ $lastAiRunId }} →
                                    </a>
                                @endif
                            </div>
                            @if($aiNarrativeUnavailable)
                                <p class="owwa-pa-ai-unavailable">
                                    AI narrative needs Ollama on this device. The lists below are the saved recommendation snapshot from the reorder and replacement-due lists, not a second Suggested Reorders panel.
                                </p>
                            @endif
                        @endif

                        @php $policySnippets = $this->getPolicySnippets(); @endphp
                        @if($policySnippets !== [])
                            <div class="owwa-pa-policy-used">
                                <p class="owwa-pa-policy-used-title">Policy used</p>
                                <ul class="owwa-pa-policy-used-list">
                                    @foreach($policySnippets as $snippet)
                                        <li>
                                            <span class="owwa-pa-policy-used-source">{{ $snippet['source'] ?? 'policy' }}</span>
                                            {{ \Illuminate\Support\Str::limit((string) ($snippet['content'] ?? ''), 160) }}
                                        </li>
                                    @endforeach
                                </ul>
                            </div>
                        @endif

                        @include('filament.partials.procurement-recommendation-items-table', [
                            'reorderRows' => collect($this->getRecommendationReorderInitialRows()),
                            'replacementRows' => collect($this->getRecommendationReplacementInitialRows()),
                            'reorderTotal' => $this->getRecommendationReorderRows()->count(),
                            'replacementTotal' => $this->getRecommendationReplacementRows()->count(),
                            'pageSize' => 25,
                            'chunked' => true,
                            'slides' => $this->availableAnalyticsSlides(),
                            'recommendationSlide' => $recommendationSlide,
                            'positionLabel' => $this->recommendationSlidePositionLabel(),
                            'showDoneButton' => true,
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
                x-on:click="window.dispatchEvent(new CustomEvent('owwa-ai-busy-force-start'))"
                wire:loading.attr="disabled"
                wire:target="generateAiRecommendation"
                class="owwa-pa-generate-btn owwa-pa-generate-btn--footer"
                data-tour="generate"
                @disabled($loading)
            >
                <span wire:loading.remove wire:target="generateAiRecommendation">Generate recommendation</span>
                <span wire:loading wire:target="generateAiRecommendation">Generating…</span>
            </button>
            <p class="owwa-pa-generate-hint">Uses the consumable reorder list and the replacement-due list</p>
        </div>
    </div>
    <x-filament-actions::modals />
    <template x-teleport="body" wire:ignore>
        <div x-cloak x-show="open" class="owwa-pa-tour">
            <div class="owwa-pa-tour-hole" x-show="hole.ready" x-bind:style="holeStyle"></div>
            <div class="owwa-pa-tour-shield" x-bind:class="{ 'is-dim': ! hole.ready }" x-on:click.prevent.stop></div>
            <div
                x-show="hole.ready"
                x-ref="card"
                class="owwa-pa-tour-card"
                role="dialog"
                aria-modal="true"
                aria-labelledby="owwa-pa-tour-title"
                aria-describedby="owwa-pa-tour-body"
                tabindex="-1"
                x-bind:style="cardStyle"
                x-on:keydown.tab="trapFocus($event)"
            >
                <p class="owwa-pa-tour-progress" x-text="progressLabel"></p>
                <h2 id="owwa-pa-tour-title" class="owwa-pa-tour-title" x-text="currentStep()?.title"></h2>
                <p id="owwa-pa-tour-body" class="owwa-pa-tour-body" x-text="currentStep()?.body"></p>
                <div class="owwa-pa-tour-actions">
                    <button type="button" class="owwa-pa-tour-back" x-on:click="back()" x-bind:disabled="index === 0">Back</button>
                    <button type="button" class="owwa-pa-tour-skip" x-on:click="close()">Skip</button>
                    <button type="button" class="owwa-pa-tour-next" x-on:click="next()" x-text="isLast ? 'Done' : 'Next'"></button>
                </div>
            </div>
        </div>
    </template>
    </div>

    @script
    <script>
        Alpine.data('atRiskPreviewTable', (config) => ({
            allRows: config.rows || [],
            view: config.view === 'stockouts' ? 'stockouts' : 'all',
            sortColumn: config.sortColumn || 'category',
            sortDirection: config.sortDirection || 'asc',
            pageSize: config.pageSize || 25,
            stockoutWithin: config.stockoutWithin || 2,
            totalCount: Number(config.totalCount || (config.rows || []).length),
            stockoutTotal: Number(config.stockoutTotal || 0),
            chunked: Boolean(config.chunked),
            visibleLimit: { all: config.pageSize || 25, stockouts: config.pageSize || 25 },
            pending: false,
            init() {
                if (typeof Alpine.store === 'function') {
                    if (Alpine.store('paAtRisk') === undefined) {
                        Alpine.store('paAtRisk', { view: this.view });
                    } else {
                        Alpine.store('paAtRisk').view = this.view;
                    }
                }
            },
            get allCount() {
                return this.chunked ? this.totalCount : this.allRows.length;
            },
            get stockoutCount() {
                if (this.chunked) {
                    return this.stockoutTotal;
                }

                return this.allRows.filter((row) => row.months_cover !== null && Number(row.months_cover) <= this.stockoutWithin).length;
            },
            setView(view) {
                this.view = view === 'stockouts' ? 'stockouts' : 'all';
                if (typeof Alpine.store === 'function' && Alpine.store('paAtRisk')) {
                    Alpine.store('paAtRisk').view = this.view;
                }
                if (this.chunked && this.$wire) {
                    this.$wire.setAtRiskView(this.view);
                }
            },
            filteredRows() {
                if (this.view !== 'stockouts') {
                    return this.allRows.slice();
                }

                return this.allRows.filter((row) => row.months_cover !== null && Number(row.months_cover) <= this.stockoutWithin);
            },
            priorityRank(row) {
                if (row.priority === 'High') {
                    return 0;
                }
                if (row.priority === 'Medium') {
                    return 1;
                }

                return 2;
            },
            byCategory(a, b) {
                const left = [
                    Number(a.category_sort_rank ?? 99),
                    String(a.category_name || '').toLowerCase(),
                    this.priorityRank(a),
                    String(a.item_name || '').toLowerCase(),
                ];
                const right = [
                    Number(b.category_sort_rank ?? 99),
                    String(b.category_name || '').toLowerCase(),
                    this.priorityRank(b),
                    String(b.item_name || '').toLowerCase(),
                ];

                for (let i = 0; i < left.length; i++) {
                    if (left[i] < right[i]) {
                        return -1;
                    }
                    if (left[i] > right[i]) {
                        return 1;
                    }
                }

                return 0;
            },
            sortedRows() {
                const rows = this.filteredRows();
                const direction = this.sortDirection === 'desc' ? -1 : 1;

                return rows.sort((a, b) => {
                    if (this.sortColumn === 'cover') {
                        const coverA = a.months_cover ?? (this.sortDirection === 'desc' ? -1 : 999);
                        const coverB = b.months_cover ?? (this.sortDirection === 'desc' ? -1 : 999);
                        const coverCmp = coverA === coverB ? 0 : (coverA < coverB ? -1 : 1) * direction;

                        return coverCmp !== 0 ? coverCmp : this.byCategory(a, b);
                    }

                    if (this.sortColumn === 'stockout') {
                        const stockoutA = a.projected_stockout_date || (this.sortDirection === 'desc' ? '0000-01-01' : '9999-12-31');
                        const stockoutB = b.projected_stockout_date || (this.sortDirection === 'desc' ? '0000-01-01' : '9999-12-31');
                        const stockoutCmp = stockoutA === stockoutB ? 0 : (stockoutA < stockoutB ? -1 : 1) * direction;

                        return stockoutCmp !== 0 ? stockoutCmp : this.byCategory(a, b);
                    }

                    if (this.sortColumn === 'priority') {
                        const priorityCmp = (this.priorityRank(a) - this.priorityRank(b)) * direction;

                        return priorityCmp !== 0 ? priorityCmp : this.byCategory(a, b);
                    }

                    return this.byCategory(a, b);
                });
            },
            visibleRows() {
                const limit = this.visibleLimit[this.view] || this.pageSize;

                return this.sortedRows().slice(0, limit);
            },
            hasMore() {
                if (this.chunked) {
                    return this.allRows.length < this.totalCount;
                }

                return this.visibleRows().length < this.sortedRows().length;
            },
            sortBy(column) {
                if (! ['priority', 'cover', 'stockout'].includes(column)) {
                    return;
                }

                if (this.sortColumn === column) {
                    this.sortDirection = this.sortDirection === 'asc' ? 'desc' : 'asc';
                } else {
                    this.sortColumn = column;
                    this.sortDirection = 'asc';
                }

                this.visibleLimit[this.view] = this.pageSize;
                if (this.chunked && this.$wire) {
                    this.$wire.sortAtRiskBy(column);
                }
            },
            sortIndicator(column) {
                if (this.sortColumn !== column) {
                    return '';
                }

                return this.sortDirection === 'asc' ? ' ↑' : ' ↓';
            },
            async onScroll(event) {
                if (this.pending) {
                    return;
                }

                const el = event.target;
                if (el.scrollTop < 24) {
                    return;
                }

                if ((el.scrollTop + el.clientHeight) < (el.scrollHeight - 80)) {
                    return;
                }

                if (! this.hasMore()) {
                    return;
                }

                this.pending = true;

                try {
                    if (this.chunked && this.$wire) {
                        const chunk = await this.$wire.loadMoreAtRiskRows();
                        if (Array.isArray(chunk) && chunk.length) {
                            this.allRows = this.allRows.concat(chunk);
                        }
                    } else {
                        const total = this.sortedRows().length;
                        this.visibleLimit[this.view] = Math.min(
                            (this.visibleLimit[this.view] || this.pageSize) + this.pageSize,
                            total,
                        );
                    }
                } finally {
                    this.pending = false;
                }
            },
            formatInt(value) {
                return Number(value || 0).toLocaleString();
            },
            formatFixed(value, digits) {
                return Number(value || 0).toLocaleString(undefined, {
                    minimumFractionDigits: digits,
                    maximumFractionDigits: digits,
                });
            },
        }));
    </script>
    @endscript
</x-filament-panels::page>

