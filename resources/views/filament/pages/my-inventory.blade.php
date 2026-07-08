@php
    use App\Services\EmployeeDistributionInventoryService;

    $summary = $this->getInventorySummary();
    $rows = $this->getInventoryRows();
    $sortBy = $this->sortBy;
    $sortDir = $this->sortDir;
    $category = $this->category;
    $categoryOptions = EmployeeDistributionInventoryService::categoryOptions();
@endphp

<x-filament-panels::page>
    <div class="owwa-inventory-layout">
        {{-- KPI cards --}}
        <div class="owwa-kpi-grid">
            <div class="owwa-kpi-card owwa-kpi-card-total">
                <span class="owwa-kpi-tooltip">Distinct items distributed to you{{ $category === 'all' ? '' : ' in this category' }}.</span>
                <div class="owwa-kpi-card-inner">
                    <span class="owwa-kpi-card-value">{{ number_format($summary['totalItems']) }}</span>
                    <span class="owwa-kpi-card-label">Distinct items</span>
                </div>
            </div>
            <div class="owwa-kpi-card owwa-kpi-card-ok">
                <span class="owwa-kpi-tooltip">Total quantity distributed to you{{ $category === 'all' ? ' (all time)' : ' in this category (all time)' }}.</span>
                <div class="owwa-kpi-card-inner">
                    <span class="owwa-kpi-card-value">{{ number_format($summary['totalQuantity']) }}</span>
                    <span class="owwa-kpi-card-label">Total received</span>
                </div>
            </div>
        </div>

        {{-- Search + category tabs --}}
        <div class="owwa-search-wrap">
            <input
                type="text"
                wire:model.live.debounce.300ms="search"
                placeholder="Search items or category…"
                class="owwa-search-bar"
            />
            <div class="owwa-pa-view-tabs owwa-stock-restock-tabs" role="tablist" aria-label="Item category filter">
                @foreach ($categoryOptions as $value => $label)
                    <button
                        type="button"
                        role="tab"
                        wire:click="setCategory(@js($value))"
                        class="owwa-pa-view-tab {{ $category === $value ? 'is-active' : '' }}"
                        aria-selected="{{ $category === $value ? 'true' : 'false' }}"
                    >
                        {{ $label }}
                    </button>
                @endforeach
            </div>
        </div>

        {{-- Data panel --}}
        <div class="owwa-data-panel">
            <div class="owwa-data-panel-body">
                <div class="owwa-table-wrap">
                    <table class="owwa-data-table">
                        <thead>
                            <tr>
                                @php
                                    $columns = [
                                        'item_name' => 'Item',
                                        'category_name' => 'Category',
                                        'quantity' => 'Total qty',
                                        'distribution_date' => 'Last received',
                                        'distribution_count' => 'Distributions',
                                    ];
                                @endphp
                                @foreach($columns as $col => $label)
                                    <th
                                        wire:click="sortByColumn('{{ $col }}')"
                                        style="cursor: pointer; user-select: none;"
                                        class="{{ in_array($col, ['quantity', 'distribution_count'], true) ? 'owwa-num' : '' }}"
                                    >
                                        {{ $label }}
                                        @if($sortBy === $col)
                                            <span style="font-size: 0.65rem; margin-left: 0.25rem;">{{ $sortDir === 'asc' ? '▲' : '▼' }}</span>
                                        @endif
                                    </th>
                                @endforeach
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($rows as $row)
                                <tr>
                                    <td class="owwa-cell-primary">
                                        <button
                                            type="button"
                                            wire:click="openDistributionLedger({{ (int) $row->item_id }})"
                                            class="text-left font-medium text-primary-600 hover:text-primary-500 dark:text-primary-400 dark:hover:text-primary-300"
                                            title="{{ $row->item_name }}"
                                        >
                                            {{ $row->item_name }}
                                        </button>
                                    </td>
                                    <td class="owwa-cell-muted">{{ $row->category_name }}</td>
                                    <td class="owwa-num owwa-cell-primary">{{ number_format((int) $row->total_quantity) }}</td>
                                    <td class="owwa-cell-muted">
                                        @if (filled($row->last_distribution_date))
                                            {{ \Illuminate\Support\Carbon::parse($row->last_distribution_date)->format('M d, Y') }}
                                        @else
                                            —
                                        @endif
                                    </td>
                                    <td class="owwa-num owwa-cell-muted">{{ number_format((int) $row->distribution_count) }}</td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="5">
                                        <div class="owwa-empty">
                                            <svg class="owwa-empty-icon" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M20.25 7.5l-.625 10.632a2.25 2.25 0 01-2.247 2.118H6.622a2.25 2.25 0 01-2.247-2.118L3.75 7.5M10 11.25h4M3.375 7.5h17.25c.621 0 1.125-.504 1.125-1.125v-1.5c0-.621-.504-1.125-1.125-1.125H3.375c-.621 0-1.125.504-1.125 1.125v1.5c0 .621.504 1.125 1.125 1.125z" />
                                            </svg>
                                            <p class="owwa-empty-title">No items yet</p>
                                            <p class="owwa-empty-desc">Items distributed to you by your Unit Consolidator will appear here.</p>
                                        </div>
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        {{ $rows->links('vendor.pagination.owwa') }}
    </div>
</x-filament-panels::page>
