<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>AI Procurement Recommendation – {{ $run->ran_at?->format('M d, Y g:i A') }}</title>
    <style>
        * { box-sizing: border-box; }
        body {
            font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
            font-size: 12px;
            color: #0f172a;
            margin: 0;
            padding: 24px;
            background: #f3f4f6;
        }
        .sheet {
            max-width: 900px;
            margin: 0 auto;
            background: #ffffff;
            padding: 24px 28px;
            border-radius: 12px;
            box-shadow: 0 10px 30px rgba(15,23,42,0.08);
        }
        h1 {
            font-size: 18px;
            margin: 0 0 4px;
        }
        .muted {
            color: #6b7280;
            font-size: 11px;
        }
        .status-badge {
            display: inline-flex;
            align-items: center;
            padding: 2px 8px;
            border-radius: 999px;
            font-size: 10px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: .06em;
            margin-left: 8px;
        }
        .status-draft { background: #e5e7eb; color: #4b5563; }
        .status-for_approval { background: #fef3c7; color: #92400e; }
        .status-approved { background: #dcfce7; color: #166534; }
        .status-archived { background: #e5e7eb; color: #4b5563; }
        .section-title {
            font-size: 12px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .08em;
            margin: 20px 0 8px;
            color: #6b7280;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 6px;
        }
        th, td {
            padding: 6px 8px;
            border: 1px solid #e5e7eb;
            text-align: left;
        }
        th {
            background: #f9fafb;
            font-size: 11px;
        }
        td {
            font-size: 11px;
        }
        .text-right { text-align: right; }
        .signature-blocks {
            margin-top: 32px;
            display: flex;
            gap: 24px;
        }
        .signature {
            flex: 1;
            border-top: 1px solid #d1d5db;
            padding-top: 8px;
            font-size: 11px;
        }
        @media print {
            body { background: #ffffff; padding: 0; }
            .sheet {
                box-shadow: none;
                border-radius: 0;
                max-width: none;
                margin: 0;
            }
        }
    </style>
</head>
<body>
<div class="sheet">
    <header>
        <h1>AI Procurement Recommendation</h1>
        <div class="muted">
            Generated {{ $run->ran_at?->format('M d, Y g:i A') ?? '—' }}
            <span class="status-badge status-{{ $run->status }}">
                {{ strtoupper(str_replace('_', ' ', $run->status)) }}
            </span>
        </div>
        @if($run->summary)
            <p style="margin-top:12px; font-size:11px; line-height:1.5;">
                {{ $run->summary }}
            </p>
        @endif
    </header>

    <div class="section-title">Urgent / priority items (AI)</div>

    @if($items->isEmpty())
        <p class="muted">No at-risk items were identified in this run.</p>
    @else
        <table>
            <thead>
            <tr>
                <th style="width:70px;">Priority</th>
                <th>Item</th>
                <th style="width:150px;">Department / Office</th>
                <th class="text-right" style="width:80px;">Current</th>
                <th class="text-right" style="width:80px;">Avg/month</th>
                <th class="text-right" style="width:90px;">Months cover</th>
                <th class="text-right" style="width:100px;">Suggested</th>
                <th>Reason</th>
            </tr>
            </thead>
            <tbody>
            @foreach($items as $item)
                <tr>
                    <td>{{ $item->priority ?? '—' }}</td>
                    <td>{{ $item->item_name }}</td>
                    <td>{{ $item->office_name ?? '—' }}</td>
                    <td class="text-right">{{ $item->current_stock ?? '—' }}</td>
                    <td class="text-right">
                        {{ $item->avg_monthly_usage !== null ? number_format($item->avg_monthly_usage, 2) : '—' }}
                    </td>
                    <td class="text-right">
                        {{ $item->months_cover !== null ? number_format($item->months_cover, 2) : '—' }}
                    </td>
                    <td class="text-right">
                        @if($item->suggested_qty_min !== null && $item->suggested_qty_max !== null)
                            @if($item->suggested_qty_min === $item->suggested_qty_max)
                                {{ $item->suggested_qty_min }}
                            @else
                                {{ $item->suggested_qty_min }}–{{ $item->suggested_qty_max }}
                            @endif
                        @else
                            —
                        @endif
                    </td>
                    <td>{{ $item->reason }}</td>
                </tr>
            @endforeach
            </tbody>
        </table>
    @endif

    <div class="section-title">Approval</div>
    <div class="signature-blocks">
        <div class="signature">
            Prepared by:<br>
            Name / Signature / Date
        </div>
        <div class="signature">
            Recommended by:<br>
            Name / Signature / Date
        </div>
        <div class="signature">
            Approved by:<br>
            Name / Signature / Date
        </div>
    </div>
</div>
</body>
</html>

