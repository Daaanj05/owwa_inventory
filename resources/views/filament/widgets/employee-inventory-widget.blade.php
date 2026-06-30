@php
    $rows = $this->getInventoryRows();
    $sort = $this->invSort;
    $dir = $this->invDir;
@endphp

<x-filament-widgets::widget>
    <div class="owwa-data-panel">
        <div class="owwa-data-panel-header">
            <h2 class="owwa-data-panel-title">Distributed inventory</h2>
            <div style="display:flex;align-items:center;gap:0.5rem;">
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
                                    'quantity' => 'Qty',
                                    'distribution_date' => 'Date',
                                    'distributed_by_name' => 'Distributed by',
                                ];
                            @endphp
                            @foreach($columns as $col => $label)
                                <th
                                    wire:click="sortInventory('{{ $col }}')"
                                    style="cursor:pointer;user-select:none;"
                                    class="{{ $col === 'quantity' ? 'owwa-num' : '' }}"
                                >
                                    {{ $label }}
                                    @if($sort === $col)
                                        <span style="font-size:0.65rem;margin-left:0.25rem;">{{ $dir === 'asc' ? '▲' : '▼' }}</span>
                                    @endif
                                </th>
                            @endforeach
                            <th>Remarks</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($rows as $row)
                            <tr>
                                <td class="owwa-cell-primary">{{ $row->item_name }}</td>
                                <td class="owwa-cell-muted">{{ $row->category_name }}</td>
                                <td class="owwa-num owwa-cell-primary">{{ number_format($row->quantity) }}</td>
                                <td class="owwa-cell-muted">{{ $row->distribution_date?->format('M d, Y') ?? '—' }}</td>
                                <td class="owwa-cell-muted">{{ $row->distributed_by_name }}</td>
                                <td class="owwa-cell-muted">{{ $row->remarks ?? '—' }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6">
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
