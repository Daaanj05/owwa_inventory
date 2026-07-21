<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>{{ $title }}</title>
    <style>
        @page { margin: 8mm; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 9px; margin: 0; padding: 4px; color: #111; }
        h2 { margin: 0 0 8px; font-size: 11px; }
        .label-grid { width: 100%; border-collapse: separate; border-spacing: 6px 6px; table-layout: fixed; }
        .label-cell { width: 50%; vertical-align: top; page-break-inside: avoid; }
        .label {
            padding: 10px 8px 8px;
            border: 1px solid #222;
            text-align: center;
            min-height: 150px;
        }
        .letterhead { text-align: center; margin-bottom: 6px; line-height: 1.25; }
        .letterhead .line-1 { font-size: 8px; }
        .letterhead .line-2 { font-size: 8.5px; font-weight: bold; margin-top: 3px; }
        .letterhead .line-3 { font-size: 7px; margin-top: 3px; }
        .qr-wrap { text-align: center; margin: 6px 0; }
        .qr-wrap img { width: 80px; height: 80px; }
        .property-number { font-weight: bold; font-size: 10px; margin-top: 4px; word-break: break-all; }
        .item-name { font-size: 8.5px; margin-top: 3px; color: #333; }
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
                            <div class="letterhead">
                                <div class="line-1">{{ $label['agency_line_1'] ?? 'Republic of the Philippines' }}</div>
                                <div class="line-2">{{ $label['agency_line_2'] ?? 'OVERSEAS WORKERS WELFARE ADMINISTRATION' }}</div>
                                <div class="line-3">{{ $label['agency_address'] ?? 'G/F Parian Commerce Center II, National Highway, Brgy. Parian, Calamba, Laguna' }}</div>
                            </div>
                            <div class="qr-wrap">
                                <img src="{{ $label['qr_data_uri'] }}" alt="QR {{ $label['property_number'] }}">
                            </div>
                            <div class="property-number">{{ $label['property_number'] }}</div>
                            <div class="item-name">{{ $label['item_name'] }}</div>
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
