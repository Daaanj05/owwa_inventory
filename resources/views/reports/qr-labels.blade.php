<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>{{ $title }}</title>
    <style>
        @page { margin: 8mm; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 9px; margin: 0; padding: 4px; }
        h2 { margin: 0 0 8px; font-size: 11px; }
        .label-grid { width: 100%; border-collapse: separate; border-spacing: 6px 4px; table-layout: fixed; }
        .label-cell { width: 50%; vertical-align: top; page-break-inside: avoid; }
        .label {
            padding: 6px;
            border: 1px solid #333;
            text-align: center;
        }
        .label img { width: 88px; height: 88px; }
        .property-number { font-size: 10px; font-weight: bold; margin-top: 4px; }
        .item-name { margin-top: 2px; font-size: 9px; }
        .office-name { color: #444; margin-top: 2px; font-size: 8px; }
    </style>
</head>
<body>
    <h2>{{ $title }}</h2>
    <table class="label-grid">
        @foreach (array_chunk($labels instanceof \Illuminate\Support\Collection ? $labels->all() : $labels, 2) as $row)
            <tr>
                @foreach ($row as $label)
                    <td class="label-cell">
                        <div class="label">
                            <img src="{{ $label['qr_data_uri'] }}" alt="QR {{ $label['property_number'] }}">
                            <div class="property-number">{{ $label['property_number'] }}</div>
                            <div class="item-name">{{ $label['item_name'] }}</div>
                            @if ($label['office_name'] !== '')
                                <div class="office-name">{{ $label['office_name'] }}</div>
                            @endif
                        </div>
                    </td>
                @endforeach
                @if (count($row) === 1)
                    <td class="label-cell"></td>
                @endif
            </tr>
        @endforeach
    </table>
</body>
</html>
