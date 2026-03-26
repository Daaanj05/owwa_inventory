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
        .header-grid dd { margin: 0; }
        table { width: 100%; border-collapse: collapse; margin: 1rem 0; }
        th, td { border: 1px solid #333; padding: 6px 8px; text-align: left; }
        th { background: #eee; font-weight: 600; }
        .signature-block { margin-top: 1rem; display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; }
        .signature-block .name { border-bottom: 1px solid #333; padding: 4px 0; min-height: 1.5em; }
        .signature-block .label { font-size: 0.85em; color: #555; margin-top: 2px; }
        .sales { margin-top: 1rem; }
        @media print { body { margin: 0; } }
    </style>
</head>
<body>
    <h1>WASTE MATERIALS REPORT</h1>
    <p style="text-align: center; font-size: 0.9em;">Appendix 65 - WMR</p>

    <dl class="header-grid">
        <div>
            <dt>Entity Name</dt>
            <dd>{{ $disposal->office?->name ?? '—' }}</dd>
        </div>
        <div>
            <dt>Fund Cluster</dt>
            <dd>{{ $disposal->office?->fund_cluster ?? '—' }}</dd>
        </div>
        <div>
            <dt>Place of Storage</dt>
            <dd>{{ $disposal->office?->name ?? '—' }}</dd>
        </div>
        <div>
            <dt>Date</dt>
            <dd>{{ $disposal->disposal_date?->format('Y-m-d') ?? '—' }}</dd>
        </div>
    </dl>

    <p><strong>ITEMS FOR DISPOSAL</strong></p>
    <table>
        <thead>
            <tr>
                <th>No.</th>
                <th>Quantity</th>
                <th>Unit</th>
                <th>Description</th>
                <th colspan="3">Record of Sales</th>
            </tr>
            <tr>
                <th></th><th></th><th></th><th></th>
                <th>Official Receipt No.</th>
                <th>Date</th>
                <th>Amount</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td>1</td>
                <td>{{ $disposal->quantity }}</td>
                <td>{{ $disposal->item?->unit ?? '—' }}</td>
                <td>{{ $disposal->item?->name }}{{ $disposal->reason ? ' – ' . $disposal->reason : '' }}</td>
                <td>{{ $disposal->official_receipt_no ?? '—' }}</td>
                <td>{{ $disposal->sale_date?->format('Y-m-d') ?? '—' }}</td>
                <td>{{ $disposal->sale_amount !== null ? number_format($disposal->sale_amount, 2) : '—' }}</td>
            </tr>
        </tbody>
    </table>

    <p><strong>Certified Correct:</strong> _______________________ &nbsp; <strong>Disposal Approved:</strong> _______________________</p>

    <div class="signature-block">
        <div>
            <div class="name">{{ $disposal->custodian_printed_name ?? '_____________________________' }}</div>
            <div class="label">Signature over Printed Name of Supply and/or Property Custodian</div>
        </div>
        <div>
            <div class="name">{{ $disposal->approved_by_printed_name ?? '_____________________________' }}</div>
            <div class="label">Signature over Printed Name of Head of Agency/Entity or Authorized Representative</div>
        </div>
    </div>

    <p style="margin-top: 1.5rem;"><strong>CERTIFICATE OF INSPECTION</strong></p>
    <p style="font-size: 0.9em;">I hereby certify that the property enumerated above was disposed of in accordance with existing regulations.</p>
    <div class="signature-block" style="margin-top: 1rem;">
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
