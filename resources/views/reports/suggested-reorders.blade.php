<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>{{ $title }}</title>
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 10px; color: #111; }
        h1 { font-size: 16px; margin: 0 0 4px; }
        .meta { margin: 0 0 12px; color: #444; }
        table { width: 100%; border-collapse: collapse; }
        th, td { border: 1px solid #333; padding: 4px 5px; text-align: left; }
        th { background: #eee; }
        .num { text-align: right; }
    </style>
</head>
<body>
    <h1>{{ $title }}</h1>
    <p class="meta">{{ $filterSummary }} · Generated {{ $generatedAt }}</p>
    <table>
        <thead>
            <tr>
                <th>Priority</th>
                <th>Item</th>
                <th>Office</th>
                <th class="num">Stock</th>
                <th class="num">Reorder</th>
                <th class="num">Forecast/mo</th>
                <th class="num">Cover</th>
                <th>Stockout</th>
                <th class="num">Unit cost</th>
                <th class="num">Suggested</th>
            </tr>
        </thead>
        <tbody>
            @forelse($rows as $row)
                <tr>
                    <td>{{ $row->priority }}</td>
                    <td>{{ $row->item_name }}</td>
                    <td>{{ $row->office_name }}</td>
                    <td class="num">{{ number_format((int) $row->current_stock) }}</td>
                    <td class="num">{{ number_format((int) $row->reorder_level) }}</td>
                    <td class="num">{{ ($row->has_recent_usage ?? true) ? number_format((float) $row->forecast_monthly_usage, 1) : '—' }}</td>
                    <td class="num">{{ $row->months_cover !== null ? number_format((float) $row->months_cover, 1) : '—' }}</td>
                    <td>{{ $row->projected_stockout_date ?? '—' }}</td>
                    <td class="num">{{ $row->latest_unit_cost !== null ? number_format((float) $row->latest_unit_cost, 2) : '—' }}</td>
                    <td class="num">{{ $row->suggested_reorder_qty !== null ? number_format((int) $row->suggested_reorder_qty) : '—' }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="10">No consumables to reorder in this filter.</td>
                </tr>
            @endforelse
        </tbody>
    </table>
</body>
</html>
