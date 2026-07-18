<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $item->name }} — Stock</title>
    <style>
        body { font-family: system-ui, sans-serif; margin: 2rem; color: #111; }
        h1 { font-size: 1.25rem; margin-bottom: 0.25rem; }
        .meta { color: #555; margin-bottom: 1rem; }
        .balance { font-size: 1.5rem; font-weight: 600; }
    </style>
</head>
<body>
    <h1>{{ $item->name }}</h1>
    <p class="meta">Stock No. {{ $item->item_code }} · {{ $office->name }}</p>
    <p class="balance">Book balance: {{ $balance }} {{ $item->unit }}</p>
    <p class="meta">Scan this label during RPCI and enter the counted quantity.</p>
</body>
</html>
