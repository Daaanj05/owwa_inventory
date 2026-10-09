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
    </style>
</head>
<body>
    <h1>{{ $title }}</h1>
    <p class="meta">{{ $filterSummary }} · Generated {{ $generatedAt }}</p>
    <table>
        <thead>
            <tr>
                <th>Item</th>
                <th>Property / Ref</th>
                <th>Issued to</th>
                <th>EUL</th>
                <th>Expires</th>
                <th>Status</th>
                <th>Action</th>
            </tr>
        </thead>
        <tbody>
            @forelse($rows as $row)
                <tr>
                    <td>{{ $row->item_name }}</td>
                    <td>{{ $row->property_number ?? $row->reference_code ?? '—' }}</td>
                    <td>{{ $row->issued_to_name ?? '—' }}</td>
                    <td>{{ $row->estimated_useful_life ?? '—' }}</td>
                    <td>{{ $row->eul_expires_at ?? '—' }}</td>
                    <td>{{ $row->status_label }}</td>
                    <td>{{ $row->action_label ?? 'Awaiting review' }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="7">No semi-expendable units are due for replacement review.</td>
                </tr>
            @endforelse
        </tbody>
    </table>
</body>
</html>
