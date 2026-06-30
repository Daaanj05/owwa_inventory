@php
    use App\Filament\Pages\StockLevels;

    $heading = $heading ?? 'At-risk items';
    $intro = $intro ?? 'Deterministic reorder signals from consumption history and current stock.';
    $atRiskView = $atRiskView ?? 'all';
    $sortColumn = $sortColumn ?? 'priority';
    $sortDirection = $sortDirection ?? 'asc';
    $allAtRiskCount = $allAtRiskCount ?? $rows->count();
    $stockoutCount = $stockoutCount ?? 0;

    $sortIndicator = static function (string $column) use ($sortColumn, $sortDirection): string {
        if ($sortColumn !== $column) {
            return '';
        }

        return $sortDirection === 'asc' ? ' ↑' : ' ↓';
    };
@endphp

<div class="owwa-pa-card fi-section rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
    <div class="fi-section-header-ctn px-5 py-3 border-b border-gray-200 dark:border-white/10">
        <div class="owwa-pa-table-header">
            <div>
                <h2 class="fi-section-header-heading">{{ $heading }}</h2>
                <p class="fi-section-header-description mt-1">{{ $intro }}</p>
            </div>
            <div class="owwa-pa-table-actions">
                <button type="button" wire:click="exportAtRiskCsv" class="owwa-pa-export-btn">
                    Export CSV
                </button>
            </div>
        </div>
        <div class="owwa-pa-view-tabs" role="tablist" aria-label="At-risk view">
            <button
                type="button"
                role="tab"
                wire:click="setAtRiskView('all')"
                class="owwa-pa-view-tab {{ $atRiskView === 'all' ? 'is-active' : '' }}"
                aria-selected="{{ $atRiskView === 'all' ? 'true' : 'false' }}"
            >
                All at-risk ({{ $allAtRiskCount }})
            </button>
            <button
                type="button"
                role="tab"
                wire:click="setAtRiskView('stockouts')"
                class="owwa-pa-view-tab {{ $atRiskView === 'stockouts' ? 'is-active' : '' }}"
                aria-selected="{{ $atRiskView === 'stockouts' ? 'true' : 'false' }}"
            >
                Stocking out in ≤2 mo ({{ $stockoutCount }})
            </button>
        </div>
        <p class="owwa-pa-tab-helper">
            Stocking-out view includes items projected to run out within 2 months (based on 12-month issuance forecast)
            and High-priority items below reorder with no recent usage.
        </p>
    </div>
    <div class="px-5 py-3">
        @if($rows->isEmpty())
            <div class="owwa-empty-state">
                <svg class="owwa-empty-state-icon" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5"
                          d="M12 6v6l4 2" />
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5"
                          d="M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                </svg>
                <h3 class="owwa-empty-state-title">
                    {{ $atRiskView === 'stockouts' ? 'No projected stockouts in this filter' : 'No at-risk items for this filter' }}
                </h3>
                <p class="owwa-empty-state-text">
                    @if($atRiskView === 'stockouts')
                        No items are projected to run out within 2 months at the current issuing pace.
                        High-priority items below reorder without recent usage would also appear here.
                        Forecast uses the last 12 months of issuances; try All at-risk or a different category.
                    @else
                        Try another category or date range, or confirm issuance history exists for the selected period.
                    @endif
                </p>
            </div>
        @else
            <div class="owwa-table-wrap owwa-table-wrap--scroll owwa-pa-table-shell owwa-pa-table-scroll">
                <table class="owwa-data-table owwa-data-table--zebra">
                    <thead>
                    <tr>
                        <th style="width: 80px;">
                            <button type="button" class="owwa-pa-sort-btn" wire:click="sortAtRiskBy('priority')">
                                Priority{{ $sortIndicator('priority') }}
                            </button>
                        </th>
                        <th>Item</th>
                        <th>Office</th>
                        <th class="owwa-num">Stock</th>
                        <th class="owwa-num">Forecast/mo</th>
                        <th class="owwa-num">
                            <button type="button" class="owwa-pa-sort-btn" wire:click="sortAtRiskBy('cover')">
                                Cover{{ $sortIndicator('cover') }}
                            </button>
                        </th>
                        <th class="owwa-num">
                            <button type="button" class="owwa-pa-sort-btn" wire:click="sortAtRiskBy('stockout')">
                                Stockout{{ $sortIndicator('stockout') }}
                            </button>
                        </th>
                        <th class="owwa-num">Unit cost</th>
                        <th class="owwa-num">Suggested</th>
                        <th style="width: 72px;">Actions</th>
                    </tr>
                    </thead>
                    <tbody>
                    @foreach($rows as $row)
                        <tr class="owwa-data-table__row {{ $row->priority === 'High' ? 'owwa-row-low' : '' }}">
                            <td>
                                @if($row->priority === 'High')
                                    <span class="owwa-status-badge owwa-status-low">High</span>
                                @else
                                    <span class="owwa-status-badge" style="background:#fff7ed;color:#9a3412;border:1px solid #fed7aa;">Medium</span>
                                @endif
                            </td>
                            <td class="owwa-cell-primary">
                                {{ $row->item_name }}
                                @if(! ($row->has_recent_usage ?? true))
                                    <span class="owwa-pa-usage-badge">No recent usage</span>
                                @endif
                            </td>
                            <td class="owwa-cell-muted">{{ $row->office_name }}</td>
                            <td class="owwa-num {{ $row->current_stock < $row->reorder_level ? 'owwa-cell-danger' : 'owwa-cell-primary' }}">
                                {{ number_format($row->current_stock) }}
                            </td>
                            <td class="owwa-num owwa-cell-muted">
                                @if($row->has_recent_usage ?? true)
                                    {{ number_format($row->forecast_monthly_usage, 1) }}
                                @else
                                    —
                                @endif
                            </td>
                            <td class="owwa-num owwa-cell-muted owwa-pa-cover-cell">
                                @if($row->months_cover !== null)
                                    <span class="owwa-pa-cover-bar-wrap" aria-hidden="true">
                                        <span class="owwa-pa-cover-bar" style="width: {{ min(100, (float) $row->months_cover / 6 * 100) }}%;"></span>
                                    </span>
                                    <span class="owwa-pa-cover-val">{{ number_format($row->months_cover, 1) }} mo</span>
                                @else
                                    <span class="owwa-pa-cover-val">—</span>
                                @endif
                            </td>
                            <td class="owwa-num owwa-cell-muted">{{ $row->projected_stockout_date ?? '—' }}</td>
                            <td class="owwa-num owwa-cell-muted">
                                {{ $row->latest_unit_cost !== null ? '₱' . number_format($row->latest_unit_cost, 2) : '—' }}
                            </td>
                            <td class="owwa-num owwa-cell-primary">
                                {{ $row->suggested_reorder_qty !== null ? number_format($row->suggested_reorder_qty) : '—' }}
                            </td>
                            <td>
                                @if(isset($row->item_category_id))
                                    <a
                                        href="{{ StockLevels::getUrl(['category' => $row->item_category_id]) }}"
                                        class="owwa-pa-row-link"
                                        title="Stock levels"
                                    >Stock</a>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>
</div>
