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
        .signature-block { margin-top: 1.5rem; display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; }
        .signature-block .name { border-bottom: 1px solid #333; padding: 4px 0; min-height: 1.5em; }
        .signature-block .label { font-size: 0.85em; color: #555; margin-top: 2px; }
        @media print { body { margin: 0; } }
    </style>
</head>
<body>
    <h1>INVENTORY CUSTODIAN SLIP</h1>
    <p style="text-align: center; font-size: 0.9em;">Appendix 59 - ICS</p>

    <dl class="header-grid">
        <div>
            <dt>Entity Name</dt>
            <dd>{{ $issuance->office?->name ?? '—' }}</dd>
        </div>
        <div>
            <dt>Fund Cluster</dt>
            <dd>{{ $issuance->office?->fund_cluster ?? '—' }}</dd>
        </div>
        <div>
            <dt>ICS No.</dt>
            <dd>{{ $issuance->reference_code ?? '—' }}</dd>
        </div>
    </dl>

    <table>
        <thead>
            <tr>
                <th>Quantity</th>
                <th>Unit</th>
                <th>Unit Cost</th>
                <th>Total Cost</th>
                <th>Description</th>
                <th>Inventory Item No.</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td>{{ $issuance->quantity }}</td>
                <td>{{ $issuance->item?->unit ?? '—' }}</td>
                <td>{{ $issuance->unit_cost ?? '—' }}</td>
                <td>{{ $issuance->amount ?? '—' }}</td>
                <td>{{ $issuance->item?->name }}</td>
                <td>{{ $issuance->property_number ?? $issuance->item?->item_code ?? '—' }}</td>
            </tr>
        </tbody>
    </table>

    <p><strong>Received from:</strong> _______________________ &nbsp; <strong>Received by:</strong> _______________________</p>
    <div class="signature-block">
        <div>
            <div class="name">{{ $issuance->issuedTo?->name ?? '_____________________________' }}</div>
            <div class="label">Signature over Printed Name</div>
        </div>
        <div>
            <div class="name">{{ $issuance->custodian_printed_name ?? $issuance->issuedBy?->name ?? '_____________________________' }}</div>
            <div class="label">Signature over Printed Name</div>
        </div>
    </div>
    <p style="margin-top: 0.5rem; font-size: 0.9em;">(Sign physically on this form when printing.)</p>
</body>
</html>
