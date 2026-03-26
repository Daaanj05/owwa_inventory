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
                <th>Item</th>
                <th>Category</th>
                <th>Office</th>
                <th>Current Stock</th>
                <th>Reorder Point</th>
            </tr>
        </thead>
        <tbody>
            @foreach($rows as $row)
            <tr>
                <td>{{ $row['item_name'] }}</td>
                <td>{{ $row['category'] }}</td>
                <td>{{ $row['office'] }}</td>
                <td>{{ $row['stock'] }}</td>
                <td>{{ $row['reorder_level'] }}</td>
            </tr>
            @endforeach
        </tbody>
    </table>
</body>
</html>
