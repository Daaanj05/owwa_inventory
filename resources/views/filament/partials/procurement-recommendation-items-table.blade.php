@php
    $rows = $rows ?? collect();
@endphp

@if($rows->isNotEmpty())
    <div class="owwa-pa-summary-table-wrap">
        <table class="owwa-pa-summary-table">
            <thead>
                <tr>
                    <th>Priority</th>
                    <th>Category</th>
                    <th>Item</th>
                    <th>Stock</th>
                    <th>Suggested</th>
                    <th>Cover</th>
                </tr>
            </thead>
            <tbody>
                @foreach($rows as $row)
                    <tr>
                        <td>
                            @if($row->priority === 'High')
                                <span class="owwa-status-badge owwa-status-low">High</span>
                            @else
                                <span class="owwa-status-badge owwa-status-medium">Medium</span>
                            @endif
                        </td>
                        <td>{{ $row->item?->category?->name ?? '—' }}</td>
                        <td>{{ $row->item_name }}</td>
                        <td>{{ number_format($row->current_stock) }}</td>
                        <td>
                            @if($row->suggested_qty_min !== null)
                                {{ number_format($row->suggested_qty_min) }}
                            @else
                                —
                            @endif
                        </td>
                        <td>
                            @if($row->months_cover !== null)
                                {{ number_format($row->months_cover, 1) }} mo
                            @else
                                —
                            @endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
@endif
