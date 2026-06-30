<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>{{ $title }} - OWWA IV-A</title>
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 10px; }
        h1 { font-size: 14px; }
        table { width: 100%; border-collapse: collapse; margin-top: 10px; }
        th, td { border: 1px solid #333; padding: 4px; text-align: left; }
        th { background: #eee; }
        .meta { margin-bottom: 15px; color: #666; }
    </style>
</head>
<body>
    <h1>{{ $title }}</h1>
    <p class="meta">OWWA Regional Office IV-A – Generated: {{ $generated_at }}</p>

    <table>
        <thead>
            <tr>
                <th>Reference Code</th>
                <th>Item</th>
                <th>From</th>
                <th>To</th>
                <th>Quantity</th>
                <th>Date</th>
                <th>Recorded By</th>
            </tr>
        </thead>
        <tbody>
            @foreach($transfers as $t)
            <tr>
                <td>{{ $t->reference_code }}</td>
                <td>{{ $t->item?->name }}</td>
                <td>{{ $t->fromOffice?->name }}</td>
                <td>{{ $t->toOffice?->name }}</td>
                <td>{{ $t->quantity }}</td>
                <td>{{ $t->transfer_date?->format('Y-m-d') }}</td>
                <td>{{ $t->recordedBy?->name }}</td>
            </tr>
            @endforeach
        </tbody>
    </table>
</body>
</html>

