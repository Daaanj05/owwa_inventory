@php
    $rows = $this->getStockRows();
    $sort = $this->stockSort;
    $dir = $this->stockDir;
    $officeName = $this->getOfficeName();
    $total = $rows->count();
    $lowCount = $rows->where('is_low', true)->count();
@endphp

<x-filament-widgets::widget>
    <div class="owwa-data-panel">
        <div class="owwa-data-panel-header">
            <h2 class="owwa-data-panel-title">Stock levels</h2>
            <div style="display:flex;align-items:center;gap:0.5rem;flex-wrap:wrap;">
                <span class="owwa-status-badge owwa-status-ok">{{ $officeName }}</span>
                @if($lowCount > 0)
                    <span class="owwa-status-badge owwa-status-low">{{ $lowCount }} low</span>
                @endif
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
                                    'stock' => 'Stock',
                                    'reorder_level' => 'Reorder',
                                ];
                            @endphp
                            @foreach($columns as $col => $label)
                                <th
                                    wire:click="sortStock('{{ $col }}')"
                                    style="cursor:pointer;user-select:none;"
                                    class="{{ in_array($col, ['stock', 'reorder_level']) ? 'owwa-num' : '' }}"
                                >
                                    {{ $label }}
                                    @if($sort === $col)
                                        <span style="font-size:0.65rem;margin-left:0.25rem;">{{ $dir === 'asc' ? '▲' : '▼' }}</span>
                                    @endif
                                </th>
                            @endforeach
                            <th class="owwa-status">Status</th>
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
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5">
                                    <div class="owwa-empty">
                                        <svg class="owwa-empty-icon" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M20.25 7.5l-.625 10.632a2.25 2.25 0 01-2.247 2.118H6.622a2.25 2.25 0 01-2.247-2.118L3.75 7.5M10 11.25h4M3.375 7.5h17.25c.621 0 1.125-.504 1.125-1.125v-1.5c0-.621-.504-1.125-1.125-1.125H3.375c-.621 0-1.125.504-1.125 1.125v1.5c0 .621.504 1.125 1.125 1.125z" />
                                        </svg>
                                        <p class="owwa-empty-title">No stock data</p>
                                        <p class="owwa-empty-desc">No items are tracked for your assigned office yet.</p>
                                    </div>
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</x-filament-widgets::widget>
