@php
    $rows = $this->getTopProductRows();
@endphp

<x-filament-widgets::widget>
    <div class="owwa-data-panel owwa-dashboard-data-panel">
        <div class="owwa-data-panel-header">
            <h2 class="owwa-data-panel-title">Top 5 acquired product</h2>
        </div>
        <div class="owwa-data-panel-body">
            <div class="owwa-table-wrap">
                <table class="owwa-data-table">
                    <thead>
                        <tr>
                            <th>Stock No.</th>
                            <th>Item</th>
                            <th>Category</th>
                            <th class="owwa-num">Qty.</th>
                            <th class="owwa-num">Unit cost</th>
                            <th class="owwa-num">Total</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($rows as $row)
                            <tr>
                                <td class="owwa-cell-muted">{{ filled($row->item_code) ? $row->item_code : '—' }}</td>
                                <td class="owwa-cell-primary">{{ $row->item_name }}</td>
                                <td class="owwa-cell-muted">{{ $row->category_name }}</td>
                                <td class="owwa-num owwa-cell-primary">{{ number_format($row->total_quantity) }}</td>
                                <td class="owwa-num owwa-cell-muted">₱{{ number_format($row->avg_unit_cost, 2) }}</td>
                                <td class="owwa-num owwa-cell-primary">₱{{ number_format($row->total_amount, 2) }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6">
                                    <div class="owwa-empty">
                                        <p class="owwa-empty-title">No acquired products yet</p>
                                        <p class="owwa-empty-desc">Top items by acquired quantity will appear after acquisitions are received.</p>
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
