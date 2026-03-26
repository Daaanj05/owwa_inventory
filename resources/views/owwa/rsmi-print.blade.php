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
        .signature-block { margin-top: 1.5rem; display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; }
        .signature-block .name { border-bottom: 1px solid #333; padding: 4px 0; min-height: 1.5em; }
        .signature-block .label { font-size: 0.85em; color: #555; margin-top: 2px; }
        .recap { margin-top: 1rem; }
        @media print { body { margin: 0; } }
    </style>
</head>
<body>
    @php
        $user = auth()->user();
        $isSupplyCustodian = $user?->isSupplyCustodian() ?? false;
    @endphp

    <h1>REPORT OF SUPPLIES AND MATERIALS ISSUED</h1>
    <p style="text-align: center; font-size: 0.9em;">Appendix 64 - RSMI</p>

    <dl class="header-grid">
        <div>
            <dt>Entity Name</dt>
            <dd>{{ $issuance->office?->name ?? '—' }}</dd>
        </div>
        <div>
            <dt>Serial No.</dt>
            <dd>{{ $issuance->reference_code ?? '—' }}</dd>
        </div>
        <div>
            <dt>Fund Cluster</dt>
            <dd>{{ $issuance->office?->fund_cluster ?? '—' }}</dd>
        </div>
        <div>
            <dt>Date</dt>
            <dd>{{ $issuance->issuance_date?->format('Y-m-d') ?? '—' }}</dd>
        </div>
    </dl>

    <table>
        <thead>
            <tr>
                <th>RIS No.</th>
                <th>Responsibility Center Code</th>
                <th>Stock No.</th>
                <th>Item</th>
                <th>Unit</th>
                <th>Quantity Issued</th>
                <th>Unit Cost</th>
                <th>Amount</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td>{{ $issuance->reference_code }}</td>
                <td>{{ $issuance->office?->code ?? $issuance->department?->code ?? $issuance->department?->name ?? '—' }}</td>
                <td>{{ $issuance->item?->item_code ?? '—' }}</td>
                <td>{{ $issuance->item?->name }}</td>
                <td>{{ $issuance->item?->unit ?? '—' }}</td>
                <td>{{ $issuance->quantity }}</td>
                <td>{{ $isSupplyCustodian ? '—' : ($issuance->unit_cost !== null ? number_format($issuance->unit_cost, 2) : '—') }}</td>
                <td>{{ $isSupplyCustodian ? '—' : ($issuance->amount !== null ? number_format($issuance->amount, 2) : '—') }}</td>
            </tr>
        </tbody>
    </table>

    <section class="recap">
        <strong>Recapitulation:</strong>
        <table>
            <thead>
                <tr>
                    <th>Stock No.</th>
                    <th>Quantity</th>
                    <th>Unit Cost</th>
                    <th>Total Cost</th>
                    <th>UACS Object Code</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td>{{ $issuance->item?->item_code ?? '—' }}</td>
                    <td>{{ $issuance->quantity }}</td>
                    <td>{{ $isSupplyCustodian ? '—' : ($issuance->unit_cost !== null ? number_format($issuance->unit_cost, 2) : '—') }}</td>
                    <td>{{ $isSupplyCustodian ? '—' : ($issuance->amount !== null ? number_format($issuance->amount, 2) : '—') }}</td>
                    <td>—</td>
                </tr>
            </tbody>
        </table>
    </section>

    <p><strong>Posted by:</strong></p>
    <p>I hereby certify to the correctness of the above information.</p>

    <div class="signature-block">
        <div>
            <div class="name">{{ $issuance->custodian_printed_name ?? '_____________________________' }}</div>
            <div class="label">Signature over Printed Name of Supply and/or Property Custodian</div>
        </div>
        <div>
            <div class="name">
                {{ $isSupplyCustodian ? '_____________________________' : ($issuance->accounting_staff_printed_name ?? '_____________________________') }}
            </div>
            <div class="label">Signature over Printed Name of Designated Accounting Staff</div>
        </div>
    </div>
    <p style="margin-top: 0.5rem; font-size: 0.9em;">(Sign physically on this form when printing.)</p>

</body>
</html>
