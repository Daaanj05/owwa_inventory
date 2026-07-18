<div class="owwa-issuance-batch-table-wrap">
    @php
        $visibility = \App\Support\IssuanceDistributionVisibility::forIssuance($record);
    @endphp
    <p class="owwa-issuance-batch-meta" style="margin-bottom: 0.75rem;">
        Status: <strong>{{ $visibility['distribution_status_label'] }}</strong>
        · Issued qty {{ number_format($visibility['issued_quantity']) }}
        · Distributed qty {{ number_format($visibility['distributed_quantity']) }}
    </p>
    @if ($visibility['employees'] === [])
        <p class="owwa-issuance-batch-meta">No employee distributions yet. Stock is still with the Unit Consolidator.</p>
    @else
        <table class="owwa-issuance-batch-table">
            <thead>
                <tr>
                    <th scope="col">Employee</th>
                    <th scope="col" class="owwa-issuance-batch-num">Qty</th>
                    <th scope="col">Distributed</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($visibility['employees'] as $employee)
                    <tr>
                        <td>{{ $employee['name'] }}</td>
                        <td class="owwa-issuance-batch-num">{{ number_format($employee['quantity']) }}</td>
                        <td>{{ $employee['date'] ?? '—' }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif
</div>
