@php
    $reorderRows = collect($reorderRows ?? []);
    $replacementRows = collect($replacementRows ?? []);
    $slides = array_values($slides ?? ['reorders']);
    $showArrows = count($slides) > 1;
    $positionLabel = $positionLabel ?? '';
    $showDoneButton = (bool) ($showDoneButton ?? false);
    $chunked = (bool) ($chunked ?? false);
    $pageSize = (int) ($pageSize ?? 25);
    $reorderTotal = (int) ($reorderTotal ?? $reorderRows->count());
    $replacementTotal = (int) ($replacementTotal ?? $replacementRows->count());

    $reorderClient = $reorderRows->map(fn ($row) => (array) $row)->values()->all();
    $replacementClient = $replacementRows->map(fn ($row) => (array) $row)->values()->all();
@endphp

<div
    class="owwa-pa-summary-slide"
    x-data="{
        slide: @entangle('recommendationSlide'),
        order: @js($slides),
        height: null,
        pageSize: {{ $pageSize }},
        chunked: {{ $chunked ? 'true' : 'false' }},
        reorderRows: @js($reorderClient),
        replacementRows: @js($replacementClient),
        reorderTotal: {{ $reorderTotal }},
        replacementTotal: {{ $replacementTotal }},
        pendingReorder: false,
        pendingReplacement: false,
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
        highRows() {
            return this.reorderRows.filter((row) => row.priority === 'High');
        },
        hasMoreReorder() {
            return this.reorderRows.length < this.reorderTotal;
        },
        hasMoreReplacement() {
            return this.replacementRows.length < this.replacementTotal;
        },
        async onReorderScroll(event) {
            if (! this.chunked || this.pendingReorder || ! this.hasMoreReorder()) {
                return;
            }
            const el = event.target;
            if ((el.scrollTop + el.clientHeight) < (el.scrollHeight - 80)) {
                return;
            }
            this.pendingReorder = true;
            try {
                const chunk = await this.$wire.loadMoreRecommendationReorderRows();
                if (Array.isArray(chunk) && chunk.length) {
                    this.reorderRows = this.reorderRows.concat(chunk);
                    this.$nextTick(() => this.measure());
                }
            } finally {
                this.pendingReorder = false;
            }
        },
        async onReplacementScroll(event) {
            if (! this.chunked || this.pendingReplacement || ! this.hasMoreReplacement()) {
                return;
            }
            const el = event.target;
            if ((el.scrollTop + el.clientHeight) < (el.scrollHeight - 80)) {
                return;
            }
            this.pendingReplacement = true;
            try {
                const chunk = await this.$wire.loadMoreRecommendationReplacementRows();
                if (Array.isArray(chunk) && chunk.length) {
                    this.replacementRows = this.replacementRows.concat(chunk);
                    this.$nextTick(() => this.measure());
                }
            } finally {
                this.pendingReplacement = false;
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
    <div class="owwa-pa-slide-viewport" x-bind:style="height ? ('height: ' + height + 'px') : ''">
        <div
            class="owwa-pa-slide-track {{ $showArrows ? 'is-dual' : '' }} {{ ($recommendationSlide ?? 'reorders') === 'replacement' ? 'is-replacement' : '' }}"
            x-bind:class="{ 'is-replacement': slide === 'replacement' }"
        >
            @if(in_array('reorders', $slides, true))
            <section class="owwa-pa-slide-panel" data-slide="reorders">
                <div class="owwa-pa-rec-section owwa-pa-callout owwa-pa-callout--info">
                    <h3 class="owwa-pa-rec-section-title">Consumable Summary</h3>
                    <template x-if="reorderRows.length === 0">
                        <p class="owwa-pa-summary-empty">No consumables are flagged in this recommendation.</p>
                    </template>
                    <div
                        class="owwa-pa-rec-scroll"
                        x-show="reorderRows.length > 0"
                        x-on:scroll.debounce.200ms="onReorderScroll($event)"
                    >
                        <div class="owwa-pa-rec-grid owwa-pa-rec-grid--summary" role="list">
                            <template x-for="(row, index) in reorderRows" :key="'summary-' + index + '-' + row.item_name">
                                <article class="owwa-pa-rec-card" role="listitem">
                                    <div class="owwa-pa-rec-card-head">
                                        <span
                                            class="owwa-status-badge"
                                            x-bind:class="row.priority === 'High' ? 'owwa-status-low' : 'owwa-status-medium'"
                                            x-text="row.priority || '—'"
                                        ></span>
                                        <strong class="owwa-pa-rec-card-name" x-text="row.item_name"></strong>
                                    </div>
                                    <p class="owwa-pa-rec-card-meta">
                                        <span x-text="'Stock ' + (row.current_stock !== null ? Number(row.current_stock).toLocaleString() : '—')"></span>
                                        <span aria-hidden="true">·</span>
                                        <span x-text="row.cover_phrase || '—'"></span>
                                    </p>
                                </article>
                            </template>
                        </div>
                        <div class="owwa-pa-load-more" x-show="hasMoreReorder()" x-cloak>Scroll to see more</div>
                    </div>
                </div>

                <div class="owwa-pa-rec-section owwa-pa-callout owwa-pa-callout--info">
                    <h3 class="owwa-pa-rec-section-title">Why these are flagged</h3>
                    <template x-if="highRows().length === 0">
                        <p class="owwa-pa-summary-empty">No High-priority consumables are flagged.</p>
                    </template>
                    <div
                        class="owwa-pa-rec-scroll"
                        x-show="highRows().length > 0"
                        x-on:scroll.debounce.200ms="onReorderScroll($event)"
                    >
                        <div class="owwa-pa-rec-grid owwa-pa-rec-grid--why" role="list">
                            <template x-for="(row, index) in highRows()" :key="'why-' + index + '-' + row.item_name">
                                <article class="owwa-pa-rec-card owwa-pa-rec-card--why" role="listitem">
                                    <strong class="owwa-pa-rec-card-name" x-text="row.item_name"></strong>
                                    <p class="owwa-pa-rec-card-explain" x-text="row.why_explanation || 'needs reorder attention'"></p>
                                </article>
                            </template>
                        </div>
                        <div class="owwa-pa-load-more" x-show="hasMoreReorder()" x-cloak>Scroll to see more</div>
                    </div>
                </div>

                <div class="owwa-pa-rec-section owwa-pa-callout owwa-pa-callout--info">
                    <h3 class="owwa-pa-rec-section-title">What to buy next</h3>
                    <template x-if="reorderRows.length === 0">
                        <p class="owwa-pa-summary-empty">Nothing to buy in this recommendation.</p>
                    </template>
                    <div
                        class="owwa-pa-rec-scroll"
                        x-show="reorderRows.length > 0"
                        x-on:scroll.debounce.200ms="onReorderScroll($event)"
                    >
                        <div class="owwa-pa-buy-table-shell" role="region" aria-label="What to buy next" tabindex="0">
                            <table class="owwa-pa-buy-table">
                                <thead>
                                    <tr>
                                        <th scope="col">Item</th>
                                        <th scope="col">Current stock</th>
                                        <th scope="col">Cover</th>
                                        <th scope="col">Buy</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <template x-for="(row, index) in reorderRows" :key="'buy-' + index + '-' + row.item_name">
                                        <tr>
                                            <th scope="row" x-text="row.item_name"></th>
                                            <td x-text="row.current_stock !== null ? Number(row.current_stock).toLocaleString() : '—'"></td>
                                            <td x-text="row.cover_phrase || '—'"></td>
                                            <td x-text="row.suggested_qty_min !== null ? Number(row.suggested_qty_min).toLocaleString() : '—'"></td>
                                        </tr>
                                    </template>
                                </tbody>
                            </table>
                        </div>
                        <div class="owwa-pa-load-more" x-show="hasMoreReorder()" x-cloak>Scroll to see more</div>
                    </div>
                </div>
            </section>
            @endif

            @if(in_array('replacement', $slides, true))
                <section class="owwa-pa-slide-panel" data-slide="replacement">
                    <div class="owwa-pa-rec-section owwa-pa-callout owwa-pa-callout--info">
                        <h3 class="owwa-pa-rec-section-title">Replace or Update Semi-Expendable Items</h3>
                        <template x-if="replacementRows.length === 0">
                            <p class="owwa-pa-summary-empty">No semi-expendable units need replacement or an update this cycle.</p>
                        </template>
                        <div
                            class="owwa-pa-rec-scroll"
                            x-show="replacementRows.length > 0"
                            x-on:scroll.debounce.200ms="onReplacementScroll($event)"
                        >
                            <div class="owwa-pa-rec-grid owwa-pa-rec-grid--semi" role="list">
                                <template x-for="(row, index) in replacementRows" :key="'semi-' + index + '-' + (row.property_number || row.item_name)">
                                    <article class="owwa-pa-rec-card owwa-pa-rec-card--semi" role="listitem">
                                        <strong class="owwa-pa-rec-card-name" x-text="row.item_name"></strong>
                                        <p class="owwa-pa-rec-card-meta" x-text="row.semi_meta || '—'"></p>
                                        <p class="owwa-pa-rec-card-action">
                                            <span x-text="row.eul_status || '—'"></span>
                                            <span aria-hidden="true">→</span>
                                            <span x-text="row.next_step || ''"></span>
                                        </p>
                                    </article>
                                </template>
                            </div>
                            <div class="owwa-pa-load-more" x-show="hasMoreReplacement()" x-cloak>Scroll to see more</div>
                        </div>
                    </div>
                </section>
            @endif
        </div>
    </div>
    @if($showArrows || $showDoneButton)
        <div class="owwa-pa-slide-footer {{ $showDoneButton ? 'owwa-pa-slide-footer--with-done' : '' }}">
            <div class="owwa-pa-slide-footer-start" aria-hidden="true"></div>
            <div class="owwa-pa-slide-footer-center">
                @if($showArrows)
                    <div class="owwa-pa-slide-nav" role="group" aria-label="Switch recommendation slide">
                        <button type="button" class="owwa-pa-slide-btn" aria-label="Previous recommendation slide" x-on:click="shift(-1)">←</button>
                        <span class="owwa-pa-slide-label" x-text="(Math.max(0, order.indexOf(slide)) + 1) + ' of ' + order.length">{{ $positionLabel }}</span>
                        <button type="button" class="owwa-pa-slide-btn" aria-label="Next recommendation slide" x-on:click="shift(1)">→</button>
                    </div>
                @endif
            </div>
            <div class="owwa-pa-slide-footer-end">
                @if($showDoneButton)
                    <button
                        type="button"
                        wire:click="dismissRecommendation"
                        wire:loading.attr="disabled"
                        wire:target="dismissRecommendation"
                        class="owwa-pa-generate-btn owwa-pa-generate-btn--secondary owwa-pa-done-btn"
                    >
                        Done
                    </button>
                @endif
            </div>
        </div>
    @endif
</div>
