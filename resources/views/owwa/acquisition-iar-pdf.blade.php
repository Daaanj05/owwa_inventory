<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Inspection and Acceptance Report</title>
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 11px; color: #111; }
        h1 { text-align: center; font-size: 14px; margin: 0 0 4px; }
        .sub { text-align: center; margin-bottom: 12px; }
        table { width: 100%; border-collapse: collapse; margin-top: 8px; }
        th, td { border: 1px solid #333; padding: 5px 6px; vertical-align: top; }
        th { background: #eee; }
        .meta td { border: none; padding: 2px 0; }
        .sigs { margin-top: 28px; width: 100%; }
        .sigs td { border: none; text-align: center; padding-top: 24px; }
        .line { border-top: 1px solid #333; margin: 0 20px; }
    </style>
</head>
<body>
    <h1>INSPECTION AND ACCEPTANCE REPORT</h1>
    <div class="sub">Appendix 62</div>
    <table class="meta">
        <tr>
            <td><strong>Supplier:</strong> {{ $purchaseOrder?->supplier_name ?: '—' }}</td>
            <td><strong>IAR No.:</strong> {{ $iar->number ?? '—' }}</td>
        </tr>
        <tr>
            <td><strong>PO No./Date:</strong> {{ trim(($purchaseOrder?->number ?? '').' / '.(optional($purchaseOrder?->po_date)->format('Y-m-d') ?? ''), ' /') }}</td>
            <td><strong>Date:</strong> {{ optional($iar->iar_date)->format('Y-m-d') ?? '—' }}</td>
        </tr>
        <tr>
            <td><strong>Invoice No.:</strong> {{ $iar->invoice_number }}</td>
            <td><strong>Invoice Date:</strong> {{ optional($iar->invoice_date)->format('Y-m-d') }}</td>
        </tr>
        <tr>
            <td><strong>Date Inspected:</strong> {{ optional($iar->date_inspected)->format('Y-m-d') }}</td>
            <td><strong>Date Received:</strong> {{ optional($iar->date_received)->format('Y-m-d') }}</td>
        </tr>
    </table>

    <table>
        <thead>
            <tr>
                <th>Stock No.</th>
                <th>Description</th>
                <th>Unit</th>
                <th>PR Qty</th>
                <th>PO Qty</th>
                <th>IAR Qty</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($lines as $line)
                <tr>
                    <td>{{ $line->stockNumber() }}</td>
                    <td>{{ $line->description }}</td>
                    <td>{{ $line->unit }}</td>
                    <td>{{ $line->pr_quantity }}</td>
                    <td>{{ $line->po_quantity }}</td>
                    <td>{{ $line->iar_quantity }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <table class="sigs">
        <tr>
            <td>
                <div class="line"></div>
                {{ $iar->inspection_officer_name ?: '________________' }}<br>Inspection Officer
            </td>
            <td>
                <div class="line"></div>
                {{ $iar->custodian_name ?: '________________' }}<br>Supply and/or Property Custodian
            </td>
        </tr>
    </table>
</body>
</html>
