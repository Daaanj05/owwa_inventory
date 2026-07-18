@php
    /** @var \Illuminate\Support\Collection<int, \App\Models\Issuance> $lines */
    $lines = $lines ?? collect();
@endphp

<div class="owwa-issuance-batch-table-wrap">
    <table class="owwa-issuance-batch-table">
        <thead>
            <tr>
                <th scope="col">Stock / property no.</th>
                <th scope="col">Item</th>
                <th scope="col">Unit</th>
                <th scope="col" class="owwa-issuance-batch-num">Qty</th>
                <th scope="col" class="owwa-issuance-batch-num">Unit cost</th>
                <th scope="col" class="owwa-issuance-batch-num">Amount</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($lines as $line)
                @php
                    $amount = $line->amount
                        ?? ((float) ($line->unit_cost ?? 0) * (int) $line->quantity);
                @endphp
                <tr>
                    <td>{{ $line->property_number ?: ($line->item?->item_code ?? '—') }}</td>
                    <td>
                        <strong>{{ $line->item?->name ?? '—' }}</strong>
                        @if (filled($line->estimated_useful_life))
                            <span class="owwa-issuance-batch-meta">EUL: {{ $line->estimated_useful_life }}</span>
                        @endif
                    </td>
                    <td>{{ $line->item?->unit ?? '—' }}</td>
                    <td class="owwa-issuance-batch-num">{{ number_format((int) $line->quantity) }}</td>
                    <td class="owwa-issuance-batch-num">
                        {{ $line->unit_cost !== null ? '₱'.number_format((float) $line->unit_cost, 2) : '—' }}
                    </td>
                    <td class="owwa-issuance-batch-num">₱{{ number_format((float) $amount, 2) }}</td>
                </tr>
            @endforeach
        </tbody>
        @if ($lines->isNotEmpty())
            <tfoot>
                <tr>
                    <th colspan="3">Totals</th>
                    <th class="owwa-issuance-batch-num">{{ number_format((int) $lines->sum('quantity')) }}</th>
                    <th></th>
                    <th class="owwa-issuance-batch-num">
                        ₱{{ number_format((float) $lines->sum(
                            fn (\App\Models\Issuance $line): float => (float) (
                                $line->amount
                                ?? ((float) ($line->unit_cost ?? 0) * (int) $line->quantity)
                            )
                        ), 2) }}
                    </th>
                </tr>
            </tfoot>
        @endif
    </table>
</div>
