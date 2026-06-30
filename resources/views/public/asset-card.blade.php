<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $asset->propertyNumber }} — OWWA Inventory</title>
    <style>
        * { box-sizing: border-box; }
        body {
            margin: 0;
            font-family: system-ui, -apple-system, Segoe UI, Roboto, sans-serif;
            background: linear-gradient(160deg, #1e3a8a 0%, #7f1d1d 100%);
            min-height: 100vh;
            color: #111827;
            padding: 16px;
        }
        .wrap { max-width: 420px; margin: 0 auto; }
        .brand {
            color: rgba(255, 255, 255, 0.95);
            font-size: 13px;
            font-weight: 600;
            letter-spacing: 0.02em;
            margin-bottom: 12px;
        }
        .card {
            background: #fff;
            border-radius: 12px;
            padding: 20px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.18);
        }
        h1 {
            margin: 0 0 4px;
            font-size: 15px;
            font-weight: 700;
            word-break: break-word;
        }
        .subtitle {
            margin: 0 0 16px;
            font-size: 13px;
            color: #6b7280;
        }
        dl { margin: 0; }
        .row {
            display: flex;
            justify-content: space-between;
            gap: 12px;
            padding: 10px 0;
            border-bottom: 1px solid #f3f4f6;
            font-size: 14px;
        }
        .row:last-child { border-bottom: none; }
        dt { margin: 0; color: #6b7280; flex-shrink: 0; }
        dd {
            margin: 0;
            font-weight: 600;
            text-align: right;
            word-break: break-word;
        }
        .status {
            display: inline-block;
            padding: 2px 8px;
            border-radius: 999px;
            background: #eff6ff;
            color: #1d4ed8;
            font-size: 12px;
            font-weight: 600;
        }
        .admin-banner {
            margin-top: 12px;
            padding: 12px 14px;
            background: rgba(255, 255, 255, 0.12);
            border-radius: 10px;
        }
        .admin-banner a {
            color: #fff;
            font-size: 14px;
            font-weight: 600;
            text-decoration: none;
        }
        .admin-banner a:hover { text-decoration: underline; }
        .footer {
            margin-top: 16px;
            text-align: center;
            color: rgba(255, 255, 255, 0.75);
            font-size: 11px;
        }
    </style>
</head>
<body>
    <div class="wrap">
        <div class="brand">OWWA Inventory — Asset tag</div>

        <div class="card">
            <h1>{{ $asset->itemName }}</h1>
            <p class="subtitle">{{ $asset->propertyNumber }}</p>

            <dl>
                <div class="row">
                    <dt>Category</dt>
                    <dd>{{ $asset->categoryName }}</dd>
                </div>
                <div class="row">
                    <dt>Office</dt>
                    <dd>{{ $asset->officeName }}</dd>
                </div>
                <div class="row">
                    <dt>Status</dt>
                    <dd><span class="status">{{ $asset->statusLabel }}</span></dd>
                </div>
                @if ($asset->unitCostFormatted !== null)
                    <div class="row">
                        <dt>Unit cost</dt>
                        <dd>{{ $asset->unitCostFormatted }}</dd>
                    </div>
                @endif
            </dl>
        </div>

        @if ($asset->adminUrl !== null)
            <div class="admin-banner">
                <a href="{{ $asset->adminUrl }}">Open in admin</a>
            </div>
        @endif

        <p class="footer">Read-only asset information. No login required to view this page.</p>
    </div>
</body>
</html>
