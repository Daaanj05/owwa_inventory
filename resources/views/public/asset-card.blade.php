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
            --owwa-line: #eef2f7;
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
            padding: 22px 20px 18px;
            box-shadow:
                0 18px 40px rgba(15, 23, 42, 0.22),
                0 0 0 1px rgba(255, 255, 255, 0.08);
        }

        .letterhead {
            text-align: center;
            margin-bottom: 18px;
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
            font-size: 12px;
            font-weight: 700;
            color: var(--owwa-navy);
        }

        .letterhead .address {
            margin: 6px 0 0;
            font-size: 11px;
            color: var(--owwa-muted);
            line-height: 1.4;
        }

        dl {
            margin: 0;
        }

        .row {
            display: grid;
            grid-template-columns: 1fr;
            gap: 2px;
            padding: 11px 0;
            border-top: 1px solid var(--owwa-line);
            font-size: 14px;
        }

        .row.is-primary dd {
            color: var(--owwa-navy-deep);
            font-weight: 800;
        }

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

        .note {
            margin: 14px 0 0;
            text-align: center;
            font-size: 11px;
            font-weight: 600;
            color: #64748b;
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

            <dl>
                <div class="row is-primary">
                    <dt>{{ $asset->propertyNumberLabel }}</dt>
                    <dd>{{ $asset->propertyNumber }}</dd>
                </div>
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

            <p class="note">Verified asset record · read-only</p>
        </div>

        <p class="footer">Read-only asset information. No login required to view this page.</p>
    </div>
</body>
</html>
