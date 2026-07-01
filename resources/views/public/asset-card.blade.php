<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $asset->propertyNumber }} — OWWA Property Tag</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Caveat:wght@500;600&display=swap" rel="stylesheet">
    <style>
        * { box-sizing: border-box; }
        body {
            margin: 0;
            font-family: system-ui, -apple-system, Segoe UI, Roboto, sans-serif;
            background: #e8edf5;
            min-height: 100vh;
            color: #111827;
            padding: 16px;
        }
        .wrap { max-width: 420px; margin: 0 auto; }
        .sticker {
            background: #fff;
            border: 2px solid #1e3a8a;
            border-radius: 4px;
            overflow: hidden;
            box-shadow: 0 8px 24px rgba(30, 58, 138, 0.12);
        }
        .sticker-header {
            background: linear-gradient(180deg, #1e40af 0%, #1e3a8a 100%);
            color: #fff;
            text-align: center;
            padding: 14px 12px 12px;
        }
        .sticker-header .republic {
            margin: 0;
            font-size: 10px;
            font-weight: 600;
            letter-spacing: 0.06em;
            text-transform: uppercase;
        }
        .sticker-header .agency {
            margin: 4px 0 0;
            font-size: 13px;
            font-weight: 700;
            letter-spacing: 0.02em;
            line-height: 1.25;
        }
        .sticker-header .address {
            margin: 6px 0 0;
            font-size: 10px;
            line-height: 1.35;
            opacity: 0.92;
        }
        .sticker-body { padding: 0; }
        .sticker-row {
            display: grid;
            grid-template-columns: 9.5rem 1fr;
            gap: 8px;
            align-items: baseline;
            padding: 9px 14px;
            border-bottom: 1px solid #d1d5db;
            font-size: 12px;
            line-height: 1.35;
        }
        .sticker-row:last-child { border-bottom: none; }
        .sticker-label {
            margin: 0;
            font-weight: 600;
            color: #1e3a8a;
        }
        .sticker-value {
            margin: 0;
            font-family: 'Caveat', 'Segoe Script', 'Bradley Hand', cursive;
            font-size: 17px;
            font-weight: 600;
            color: #111827;
            word-break: break-word;
        }
        .footer {
            margin-top: 14px;
            text-align: center;
            color: #6b7280;
            font-size: 11px;
            line-height: 1.4;
        }
    </style>
</head>
<body>
    <div class="wrap">
        <div class="sticker">
            <div class="sticker-header">
                <p class="republic">Republic of the Philippines</p>
                <p class="agency">OVERSEAS WORKERS WELFARE ADMINISTRATION</p>
                <p class="address">OWWA Building, 7th St. cor. F.B. Harrison St.,<br>1300 Pasay City, Philippines</p>
            </div>

            <div class="sticker-body">
                <div class="sticker-row">
                    <p class="sticker-label">Semi-Expendable Property No.</p>
                    <p class="sticker-value">{{ $asset->propertyNumber }}</p>
                </div>
                <div class="sticker-row">
                    <p class="sticker-label">Semi-Expendable Property</p>
                    <p class="sticker-value">{{ $asset->article }}</p>
                </div>
                <div class="sticker-row">
                    <p class="sticker-label">Description</p>
                    <p class="sticker-value">{{ $asset->description }}</p>
                </div>
                <div class="sticker-row">
                    <p class="sticker-label">Unit / Section</p>
                    <p class="sticker-value">{{ $asset->unitSection }}</p>
                </div>
                <div class="sticker-row">
                    <p class="sticker-label">Stock No.</p>
                    <p class="sticker-value">{{ $asset->stockNumber }}</p>
                </div>
                @if ($asset->endUser !== null)
                    <div class="sticker-row">
                        <p class="sticker-label">End-user</p>
                        <p class="sticker-value">{{ $asset->endUser }}</p>
                    </div>
                @endif
                <div class="sticker-row">
                    <p class="sticker-label">Acquisition Cost</p>
                    <p class="sticker-value">{{ $asset->acquisitionCostFormatted ?? '—' }}</p>
                </div>
                <div class="sticker-row">
                    <p class="sticker-label">Date Acquired</p>
                    <p class="sticker-value">{{ $asset->dateAcquiredFormatted ?? '—' }}</p>
                </div>
            </div>
        </div>

        <p class="footer">Read-only property tag information. No login required to view this page.</p>
    </div>
</body>
</html>
