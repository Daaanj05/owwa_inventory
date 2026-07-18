<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Purchase Request</title>
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 11px; color: #111; }
        h1 { text-align: center; font-size: 14px; margin: 0 0 4px; }
        .sub { text-align: center; margin-bottom: 12px; }
        table { width: 100%; border-collapse: collapse; margin-top: 10px; }
        th, td { border: 1px solid #333; padding: 5px 6px; vertical-align: top; }
        th { background: #eee; }
        .meta { width: 100%; margin-bottom: 8px; }
        .meta td { border: none; padding: 2px 0; }
        .sigs { margin-top: 28px; width: 100%; }
        .sigs td { border: none; text-align: center; padding-top: 24px; }
        .line { border-top: 1px solid #333; margin: 0 20px; }
    </style>
</head>
<body>
    <h1>PURCHASE REQUEST</h1>
    <div class="sub">Appendix 60</div>
    <table class="meta">
        <tr>
            <td><strong>Entity Name:</strong> {{ $officeName ?? '—' }}</td>
            <td><strong>PR No.:</strong> {{ $paperwork->pr_number ?? '—' }}</td>
        </tr>
        <tr>
            <td><strong>Office/Section:</strong> {{ $officeName ?? '—' }}</td>
            <td><strong>Date:</strong> {{ optional($paperwork->pr_date)->format('Y-m-d') ?? '—' }}</td>
        </tr>
    </table>
    <table>
        <thead>
            <tr>
                <th>Stock No.</th>
                <th>Unit</th>
                <th>Description</th>
                <th>Qty</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($lines as $line)
                <tr>
                    <td>{{ $line->stockNumber() }}</td>
                    <td>{{ $line->unit ?? $line->item?->unit }}</td>
                    <td>{{ $line->description ?? $line->item?->name }}</td>
                    <td>{{ $line->quantity }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
    <p><strong>Purpose:</strong> {{ $paperwork->purpose }}</p>
    <table class="sigs">
        <tr>
            <td>
                <div class="line"></div>
                {{ $paperwork->requested_by_name ?: '________________' }}<br>Requested by
            </td>
            <td>
                <div class="line"></div>
                {{ $paperwork->approved_by_name ?: '________________' }}<br>Approved by
            </td>
        </tr>
    </table>
</body>
</html>
