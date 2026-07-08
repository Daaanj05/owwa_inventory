@php
    use App\Services\EmployeeDistributionInventoryService;

    $rows = $this->getInventoryRows();
    $sort = $this->invSort;
    $dir = $this->invDir;
    $invCategory = $this->invCategory;
    $categoryOptions = EmployeeDistributionInventoryService::categoryOptions();
@endphp

<x-filament-widgets::widget>
    <div class="owwa-data-panel">
        <div class="owwa-data-panel-header">
            <h2 class="owwa-data-panel-title">Distributed inventory</h2>
            <div style="display:flex;align-items:center;gap:0.5rem;flex-wrap:wrap;">
                <select wire:model.live="invCategory" class="owwa-search-bar" style="max-width:14rem;" aria-label="Item category">
                    @foreach ($categoryOptions as $value => $label)
                        <option value="{{ $value }}">{{ $label }}</option>
                    @endforeach
                </select>
                <input
                    type="text"
                    wire:model.live.debounce.300ms="invSearch"
                    placeholder="Search items…"
                    class="owwa-search-bar"
                    style="width:14rem;"
                />
            </div>
        </div>
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
                                    wire:click="sortInventory('{{ $col }}')"
                                    style="cursor:pointer;user-select:none;"
                                    class="{{ in_array($col, ['quantity', 'distribution_count'], true) ? 'owwa-num' : '' }}"
                                >
                                    {{ $label }}
                                    @if($sort === $col)
                                        <span style="font-size:0.65rem;margin-left:0.25rem;">{{ $dir === 'asc' ? '▲' : '▼' }}</span>
                                    @endif
                                </th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($rows as $row)
                            <tr>
                                <td class="owwa-cell-primary">
                                    <a
                                        href="{{ $this->ledgerUrl((int) $row->item_id) }}"
                                        class="font-medium text-primary-600 hover:text-primary-500 dark:text-primary-400 dark:hover:text-primary-300"
                                        title="{{ $row->item_name }}"
                                    >
                                        {{ $row->item_name }}
                                    </a>
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
            @if($rows->hasPages())
                <div class="owwa-data-panel-footer">
                    {{ $rows->links('vendor.pagination.owwa') }}
                </div>
            @endif
        </div>
    </div>
</x-filament-widgets::widget>
