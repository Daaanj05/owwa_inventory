@php
    use App\Services\EmployeeDistributionInventoryService;
    use App\Support\SemiExpendableUsefulLife;

    $summary = $this->getInventorySummary();
    $rows = $this->getInventoryRows();
    $sortBy = $this->sortBy;
    $sortDir = $this->sortDir;
    $category = $this->category;
    $categoryOptions = EmployeeDistributionInventoryService::categoryOptions();
    $propertyView = $this->usesPropertyIssuanceView();
    $custodyTab = $this->custodyTab;
@endphp

<x-filament-panels::page>
    <div class="owwa-inventory-layout">
        {{-- KPI cards --}}
        <div class="owwa-kpi-grid">
            <div class="owwa-kpi-card owwa-kpi-card-total">
                <span class="owwa-kpi-tooltip">Distinct items distributed to you in this category.</span>
                <div class="owwa-kpi-card-inner">
                    <span class="owwa-kpi-card-value">{{ number_format($summary['totalItems']) }}</span>
                    <span class="owwa-kpi-card-label">Distinct items</span>
                </div>
            </div>
            <div class="owwa-kpi-card owwa-kpi-card-ok">
                <span class="owwa-kpi-tooltip">Total quantity distributed to you in this category (all time).</span>
                <div class="owwa-kpi-card-inner">
                    <span class="owwa-kpi-card-value">{{ number_format($summary['totalQuantity']) }}</span>
                    <span class="owwa-kpi-card-label">Total received</span>
                </div>
            </div>
        </div>

        {{-- Category dropdown + search --}}
        <div class="owwa-search-wrap" style="display:flex;gap:0.75rem;flex-wrap:wrap;align-items:center;">
            <select wire:model.live="category" class="owwa-search-bar" style="max-width:14rem;" aria-label="Item category">
                @foreach ($categoryOptions as $value => $label)
                    <option value="{{ $value }}">{{ $label }}</option>
                @endforeach
            </select>
            <input
                type="text"
                wire:model.live.debounce.300ms="search"
                placeholder="Search items…"
                class="owwa-search-bar"
                style="width: 18rem; max-width: 100%;"
            />
        </div>

        @if ($propertyView)
            <div class="owwa-search-wrap" style="display:flex;gap:0.5rem;flex-wrap:wrap;">
                <button
                    type="button"
                    wire:click="$set('custodyTab', 'on_hand')"
                    class="owwa-search-bar {{ $custodyTab === 'on_hand' ? 'ring-2 ring-primary-500' : '' }}"
                    style="max-width:10rem;cursor:pointer;"
                >
                    On hand
                </button>
                <button
                    type="button"
                    wire:click="$set('custodyTab', 'history')"
                    class="owwa-search-bar {{ $custodyTab === 'history' ? 'ring-2 ring-primary-500' : '' }}"
                    style="max-width:10rem;cursor:pointer;"
                >
                    History
                </button>
            </div>
        @endif

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
                                @if ($propertyView)
                                    <th class="owwa-status">EUL status</th>
                                @endif
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($rows as $row)
                                @if ($propertyView)
                                    @php
                                        $slug = $row->template_slug ?? null;
                                        $eulStatus = $row->worst_eul_status ?? null;
                                    @endphp
                                    <tr>
                                        <td class="owwa-cell-primary">
                                            <button
                                                type="button"
                                                wire:click="openPropertyIssuanceLedger({{ (int) $row->item_id }})"
                                                class="text-left font-medium text-primary-600 hover:text-primary-500 dark:text-primary-400 dark:hover:text-primary-300"
                                            >
                                                {{ $row->item_name ?? '—' }}
                                            </button>
                                        </td>
                                        <td class="owwa-num owwa-cell-primary">{{ number_format((int) $row->total_quantity) }}</td>
                                        <td class="owwa-cell-muted">
                                            @if (filled($row->last_distribution_date))
                                                {{ \Illuminate\Support\Carbon::parse($row->last_distribution_date)->format('M d, Y') }}
                                            @else
                                                —
                                            @endif
                                        </td>
                                        <td class="owwa-num owwa-cell-muted">{{ number_format((int) $row->distribution_count) }}</td>
                                        <td class="owwa-status">
                                            @if($eulStatus === SemiExpendableUsefulLife::STATUS_EXPIRED)
                                                <span class="owwa-status-badge owwa-status-low">Expired</span>
                                            @elseif($eulStatus === SemiExpendableUsefulLife::STATUS_NEARING)
                                                <span class="owwa-status-badge owwa-status-low">Nearing</span>
                                            @elseif($slug === 'semi_expendable')
                                                <span class="owwa-status-badge owwa-status-ok">Active</span>
                                            @else
                                                <span class="owwa-cell-muted">N/A</span>
                                            @endif
                                        </td>
                                    </tr>
                                @else
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
                                @endif
                            @empty
                                <tr>
                                    <td colspan="{{ $propertyView ? 5 : 4 }}">
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
