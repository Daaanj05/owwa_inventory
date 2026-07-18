@php
    /** @var array<int, array<string, mixed>> $itemRows */
    /** @var bool $showReceipts */
    /** @var bool $forceShowCosts */
    /** @var \App\Models\AcquisitionPaperwork|null $paperwork */
    $itemRows = $itemRows ?? [];
    $showReceipts = (bool) ($showReceipts ?? false);
    $forceShowCosts = (bool) ($forceShowCosts ?? false);
    $includeCosts = $forceShowCosts || ($paperwork?->isPrApproved() ?? false);
    $baseColumns = $includeCosts ? 5 : 3;
@endphp

<div class="owwa-acquisition-items-table-wrap">
    <table class="owwa-data-table owwa-acquisition-items-table">
        <thead>
            <tr>
                <th>Stock No.</th>
                <th>Description</th>
                <th class="owwa-num">Qty</th>
                @if ($includeCosts)
                    <th class="owwa-num">Unit cost</th>
                    <th class="owwa-num">Amount</th>
                @endif
                @if ($showReceipts)
                    <th>Receipt</th>
                    <th>Date</th>
                @endif
            </tr>
        </thead>
        <tbody>
            @forelse ($itemRows as $row)
                <tr>
                    <td class="owwa-cell-muted">{{ $row['stock_no'] !== '' ? $row['stock_no'] : '—' }}</td>
                    <td class="owwa-cell-primary">{{ $row['description'] }}</td>
                    <td class="owwa-num">{{ number_format((int) $row['quantity']) }}</td>
                    @if ($includeCosts)
                        <td class="owwa-num">₱{{ number_format((float) $row['unit_cost'], 2) }}</td>
                        <td class="owwa-num">₱{{ number_format((float) $row['amount'], 2) }}</td>
                    @endif
                    @if ($showReceipts)
                        <td>{{ $row['receipt_ref'] ?: '—' }}</td>
                        <td class="owwa-cell-muted">{{ $row['receipt_date'] ?: '—' }}</td>
                    @endif
                </tr>
            @empty
                <tr>
                    <td colspan="{{ $baseColumns + ($showReceipts ? 2 : 0) }}" class="owwa-cell-muted">No line items.</td>
                </tr>
            @endforelse
        </tbody>
    </table>
</div>
