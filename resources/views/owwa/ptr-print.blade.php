<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title }}</title>
    <style>
        body { font-family: system-ui, sans-serif; font-size: 12px; max-width: 900px; margin: 1rem auto; padding: 0 1rem; }
        h1 { font-size: 1.1rem; text-align: center; margin-bottom: 1rem; }
        .header-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 0.5rem 2rem; margin-bottom: 1rem; }
        .header-grid dt { font-weight: 600; }
        table { width: 100%; border-collapse: collapse; margin: 1rem 0; }
        th, td { border: 1px solid #333; padding: 6px 8px; text-align: left; }
        th { background: #eee; }
        .signature-block { margin-top: 1.5rem; display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 1rem; }
        .signature-block .name { border-bottom: 1px solid #333; padding: 4px 0; min-height: 1.5em; }
        .signature-block .label { font-size: 0.85em; color: #555; margin-top: 2px; }
        @media print { body { margin: 0; } }
    </style>
</head>
<body>
    <h1>PROPERTY TRANSFER REPORT</h1>
    <p style="text-align: center; font-size: 0.9em;">Appendix 76 - PTR</p>

    <dl class="header-grid">
        <div>
            <dt>Entity Name</dt>
            <dd>{{ $transfer->fromOffice?->name ?? $transfer->toOffice?->name ?? '—' }}</dd>
        </div>
        <div>
            <dt>Fund Cluster</dt>
            <dd>{{ $transfer->fromOffice?->fund_cluster ?? $transfer->toOffice?->fund_cluster ?? '—' }}</dd>
        </div>
        <div>
            <dt>From (Accountable Officer/Agency)</dt>
            <dd>{{ $transfer->fromOffice?->name ?? '—' }}</dd>
        </div>
        <div>
            <dt>PTR No.</dt>
            <dd>{{ $transfer->reference_code ?? '—' }}</dd>
        </div>
        <div>
            <dt>To (Accountable Officer/Agency)</dt>
            <dd>{{ $transfer->toOffice?->name ?? '—' }}</dd>
        </div>
        <div>
            <dt>Date</dt>
            <dd>{{ $transfer->transfer_date?->format('Y-m-d') ?? '—' }}</dd>
        </div>
    </dl>

    <table>
        <thead>
            <tr>
                <th>Date Acquired</th>
                <th>Property No.</th>
                <th>Description</th>
                <th>Amount</th>
                <th>Condition</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td>{{ $dateAcquired ?? '—' }}</td>
                <td>{{ $transfer->property_number ?? $transfer->item?->item_code ?? '—' }}</td>
                <td>{{ $transfer->item?->name }}</td>
                <td>{{ $acquisitionCost !== null ? number_format($acquisitionCost, 2) : '—' }}</td>
                <td>{{ $transfer->condition ?? '—' }}</td>
            </tr>
        </tbody>
    </table>

    <p><strong>Approved by:</strong> _______________________ &nbsp; <strong>Released/Issued by:</strong> _______________________ &nbsp; <strong>Received by:</strong> _______________________</p>
    <div class="signature-block">
        <div>
            <div class="name">{{ $transfer->approved_by_printed_name ?? $transfer->fromOffice?->name ?? '_____________________________' }}</div>
            <div class="label">Approved by</div>
        </div>
        <div>
            <div class="name">{{ $transfer->released_by_printed_name ?? $transfer->recordedBy?->name ?? '_____________________________' }}</div>
            <div class="label">Released/Issued by</div>
        </div>
        <div>
            <div class="name">{{ $transfer->received_by_printed_name ?? $transfer->toOffice?->name ?? '_____________________________' }}</div>
            <div class="label">Received by</div>
        </div>
    </div>
    <p style="margin-top: 0.5rem; font-size: 0.9em;">(Sign physically on this form when printing.)</p>
</body>
</html>
