@php
    $reorderRows = $reorderRows ?? collect();
    $replacementRows = $replacementRows ?? collect();
    $slides = array_values($slides ?? ['reorders']);
    $showArrows = count($slides) > 1;
    $positionLabel = $positionLabel ?? '';
@endphp

<div
    class="owwa-pa-summary-slide"
    x-data="{
        slide: @entangle('recommendationSlide'),
        order: @js($slides),
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
    <div class="owwa-pa-slide-viewport" x-bind:style="height ? ('height: ' + height + 'px') : ''">
        <div
            class="owwa-pa-slide-track {{ $showArrows ? 'is-dual' : '' }} {{ ($recommendationSlide ?? 'reorders') === 'replacement' ? 'is-replacement' : '' }}"
            x-bind:class="{ 'is-replacement': slide === 'replacement' }"
        >
            @if(in_array('reorders', $slides, true))
            <section class="owwa-pa-slide-panel" data-slide="reorders">
                <h3 class="owwa-pa-summary-slide-title">Consumables to buy</h3>
                @if($reorderRows->isEmpty())
                    <p class="owwa-pa-summary-empty">No consumables to reorder in this filter.</p>
                @else
                    <div class="owwa-pa-summary-table-wrap owwa-pa-table-scroll">
                        <table class="owwa-pa-summary-table">
                            <thead>
                                <tr>
                                    <th>Priority</th>
                                    <th>Item</th>
                                    <th>Stock</th>
                                    <th>Suggested</th>
                                    <th>Cover</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($reorderRows as $row)
                                    <tr>
                                        <td>
                                            @if($row->priority === 'High')
                                                <span class="owwa-status-badge owwa-status-low">High</span>
                                            @else
                                                <span class="owwa-status-badge owwa-status-medium">Medium</span>
                                            @endif
                                        </td>
                                        <td>{{ $row->item_name }}</td>
                                        <td>{{ number_format($row->current_stock) }}</td>
                                        <td>
                                            @if($row->suggested_qty_min !== null)
                                                {{ number_format($row->suggested_qty_min) }}
                                            @else
                                                —
                                            @endif
                                        </td>
                                        <td>
                                            @if($row->months_cover !== null)
                                                {{ number_format($row->months_cover, 1) }} mo
                                            @else
                                                —
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </section>
            @endif
            @if(in_array('replacement', $slides, true))
                <section class="owwa-pa-slide-panel" data-slide="replacement">
                    <h3 class="owwa-pa-summary-slide-title">Semi-expendable replacement</h3>
                    @if($replacementRows->isEmpty())
                        <p class="owwa-pa-summary-empty">No semi-expendable units are due for replacement review.</p>
                    @else
                        <div class="owwa-pa-summary-table-wrap owwa-pa-table-scroll">
                            <table class="owwa-pa-summary-table">
                                <thead>
                                    <tr>
                                        <th>Item</th>
                                        <th>Property number</th>
                                        <th>Status</th>
                                        <th>Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach($replacementRows as $row)
                                        <tr>
                                            <td>{{ $row->item_name }}</td>
                                            <td>{{ $row->property_number ?? '—' }}</td>
                                            <td>{{ $row->eul_status ?? '—' }}</td>
                                            <td>{{ $row->reason ?? '—' }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif
                </section>
            @endif
        </div>
    </div>
    @if($showArrows)
        <div class="owwa-pa-slide-footer">
            <div class="owwa-pa-slide-nav" role="group" aria-label="Switch recommendation list">
                <button type="button" class="owwa-pa-slide-btn" aria-label="Previous recommendation list" x-on:click="shift(-1)">←</button>
                <span class="owwa-pa-slide-label" x-text="(Math.max(0, order.indexOf(slide)) + 1) + ' of ' + order.length">{{ $positionLabel }}</span>
                <button type="button" class="owwa-pa-slide-btn" aria-label="Next recommendation list" x-on:click="shift(1)">→</button>
            </div>
        </div>
    @endif
</div>
