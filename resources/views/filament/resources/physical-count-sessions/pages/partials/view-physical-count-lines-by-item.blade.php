@php
    /** @var array<int, array{item_id: int, item_name: string, tag_count: int, balance_per_card: int, on_hand_count: int, variance: int, property_numbers: array<int, string>}> $rows */
@endphp

<div class="owwa-pc-lines-by-item-table-wrap">
    <div class="owwa-table-wrap">
        <table class="owwa-data-table">
            <thead>
                <tr>
                    <th>Item</th>
                    <th class="owwa-num">Tags</th>
                    <th class="owwa-num">Per card (book)</th>
                    <th class="owwa-num">On hand</th>
                    <th class="owwa-num">Shortage / overage</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($rows as $row)
                    @php
                        $variance = (int) $row['variance'];
                        $varianceClass = match (true) {
                            $variance < 0 => 'owwa-cell-danger',
                            $variance > 0 => 'owwa-cell-warning',
                            default => 'owwa-cell-primary',
                        };
                    @endphp
                    <tr @class(['owwa-row-low' => $variance < 0])>
                        <td class="owwa-cell-primary">
                            {{ $row['item_name'] }}
                            @if (count($row['property_numbers']) > 0)
                                <span class="owwa-pc-lines-property-hint">
                                    {{ implode(', ', $row['property_numbers']) }}
                                </span>
                            @endif
                        </td>
                        <td class="owwa-num owwa-cell-muted">{{ number_format($row['tag_count']) }}</td>
                        <td class="owwa-num owwa-cell-muted">{{ number_format($row['balance_per_card']) }}</td>
                        <td class="owwa-num owwa-cell-primary">{{ number_format($row['on_hand_count']) }}</td>
                        <td class="owwa-num {{ $varianceClass }}">{{ number_format($variance) }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="5">
                            <div class="owwa-empty">
                                <p class="owwa-empty-title">No count lines yet</p>
                                <p class="owwa-empty-desc">Load expected assets or scan property tags to begin.</p>
                            </div>
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
