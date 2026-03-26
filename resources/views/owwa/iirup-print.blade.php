<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title }}</title>
    <style>
        body { font-family: system-ui, sans-serif; font-size: 12px; max-width: 960px; margin: 1rem auto; padding: 0 1rem; }
        h1 { font-size: 1rem; text-align: center; margin-bottom: 1rem; }
        .header-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 0.5rem 2rem; margin-bottom: 1rem; }
        .header-grid dt { font-weight: 600; }
        table { width: 100%; border-collapse: collapse; margin: 1rem 0; font-size: 0.9rem; }
        th, td { border: 1px solid #333; padding: 4px 6px; text-align: left; }
        th { background: #eee; }
        .signature-block { margin-top: 1.5rem; display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; }
        .signature-block .name { border-bottom: 1px solid #333; padding: 4px 0; min-height: 1.5em; }
        .signature-block .label { font-size: 0.85em; color: #555; margin-top: 2px; }
        @media print { body { margin: 0; } }
    </style>
</head>
<body>
    <h1>INVENTORY AND INSPECTION REPORT OF UNSERVICEABLE PROPERTY</h1>
    <p style="text-align: center; font-size: 0.9em;">Appendix 74 - IIRUP / Annex A.10 - IIRUSP</p>

    <dl class="header-grid">
        <div>
            <dt>Entity Name</dt>
            <dd>{{ $disposal->office?->name ?? '—' }}</dd>
        </div>
        <div>
            <dt>Fund Cluster</dt>
            <dd>{{ $disposal->office?->fund_cluster ?? '—' }}</dd>
        </div>
    </dl>

    <p><strong>ITEMS FOR DISPOSAL (Unserviceable)</strong></p>
    <table>
        <thead>
            <tr>
                <th>Date Acquired</th>
                <th>Particulars / Articles</th>
                <th>Property No.</th>
                <th>Qty</th>
                <th>Remarks</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td>{{ $dateAcquired ?? '—' }}</td>
                <td>{{ $disposal->item?->name }}</td>
                <td>{{ $disposal->property_number ?? $disposal->item?->item_code ?? '—' }}</td>
                <td>{{ $disposal->quantity }}</td>
                <td>{{ $disposal->reason ?? '—' }}</td>
            </tr>
        </tbody>
    </table>

    <p><strong>Requested by:</strong> _______________________ &nbsp; <strong>Approved by:</strong> _______________________</p>
    <div class="signature-block">
        <div>
            <div class="name">{{ $disposal->custodian_printed_name ?? '_____________________________' }}</div>
            <div class="label">Signature over Printed Name of Accountable Officer</div>
        </div>
        <div>
            <div class="name">{{ $disposal->approved_by_printed_name ?? '_____________________________' }}</div>
            <div class="label">Signature over Printed Name of Authorized Official</div>
        </div>
        <div>
            <div class="name">{{ $disposal->inspection_officer_printed_name ?? '_____________________________' }}</div>
            <div class="label">Signature over Printed Name of Inspection Officer</div>
        </div>
        <div>
            <div class="name">{{ $disposal->witness_printed_name ?? '_____________________________' }}</div>
            <div class="label">Signature over Printed Name of Witness</div>
        </div>
    </div>
    <p style="margin-top: 0.5rem; font-size: 0.9em;">(Sign physically on this form when printing.)</p>
</body>
</html>
