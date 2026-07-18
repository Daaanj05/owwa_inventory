<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Purchase Order</title>
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 11px; color: #111; }
        h1 { text-align: center; font-size: 14px; margin: 0 0 4px; }
        .sub { text-align: center; margin-bottom: 12px; }
        table { width: 100%; border-collapse: collapse; margin-top: 8px; }
        th, td { border: 1px solid #333; padding: 5px 6px; vertical-align: top; }
        th { background: #eee; }
        .meta td { border: none; padding: 2px 0; }
        .page-break { page-break-before: always; }
        .remarks-box { min-height: 180px; border: 1px solid #333; padding: 10px; margin-top: 12px; white-space: pre-wrap; }
        .conforme { width: 100%; margin-top: 28px; background: #efefef; border-collapse: collapse; }
        .conforme td { border: 1px solid #bbb; padding: 14px 10px; height: 120px; vertical-align: top; }
        .conforme .label { font-weight: bold; background: #fff; display: inline-block; padding: 2px 6px; }
        .sig-line { border-top: 1px solid #333; margin-top: 48px; text-align: center; font-size: 10px; padding-top: 4px; }
        .right { text-align: right; }
        .total-row td { font-weight: bold; }
    </style>
</head>
<body>
    <h1>PURCHASE ORDER</h1>
    <div class="sub">Appendix 61</div>
    <table class="meta">
        <tr>
            <td><strong>Supplier:</strong> {{ $purchaseOrder->supplier_name }}</td>
            <td><strong>PO No.:</strong> {{ $purchaseOrder->number ?? '—' }}</td>
        </tr>
        <tr>
            <td><strong>Address:</strong> {{ $purchaseOrder->supplier_address }}</td>
            <td><strong>Date:</strong> {{ optional($purchaseOrder->po_date)->format('Y-m-d') }}</td>
        </tr>
        <tr>
            <td><strong>TIN:</strong> {{ $purchaseOrder->supplier_tin ?: '—' }}</td>
            <td><strong>Mode of Procurement:</strong> {{ $purchaseOrder->mode_of_procurement }}</td>
        </tr>
        <tr>
            <td><strong>Place of Delivery:</strong> {{ $purchaseOrder->place_of_delivery }}</td>
            <td><strong>Delivery Term:</strong> {{ $purchaseOrder->delivery_term ?: '—' }}</td>
        </tr>
        <tr>
            <td><strong>Date of Delivery:</strong> {{ optional($purchaseOrder->date_of_delivery)->format('Y-m-d') ?: '—' }}</td>
            <td><strong>Payment Term:</strong> {{ $purchaseOrder->payment_term ?: '—' }}</td>
        </tr>
        <tr>
            <td colspan="2"><strong>PR No.:</strong> {{ $paperwork?->pr_number ?: '—' }}</td>
        </tr>
    </table>

    <table>
        <thead>
            <tr>
                <th>Stock No.</th>
                <th>Unit</th>
                <th>Description</th>
                <th>Qty</th>
                <th>Unit Cost</th>
                <th>Amount</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($lines as $line)
                <tr>
                    <td>{{ $line->stockNumber() }}</td>
                    <td>{{ $line->unit }}</td>
                    <td>{{ $line->description }}</td>
                    <td>{{ $line->po_quantity }}</td>
                    <td class="right">{{ $line->unit_cost !== null ? number_format((float) $line->unit_cost, 2) : '—' }}</td>
                    <td class="right">{{ $line->amount !== null ? number_format((float) $line->amount, 2) : '—' }}</td>
                </tr>
            @endforeach
            <tr><td colspan="6">&nbsp;</td></tr>
            <tr class="total-row">
                <td colspan="5">{{ $totalAmountInWords }}</td>
                <td class="right">{{ number_format((float) $totalAmount, 2) }}</td>
            </tr>
        </tbody>
    </table>

    <div class="page-break">
        <h1>Technical Specification</h1>
        <div class="remarks-box">{{ $technicalSpecifications }}</div>

        <table class="conforme">
            <tr>
                <td style="width:50%;">
                    <span class="label">Conforme:</span>
                    <div class="sig-line">Signature over Printed Name of Supplier</div>
                    <div class="sig-line">Date</div>
                </td>
                <td style="width:50%;">
                    <div>Very truly yours,</div>
                    <div class="sig-line">Signature over Printed Name of Authorized Official</div>
                    <div class="sig-line">Designation</div>
                </td>
            </tr>
        </table>
    </div>
</body>
</html>
