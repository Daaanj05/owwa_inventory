@php
    $heading = $heading ?? 'At-risk items';
    $intro = $intro ?? 'Deterministic reorder signals from consumption history and current stock.';
    $atRiskView = $atRiskView ?? 'all';
    $sortColumn = $sortColumn ?? 'category';
    $sortDirection = $sortDirection ?? 'asc';
    $allRows = $allRows ?? collect($rows ?? []);
    $allAtRiskCount = $allAtRiskCount ?? $allRows->count();
    $stockoutCount = $stockoutCount ?? $allRows
        ->filter(fn ($row): bool => ($row->months_cover ?? $row['months_cover'] ?? null) !== null
            && (float) ($row->months_cover ?? $row['months_cover']) <= 2.0)
        ->count();
    $embedded = (bool) ($embedded ?? false);
    $chunked = (bool) ($chunked ?? false);
    $pageSize = (int) ($pageSize ?? 25);
    $stockoutWithinMonths = (float) ($stockoutWithinMonths ?? 2.0);
    $totalCount = (int) ($totalCount ?? $allAtRiskCount);

    $clientRows = $allRows
        ->map(function ($row): array {
            $row = (object) $row;

            return [
                'item_id' => (int) ($row->item_id ?? 0),
                'office_id' => (int) ($row->office_id ?? 0),
                'item_name' => (string) ($row->item_name ?? ''),
                'category_name' => (string) ($row->category_name ?? ''),
                'category_sort_rank' => (int) ($row->category_sort_rank ?? 99),
                'current_stock' => (int) ($row->current_stock ?? 0),
                'reorder_level' => (int) ($row->reorder_level ?? 0),
                'forecast_monthly_usage' => (float) ($row->forecast_monthly_usage ?? 0),
                'months_cover' => isset($row->months_cover) && $row->months_cover !== null ? (float) $row->months_cover : null,
                'suggested_reorder_qty' => isset($row->suggested_reorder_qty) && $row->suggested_reorder_qty !== null ? (int) $row->suggested_reorder_qty : null,
                'projected_stockout_date' => $row->projected_stockout_date ?? null,
                'latest_unit_cost' => isset($row->latest_unit_cost) && $row->latest_unit_cost !== null ? (float) $row->latest_unit_cost : null,
                'priority' => (string) ($row->priority ?? ''),
            ];
        })
        ->values()
        ->all();
@endphp

@if (! $embedded)
<div class="owwa-pa-card fi-section rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
@endif
    <div
        class="{{ $embedded ? 'owwa-pa-slide-body' : 'fi-section-header-ctn px-5 py-3 border-b border-gray-200 dark:border-white/10' }}"
        x-data="atRiskPreviewTable({
            rows: @js($clientRows),
            view: @js($atRiskView),
            sortColumn: @js($sortColumn),
            sortDirection: @js($sortDirection),
            pageSize: {{ $pageSize }},
            stockoutWithin: {{ $stockoutWithinMonths }},
            totalCount: {{ $totalCount }},
            stockoutTotal: {{ (int) $stockoutCount }},
            chunked: {{ $chunked ? 'true' : 'false' }},
        })"
        x-init="init()"
    >
        @if (! $embedded)
        <div class="owwa-pa-table-header">
            <div>
                <h2 class="fi-section-header-heading">{{ $heading }}</h2>
                <p class="fi-section-header-description mt-1">{{ $intro }}</p>
            </div>
            <div class="owwa-pa-table-actions">
                <button type="button" class="owwa-pa-export-btn" x-on:click="$wire.exportAtRiskPdf(view)">
                    Export PDF
                </button>
            </div>
        </div>
        @endif
        <div class="owwa-pa-view-tabs" data-tour="filters" role="tablist" aria-label="Suggested reorders">
            <button
                type="button"
                role="tab"
                x-on:click="setView('all')"
                class="owwa-pa-view-tab"
                x-bind:class="{ 'is-active': view === 'all' }"
                x-bind:aria-selected="view === 'all' ? 'true' : 'false'"
            >
                <span x-text="'All at-risk (' + allCount + ')'">All at-risk ({{ $allAtRiskCount }})</span>
            </button>
            <button
                type="button"
                role="tab"
                x-on:click="setView('stockouts')"
                class="owwa-pa-view-tab"
                x-bind:class="{ 'is-active': view === 'stockouts' }"
                x-bind:aria-selected="view === 'stockouts' ? 'true' : 'false'"
            >
                <span x-text="'Stocking out in ≤2 mo (' + stockoutCount + ')'">Stocking out in ≤2 mo ({{ $stockoutCount }})</span>
            </button>
        </div>
        <p class="owwa-pa-tab-helper">
            Stocking-out view includes items projected to run out within 2 months, based on the last 12 months of issuances from the supply office.
        </p>

        <div class="owwa-pa-slide-table">
            <div x-show="sortedRows().length === 0" x-cloak>
                <div class="owwa-empty-state">
                    <svg class="owwa-empty-state-icon" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round"
                              d="M12 6v6l4 2" />
                        <path stroke-linecap="round" stroke-linejoin="round"
                              d="M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                    </svg>
                    <h3 class="owwa-empty-state-title" x-text="view === 'stockouts' ? 'No projected stockouts in this filter' : 'No at-risk items for this filter'">
                        {{ $atRiskView === 'stockouts' ? 'No projected stockouts in this filter' : 'No at-risk items for this filter' }}
                    </h3>
                    <p class="owwa-empty-state-text">
                        <span x-show="view === 'stockouts'">
                            No items are projected to run out within 2 months at the current issuing pace.
                            Forecast uses the last 12 months of issuances from the supply office; try All at-risk or a different category.
                        </span>
                        <span x-show="view !== 'stockouts'">
                            Try another category or date range, or confirm issuance history exists for the selected period.
                        </span>
                    </p>
                </div>
            </div>

            <div
                x-show="sortedRows().length > 0"
                class="owwa-table-wrap owwa-table-wrap--scroll owwa-pa-table-shell owwa-pa-table-scroll"
                x-on:scroll.debounce.200ms="onScroll($event)"
            >
                <table class="owwa-data-table owwa-pa-reorders-table">
                    <thead>
                    <tr>
                        <th style="width: 80px;">
                            <button type="button" class="owwa-pa-sort-btn" x-on:click="sortBy('priority')">
                                Priority<span x-text="sortIndicator('priority')"></span>
                            </button>
                        </th>
                        <th>Item</th>
                        <th class="owwa-num">Stock</th>
                        <th class="owwa-num">Forecast/mo</th>
                        <th class="owwa-num">
                            <button type="button" class="owwa-pa-sort-btn" x-on:click="sortBy('cover')">
                                Cover<span x-text="sortIndicator('cover')"></span>
                            </button>
                        </th>
                        <th class="owwa-num">
                            <button type="button" class="owwa-pa-sort-btn" x-on:click="sortBy('stockout')">
                                Stockout<span x-text="sortIndicator('stockout')"></span>
                            </button>
                        </th>
                        <th class="owwa-num">Unit cost</th>
                        <th class="owwa-num">Suggested</th>
                    </tr>
                    </thead>
                    <tbody>
                    <template x-for="row in visibleRows()" :key="row.item_id + '-' + row.office_id">
                        <tr class="owwa-data-table__row" x-bind:class="row.priority === 'High' ? 'owwa-row-low' : 'owwa-row-medium'">
                            <td>
                                <span
                                    class="owwa-status-badge"
                                    x-bind:class="row.priority === 'High' ? 'owwa-status-low' : 'owwa-status-medium'"
                                    x-text="row.priority"
                                ></span>
                            </td>
                            <td class="owwa-cell-primary" x-text="row.item_name"></td>
                            <td
                                class="owwa-num"
                                x-bind:class="row.current_stock <= row.reorder_level ? 'owwa-cell-danger' : 'owwa-cell-primary'"
                                x-text="formatInt(row.current_stock)"
                            ></td>
                            <td class="owwa-num owwa-cell-muted" x-text="formatFixed(row.forecast_monthly_usage, 1)"></td>
                            <td class="owwa-num owwa-cell-muted owwa-pa-cover-cell">
                                <template x-if="row.months_cover !== null">
                                    <span>
                                        <span class="owwa-pa-cover-bar-wrap" aria-hidden="true">
                                            <span class="owwa-pa-cover-bar" x-bind:style="'width: ' + Math.min(100, row.months_cover / 6 * 100) + '%;'"></span>
                                        </span>
                                        <span class="owwa-pa-cover-val" x-text="formatFixed(row.months_cover, 1) + ' mo'"></span>
                                    </span>
                                </template>
                                <template x-if="row.months_cover === null">
                                    <span class="owwa-pa-cover-val">—</span>
                                </template>
                            </td>
                            <td class="owwa-num owwa-cell-muted" x-text="row.projected_stockout_date || '—'"></td>
                            <td class="owwa-num owwa-cell-muted" x-text="row.latest_unit_cost !== null ? ('₱' + formatFixed(row.latest_unit_cost, 2)) : '—'"></td>
                            <td class="owwa-num owwa-cell-primary" x-text="row.suggested_reorder_qty !== null ? formatInt(row.suggested_reorder_qty) : '—'"></td>
                        </tr>
                    </template>
                    </tbody>
                </table>
                <div class="owwa-pa-load-more" x-show="hasMore()" x-cloak>Scroll to see more</div>
            </div>

            {{-- SSR fallback for Livewire tests without Alpine --}}
            <noscript>
                @php
                    $ssrRows = collect($clientRows)->take($pageSize);
                @endphp
                @if($ssrRows->isEmpty())
                    <p>No at-risk items for this filter</p>
                @else
                    <ul>
                        @foreach($ssrRows as $row)
                            <li>{{ $row['item_name'] }}</li>
                        @endforeach
                    </ul>
                    @if(count($clientRows) > $pageSize)
                        <div class="owwa-pa-load-more">Scroll to see more</div>
                    @endif
                @endif
            </noscript>
        </div>
    </div>
@if (! $embedded)
</div>
@endif
