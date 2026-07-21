<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $asset->propertyNumber }} — OWWA Inventory</title>
    <style>
        :root {
            --owwa-navy: #1e3a8a;
            --owwa-navy-deep: #172554;
            --owwa-crimson: #7f1d1d;
            --owwa-text: #111827;
            --owwa-muted: #6b7280;
            --owwa-line: #e5e7eb;
            --owwa-soft: #f8fafc;
        }

        * { box-sizing: border-box; }

        body {
            margin: 0;
            font-family: "Segoe UI", system-ui, -apple-system, Roboto, sans-serif;
            background:
                radial-gradient(ellipse 80% 50% at 50% -10%, rgba(255, 255, 255, 0.12), transparent 55%),
                linear-gradient(160deg, var(--owwa-navy) 0%, #312e81 48%, var(--owwa-crimson) 100%);
            min-height: 100vh;
            color: var(--owwa-text);
            padding: 20px 16px 28px;
        }

        .wrap {
            max-width: 440px;
            margin: 0 auto;
        }

        .panel {
            background: #fff;
            border-radius: 16px;
            overflow: hidden;
            box-shadow:
                0 18px 40px rgba(15, 23, 42, 0.22),
                0 0 0 1px rgba(255, 255, 255, 0.08);
        }

        .letterhead {
            padding: 18px 20px 14px;
            text-align: center;
            background:
                linear-gradient(180deg, #ffffff 0%, #f8fafc 100%);
            border-bottom: 3px solid var(--owwa-navy);
        }

        .logos {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 18px;
            margin-bottom: 12px;
        }

        .logos img {
            width: 56px;
            height: 56px;
            object-fit: contain;
            display: block;
        }

        .letterhead .line1 {
            margin: 0;
            font-size: 12px;
            color: #374151;
            letter-spacing: 0.01em;
        }

        .letterhead .line2 {
            margin: 5px 0 0;
            font-size: 13px;
            font-weight: 800;
            letter-spacing: 0.03em;
            color: var(--owwa-navy-deep);
            line-height: 1.3;
        }

        .letterhead .office {
            margin: 8px 0 0;
            display: inline-block;
            font-size: 11px;
            font-weight: 700;
            color: var(--owwa-navy);
            background: #eff6ff;
            border: 1px solid #dbeafe;
            border-radius: 999px;
            padding: 3px 10px;
        }

        .letterhead .address {
            margin: 8px 0 0;
            font-size: 11px;
            color: var(--owwa-muted);
            line-height: 1.4;
        }

        .hero {
            padding: 14px 20px 6px;
            background: var(--owwa-soft);
            border-bottom: 1px solid var(--owwa-line);
        }

        .hero-label {
            margin: 0;
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.04em;
            color: var(--owwa-muted);
        }

        .hero-value {
            margin: 4px 0 0;
            font-size: 15px;
            font-weight: 800;
            color: var(--owwa-navy-deep);
            word-break: break-word;
            line-height: 1.35;
        }

        .hero-article {
            margin: 6px 0 0;
            font-size: 13px;
            font-weight: 600;
            color: #1f2937;
        }

        .card {
            padding: 4px 20px 18px;
        }

        dl { margin: 0; }

        .row {
            display: grid;
            grid-template-columns: 1fr;
            gap: 2px;
            padding: 11px 0;
            border-bottom: 1px solid #f3f4f6;
            font-size: 14px;
        }

        .row:last-child { border-bottom: none; }

        @media (min-width: 420px) {
            .row {
                grid-template-columns: minmax(0, 42%) minmax(0, 58%);
                gap: 12px;
                align-items: start;
            }

            dd { text-align: right; }
        }

        dt {
            margin: 0;
            color: var(--owwa-muted);
            font-size: 12px;
            font-weight: 600;
        }

        dd {
            margin: 0;
            font-weight: 700;
            color: var(--owwa-text);
            word-break: break-word;
            line-height: 1.35;
        }

        dd.is-empty {
            color: #9ca3af;
            font-weight: 500;
        }

        .badge {
            margin: 0 20px 16px;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            font-size: 11px;
            font-weight: 600;
            color: #166534;
            background: #ecfdf5;
            border: 1px solid #bbf7d0;
            border-radius: 999px;
            padding: 5px 10px;
        }

        .badge::before {
            content: '';
            width: 7px;
            height: 7px;
            border-radius: 50%;
            background: #22c55e;
            flex-shrink: 0;
        }

        .footer {
            margin-top: 16px;
            text-align: center;
            color: rgba(255, 255, 255, 0.78);
            font-size: 11px;
            line-height: 1.4;
        }
    </style>
</head>
<body>
    @php
        $display = static function (?string $value): array {
            $trimmed = trim((string) $value);

            if ($trimmed === '' || $trimmed === '—') {
                return ['text' => '—', 'empty' => true];
            }

            return ['text' => $trimmed, 'empty' => false];
        };

        $spTag = $display($asset->spTagNo);
        $unitSection = $display($asset->unitSection);
        $description = $display($asset->description);
        $endUser = $display($asset->endUser);
        $cost = $display($asset->acquisitionCost);
        $dateAcquired = $display($asset->dateAcquiredFormatted);
    @endphp

    <div class="wrap">
        <div class="panel">
            <div class="letterhead">
                <div class="logos" aria-hidden="true">
                    <img
                        src="{{ asset('images/bagong-pilipinas-form-logo.png') }}"
                        alt=""
                        width="56"
                        height="56"
                    >
                    <img
                        src="{{ asset('images/owwa-form-logo.png') }}"
                        alt=""
                        width="56"
                        height="56"
                    >
                </div>
                <p class="line1">{{ $asset->agencyLine1 }}</p>
                <p class="line2">{{ $asset->agencyLine2 }}</p>
                <p class="office">Regional Office IV-A</p>
                <p class="address">{{ $asset->agencyAddress }}</p>
            </div>

            <div class="hero">
                <p class="hero-label">{{ $asset->propertyNumberLabel }}</p>
                <p class="hero-value">{{ $asset->propertyNumber }}</p>
                <p class="hero-article">{{ $asset->article }}</p>
            </div>

            <div class="card">
                <dl>
                    <div class="row">
                        <dt>SP Tag No.</dt>
                        <dd @class(['is-empty' => $spTag['empty']])>{{ $spTag['text'] }}</dd>
                    </div>
                    <div class="row">
                        <dt>Unit/Section</dt>
                        <dd @class(['is-empty' => $unitSection['empty']])>{{ $unitSection['text'] }}</dd>
                    </div>
                    <div class="row">
                        <dt>{{ $asset->propertyNameLabel }}</dt>
                        <dd>{{ $asset->article }}</dd>
                    </div>
                    <div class="row">
                        <dt>Description</dt>
                        <dd @class(['is-empty' => $description['empty']])>{{ $description['text'] }}</dd>
                    </div>
                    <div class="row">
                        <dt>End-user</dt>
                        <dd @class(['is-empty' => $endUser['empty']])>{{ $endUser['text'] }}</dd>
                    </div>
                    <div class="row">
                        <dt>Acquisition Cost</dt>
                        <dd @class(['is-empty' => $cost['empty']])>{{ $cost['text'] }}</dd>
                    </div>
                    <div class="row">
                        <dt>Date Acquired</dt>
                        <dd @class(['is-empty' => $dateAcquired['empty']])>{{ $dateAcquired['text'] }}</dd>
                    </div>
                </dl>
            </div>

            <div class="badge">Verified asset record · read-only</div>
        </div>

        <p class="footer">Read-only asset information. No login required to view this page.</p>
    </div>
</body>
</html>
