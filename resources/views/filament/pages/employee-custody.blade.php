@php
    use App\Services\EmployeeDistributionInventoryService;
    use App\Support\SemiExpendableUsefulLife;

    $rows = $this->getInventoryRows();
    $sortBy = $this->sortBy;
    $sortDir = $this->sortDir;
    $categoryOptions = EmployeeDistributionInventoryService::categoryOptions();
    $employeeOptions = $this->getEmployeeOptions();
    $propertyView = $this->usesPropertyIssuanceView();
    $hasEmployee = filled($this->employee);
    $custodyTab = $this->custodyTab;
@endphp

<x-filament-panels::page>
    <div class="owwa-inventory-layout">
        <div class="owwa-search-wrap" style="display:flex;gap:0.75rem;flex-wrap:wrap;align-items:center;">
            <select wire:model.live="employee" class="owwa-search-bar" style="max-width:16rem;" aria-label="Employee" required>
                <option value="">Select employee…</option>
                @foreach ($employeeOptions as $value => $label)
                    <option value="{{ $value }}">{{ $label }}</option>
                @endforeach
            </select>
            <select wire:model.live="category" class="owwa-search-bar" style="max-width:14rem;" aria-label="Item category" @disabled(! $hasEmployee)>
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
                @disabled(! $hasEmployee)
            />
        </div>

        <div class="owwa-search-wrap owwa-employee-custody-period-row" style="display:flex;gap:0.75rem;flex-wrap:wrap;align-items:center;justify-content:space-between;margin-top:0.75rem;">
            <div style="display:flex;gap:0.75rem;flex-wrap:wrap;align-items:center;">
                <label class="owwa-pa-filter-label" for="custody-from-date">From</label>
                <input
                    id="custody-from-date"
                    type="date"
                    wire:model.live="fromDate"
                    class="owwa-search-bar"
                    style="max-width:11rem;"
                    aria-label="From date"
                    @disabled(! $hasEmployee)
                />
                <label class="owwa-pa-filter-label" for="custody-to-date">To</label>
                <input
                    id="custody-to-date"
                    type="date"
                    wire:model.live="toDate"
                    class="owwa-search-bar"
                    style="max-width:11rem;"
                    aria-label="To date"
                    @disabled(! $hasEmployee)
                />
            </div>
            @if ($hasEmployee)
                <button
                    type="button"
                    wire:click="exportAllItems"
                    class="owwa-pa-export-btn"
                    style="display:inline-flex;align-items:center;gap:0.4rem;"
                >
                    <x-filament::icon icon="heroicon-o-document-arrow-down" class="h-4 w-4" />
                    Export All Item
                </button>
            @endif
        </div>

        @if ($hasEmployee && $propertyView)
            <div class="owwa-search-wrap" style="display:flex;gap:0.5rem;flex-wrap:wrap;margin-top:0.75rem;">
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

        <div class="owwa-data-panel">
            <div class="owwa-data-panel-body">
                @if (! $hasEmployee)
                    <div class="owwa-empty" style="padding: 2rem 1rem;">
                        <p class="owwa-empty-title">Select an employee</p>
                        <p class="owwa-empty-desc">Choose an employee to view their distributed and issued items.</p>
                    </div>
                @else
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
                                                <p class="owwa-empty-title">No items on record</p>
                                                <p class="owwa-empty-desc">Distributions to this employee will appear here.</p>
                                            </div>
                                        </td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                @endif
            </div>
        </div>

        @if ($hasEmployee)
            <div class="mt-4">
                {{ $rows->links('vendor.pagination.owwa') }}
            </div>
        @endif
    </div>
</x-filament-panels::page>
