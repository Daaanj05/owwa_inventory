@php
    $summary = $this->getStockSummary();
    $rows = $this->getStockRows();
    $sortBy = $this->sortBy;
    $sortDir = $this->sortDir;
    $officeName = $this->getSupplyOfficeName();
    $categories = $this->getCategoryOptions();
@endphp

<x-filament-panels::page>
    <div class="owwa-inventory-layout">
        <div class="owwa-kpi-grid">
            <div class="owwa-kpi-card owwa-kpi-card-total">
                <span class="owwa-kpi-tooltip">Items with stock at the regional supply office.</span>
                <div class="owwa-kpi-card-inner">
                    <span class="owwa-kpi-card-value">{{ number_format($summary['total']) }}</span>
                    <span class="owwa-kpi-card-label">Requestable items</span>
                </div>
            </div>
            <div class="owwa-kpi-card owwa-kpi-card-ok">
                <span class="owwa-kpi-tooltip">Items above reorder level at regional supply.</span>
                <div class="owwa-kpi-card-inner">
                    <span class="owwa-kpi-card-value">{{ number_format($summary['okCount']) }}</span>
                    <span class="owwa-kpi-card-label">In stock</span>
                </div>
            </div>
            <div class="owwa-kpi-card owwa-kpi-card-low">
                <span class="owwa-kpi-tooltip">Items at or below reorder level at regional supply.</span>
                <div class="owwa-kpi-card-inner">
                    <span class="owwa-kpi-card-value">{{ number_format($summary['lowCount']) }}</span>
                    <span class="owwa-kpi-card-label">Low stock</span>
                </div>
            </div>
        </div>

        <div class="owwa-search-wrap" style="display:flex;gap:0.75rem;flex-wrap:wrap;align-items:center;">
            @if(count($categories) > 0)
                <select wire:model.live="category" class="owwa-search-bar" style="max-width:14rem;">
                    <option value="">All categories</option>
                    @foreach($categories as $id => $name)
                        <option value="{{ $id }}">{{ $name }}</option>
                    @endforeach
                </select>
            @endif
            <input
                type="text"
                wire:model.live.debounce.300ms="search"
                placeholder="Search items or category…"
                class="owwa-search-bar"
                style="flex:1;min-width:12rem;"
            />
            <span class="owwa-status-badge owwa-status-ok">{{ $officeName }}</span>
        </div>

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
                                        'stock' => 'Available',
                                        'reorder_level' => 'Reorder',
                                    ];
                                @endphp
                                @foreach($columns as $col => $label)
                                    <th
                                        wire:click="sortByColumn('{{ $col }}')"
                                        style="cursor: pointer; user-select: none;"
                                        class="{{ in_array($col, ['stock', 'reorder_level']) ? 'owwa-num' : '' }}"
                                    >
                                        {{ $label }}
                                        @if($sortBy === $col)
                                            <span style="font-size: 0.65rem; margin-left: 0.25rem;">{{ $sortDir === 'asc' ? '▲' : '▼' }}</span>
                                        @endif
                                    </th>
                                @endforeach
                                <th class="owwa-status">Status</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($rows as $row)
                                <tr class="{{ $row->is_low ? 'owwa-row-low' : '' }}">
                                    <td class="owwa-cell-primary">{{ $row->item_name }}</td>
                                    <td class="owwa-cell-muted">{{ $row->category_name }}</td>
                                    <td class="owwa-num {{ $row->is_low ? 'owwa-cell-danger' : 'owwa-cell-primary' }}">{{ number_format($row->stock) }}</td>
                                    <td class="owwa-num owwa-cell-muted">{{ number_format($row->reorder_level) }}</td>
                                    <td class="owwa-status">
                                        @if($row->is_low)
                                            <span class="owwa-status-badge owwa-status-low">Low</span>
                                        @else
                                            <span class="owwa-status-badge owwa-status-ok">OK</span>
                                        @endif
                                    </td>
                                    <td class="owwa-status">
                                        <a href="{{ $this->requestItemUrl($row->item_id) }}" class="owwa-status-badge owwa-status-ok" style="text-decoration:none;">
                                            Request
                                        </a>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="6">
                                        <div class="owwa-empty">
                                            <svg class="owwa-empty-icon" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M20.25 7.5l-.625 10.632a2.25 2.25 0 01-2.247 2.118H6.622a2.25 2.25 0 01-2.247-2.118L3.75 7.5M10 11.25h4M3.375 7.5h17.25c.621 0 1.125-.504 1.125-1.125v-1.5c0-.621-.504-1.125-1.125-1.125H3.375c-.621 0-1.125.504-1.125 1.125v1.5c0 .621.504 1.125 1.125 1.125z" />
                                            </svg>
                                            <p class="owwa-empty-title">No regional stock listed</p>
                                            <p class="owwa-empty-desc">No items with inventory activity at {{ $officeName }} yet, or no regional office is configured.</p>
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
