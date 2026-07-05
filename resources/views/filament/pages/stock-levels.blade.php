@php
    use App\Support\ItemPropertyClass;
    use App\Support\SemiExpendableValueCategory;

    $summary = $this->getStockLevelsSummary();
    $rows = $this->getStockLevels();
    $total = $summary['total'];
    $totalStockQty = $summary['totalStockQty'];
    $lowCount = $summary['lowCount'];
    $okCount = $summary['okCount'];
    $usesTaggedUnits = $this->usesTaggedUnitsColumn();
    $sortBy = $this->sortBy;
    $sortDir = $this->sortDir;
    $restockFilter = $this->restockFilter;
    $isSemiExpendable = $this->categoryRecord?->getTemplateSlug() === 'semi_expendable';
    $propertyClassOptions = ItemPropertyClass::options();
@endphp

<x-filament-panels::page>
    <div class="owwa-inventory-layout">
        {{-- KPI cards row --}}
        <div class="owwa-kpi-grid owwa-kpi-grid--4">
            <div class="owwa-kpi-card owwa-kpi-card-total">
                <span class="owwa-kpi-tooltip">Number of listed items in this category.</span>
                <div class="owwa-kpi-card-inner">
                    <span class="owwa-kpi-card-value">{{ number_format($total) }}</span>
                    <span class="owwa-kpi-card-label">Total items</span>
                </div>
            </div>
            <div class="owwa-kpi-card owwa-kpi-card-ok">
                <span class="owwa-kpi-tooltip">Number of listed items that currently have available stock.</span>
                <div class="owwa-kpi-card-inner">
                    <span class="owwa-kpi-card-value">{{ number_format($okCount) }}</span>
                    <span class="owwa-kpi-card-label">In stock</span>
                </div>
            </div>
            <div class="owwa-kpi-card owwa-kpi-card-low">
                <span class="owwa-kpi-tooltip">Number of listed items currently at or below reorder level.</span>
                <div class="owwa-kpi-card-inner">
                    <span class="owwa-kpi-card-value">{{ number_format($lowCount) }}</span>
                    <span class="owwa-kpi-card-label">Low stock</span>
                </div>
            </div>
            <div class="owwa-kpi-card owwa-kpi-card-total">
                <span class="owwa-kpi-tooltip">
                    @if ($usesTaggedUnits)
                        Total quantity on hand across all listed items (sum of Stock column). Accountable tags include warehouse and issued-in-use property tags used for physical count.
                    @else
                        Total quantity on hand across all listed items (sum of Stock column).
                    @endif
                </span>
                <div class="owwa-kpi-card-inner">
                    <span class="owwa-kpi-card-value">{{ number_format($totalStockQty) }}</span>
                    <span class="owwa-kpi-card-label">Total stock</span>
                </div>
            </div>
        </div>

        @if ($isSemiExpendable && ($missingPropertyClassCount = $this->getMissingPropertyClassCount()) > 0)
            <div class="owwa-data-panel-alert owwa-data-panel-alert-full" role="alert">
                <svg class="owwa-alert-icon" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126z" />
                </svg>
                <div>
                    {{ number_format($missingPropertyClassCount) }} item(s) have no property class and will export under Office equipment.
                </div>
            </div>
        @endif

        {{-- Toolbar: search + restock filter + export --}}
        <div class="owwa-toolbar owwa-stock-levels-toolbar">
            <div class="owwa-toolbar-left owwa-stock-levels-toolbar-left">
                <input
                    type="text"
                    wire:model.live.debounce.300ms="search"
                    placeholder="Search items or category…"
                    class="owwa-search-bar"
                />
                <div class="owwa-pa-view-tabs owwa-stock-restock-tabs" role="tablist" aria-label="Restock status filter">
                    <button
                        type="button"
                        role="tab"
                        wire:click="setRestockFilter('active')"
                        class="owwa-pa-view-tab {{ $restockFilter === 'active' ? 'is-active' : '' }}"
                        aria-selected="{{ $restockFilter === 'active' ? 'true' : 'false' }}"
                    >
                        Active only
                    </button>
                    <button
                        type="button"
                        role="tab"
                        wire:click="setRestockFilter('inactive')"
                        class="owwa-pa-view-tab {{ $restockFilter === 'inactive' ? 'is-active' : '' }}"
                        aria-selected="{{ $restockFilter === 'inactive' ? 'true' : 'false' }}"
                    >
                        Inactive only
                    </button>
                </div>
            </div>
            <div class="owwa-toolbar-right">
                {{ $this->buildExportDownloadsActionGroup() }}
            </div>
        </div>

        {{-- Data panel --}}
        <div class="owwa-data-panel">
            <div class="owwa-data-panel-body">
                <div class="owwa-table-wrap owwa-table-wrap--scroll owwa-stock-levels-table-wrap">
                    <table class="owwa-data-table owwa-stock-levels-table">
                        <thead>
                            <tr>
                                @php
                                    $columns = [
                                        'item_name' => 'Item',
                                        'category_name' => 'Category',
                                    ];
                                    $columns['unit_cost'] = 'Unit cost';
                                    if ($isSemiExpendable) {
                                        $columns['property_class'] = 'Property class';
                                        $columns['value_type'] = 'Value category';
                                    }
                                    $columns['restock_status'] = 'Restock';
                                    $columns['stock'] = 'Stock';
                                    if ($usesTaggedUnits) {
                                        $columns['tagged_units'] = 'Accountable tags';
                                    }
                                    $columns['reorder_level'] = 'Reorder';
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
                                <th class="owwa-stock-actions" style="width: 72px;">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($rows as $row)
                                <tr class="{{ ($row->is_inactive_for_restock ?? false) ? 'opacity-75' : '' }} {{ ($row->is_low || ($row->tagged_drift ?? false)) ? 'owwa-row-low' : '' }}">
                                    <td class="owwa-cell-primary">
                                        <button
                                            type="button"
                                            wire:click="openStockLedger({{ (int) $row->item_id }}, {{ (int) $row->office_id }}, {{ json_encode((float) ($row->unit_cost ?? 0)) }})"
                                            class="text-left font-medium text-primary-600 hover:text-primary-500 dark:text-primary-400 dark:hover:text-primary-300"
                                            title="{{ $row->item_name }}"
                                        >
                                            {{ $row->item_name }}
                                        </button>
                                    </td>
                                    <td class="owwa-cell-muted">{{ $row->category_name }}</td>
                                    <td class="owwa-num owwa-cell-muted">₱{{ number_format((float) ($row->unit_cost ?? 0), 2) }}</td>
                                    @if($isSemiExpendable)
                                        <td class="owwa-cell-muted">
                                            @if(filled($row->property_class))
                                                {{ $propertyClassOptions[$row->property_class] ?? $row->property_class }}
                                            @else
                                                <span class="text-warning-600 dark:text-warning-400">Not set</span>
                                            @endif
                                        </td>
                                        <td class="owwa-cell-muted">
                                            {{ SemiExpendableValueCategory::labelForValueType($row->value_type ?? 'low') }}
                                        </td>
                                    @endif
                                    <td class="owwa-cell-muted">
                                        @if($row->is_inactive_for_restock ?? false)
                                            <span class="owwa-status-badge owwa-status-low" title="Legacy cost — not restocked">Inactive</span>
                                        @else
                                            <span class="owwa-status-badge owwa-status-ok">Active</span>
                                        @endif
                                    </td>
                                    <td class="owwa-num {{ $row->is_low ? 'owwa-cell-danger' : 'owwa-cell-primary' }}">{{ number_format($row->stock) }}</td>
                                    @if($usesTaggedUnits)
                                        <td class="owwa-num {{ ($row->tagged_drift ?? false) ? 'owwa-cell-danger' : 'owwa-cell-primary' }}" title="Property tags accountable to this office (warehouse + issued in use). Stock column is warehouse quantity only.">
                                            {{ number_format($row->accountable_tags ?? $row->tagged_units ?? 0) }}
                                        </td>
                                    @endif
                                    <td class="owwa-num owwa-cell-muted">{{ number_format($row->reorder_level) }}</td>
                                    <td class="owwa-status">
                                        @if($row->is_low)
                                            <span class="owwa-status-badge owwa-status-low">Low</span>
                                        @else
                                            <span class="owwa-status-badge owwa-status-ok">OK</span>
                                        @endif
                                    </td>
                                    <td class="owwa-stock-actions">
                                        <x-filament::dropdown
                                            placement="bottom-end"
                                            shift
                                            width="xs"
                                            wire:key="stock-actions-{{ (int) $row->item_id }}-{{ (int) $row->office_id }}-{{ (float) ($row->unit_cost ?? 0) }}"
                                        >
                                            <x-slot name="trigger">
                                                <x-filament::icon-button
                                                    icon="heroicon-m-ellipsis-vertical"
                                                    color="gray"
                                                    label="Actions"
                                                />
                                            </x-slot>

                                            <x-filament::dropdown.list>
                                                @if($this->canCreateTransfer())
                                                    @if($row->stock > 0)
                                                        <x-filament::dropdown.list.item
                                                            tag="a"
                                                            :href="$this->getTransferPrefillUrl((int) $row->item_id, (int) $row->office_id, (float) ($row->unit_cost ?? 0))"
                                                            icon="heroicon-o-arrows-right-left"
                                                        >
                                                            Transfer
                                                        </x-filament::dropdown.list.item>
                                                    @else
                                                        <x-filament::dropdown.list.item
                                                            icon="heroicon-o-arrows-right-left"
                                                            disabled
                                                            tooltip="No stock available"
                                                        >
                                                            Transfer
                                                        </x-filament::dropdown.list.item>
                                                    @endif
                                                @endif

                                                @if($row->is_inactive_for_restock ?? false)
                                                    <x-filament::dropdown.list.item
                                                        icon="heroicon-o-arrow-path"
                                                        wire:click="toggleRestockActive({{ (int) $row->item_id }}, {{ (int) $row->office_id }}, {{ json_encode((float) ($row->unit_cost ?? 0)) }})"
                                                    >
                                                        Mark active
                                                    </x-filament::dropdown.list.item>
                                                @else
                                                    <x-filament::dropdown.list.item
                                                        icon="heroicon-o-archive-box"
                                                        wire:click="toggleRestockInactive({{ (int) $row->item_id }}, {{ (int) $row->office_id }}, {{ json_encode((float) ($row->unit_cost ?? 0)) }})"
                                                    >
                                                        Mark inactive
                                                    </x-filament::dropdown.list.item>
                                                @endif
                                            </x-filament::dropdown.list>
                                        </x-filament::dropdown>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="{{ ($isSemiExpendable ? 8 : 6) + ($usesTaggedUnits ? 1 : 0) + 1 }}">
                                        <div class="owwa-empty">
                                            <svg class="owwa-empty-icon" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M20.25 7.5l-.625 10.632a2.25 2.25 0 01-2.247 2.118H6.622a2.25 2.25 0 01-2.247-2.118L3.75 7.5M10 11.25h4M3.375 7.5h17.25c.621 0 1.125-.504 1.125-1.125v-1.5c0-.621-.504-1.125-1.125-1.125H3.375c-.621 0-1.125.504-1.125 1.125v1.5c0 .621.504 1.125 1.125 1.125z" />
                                            </svg>
                                            <p class="owwa-empty-title">No stock data</p>
                                            <p class="owwa-empty-desc">Add items and offices in Setup to track stock.</p>
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

    <x-filament-actions::modals />
</x-filament-panels::page>
