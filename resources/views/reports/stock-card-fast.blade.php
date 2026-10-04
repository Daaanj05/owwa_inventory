{{-- Appendix 58 Stock Card lookalike (Fast DomPDF). Matches official Excel Appendix 58 - SC.xlsx A4 portrait print. --}}
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Stock Card</title>
    <style>
        @page {
            size: A4 portrait;
            margin: 14mm 12mm 12mm 12mm;
        }
        * {
            box-sizing: border-box;
        }
        body {
            font-family: "Times New Roman", Times, serif;
            font-size: 12px;
            color: #000;
            margin: 0;
            padding: 0;
        }
        .card {
            page-break-after: always;
            width: 100%;
        }
        .card:last-child {
            page-break-after: auto;
        }
        .generated-on {
            width: 100%;
            text-align: right;
            font-size: 10px;
            font-family: "Times New Roman", Times, serif;
            margin: 6px 0 0 0;
            color: #000;
        }
        .brand-header-wrap {
            width: 100%;
            text-align: center;
            margin: 0 0 14px 0;
        }
        .brand-header {
            width: auto;
            border-collapse: collapse;
            margin: 0 auto;
        }
        .brand-header td {
            vertical-align: bottom;
            padding: 0;
            border: none;
        }
        .brand-header .brand-logo {
            width: auto;
            white-space: nowrap;
        }
        .brand-header .brand-logo--left {
            text-align: right;
            padding-right: 12px;
        }
        .brand-header .brand-logo--right {
            text-align: left;
            padding-left: 12px;
        }
        .brand-header .brand-logo img {
            height: 2.33cm;
            width: auto;
            display: inline-block;
            vertical-align: bottom;
        }
        .brand-header .brand-title-block {
            width: auto;
            text-align: center;
            padding: 0 6px;
            font-family: "Times New Roman", Times, serif;
        }
        .brand-header .agency-line {
            font-size: 14px;
            font-weight: normal;
            line-height: 1.15;
            margin: 0;
            white-space: nowrap;
        }
        .brand-header .brand-title {
            font-size: 14px;
            font-weight: bold;
            line-height: 1.15;
            margin: 2px 0 0 0;
            white-space: nowrap;
        }
        .meta {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 10px;
            table-layout: fixed;
        }
        .meta td {
            font-size: 12px;
            font-weight: bold;
            vertical-align: bottom;
            padding: 0;
        }
        .meta .entity {
            width: 62%;
            padding-right: 10px;
        }
        .meta .fund {
            width: 38%;
        }
        .meta .line {
            display: inline-block;
            border-bottom: 1px solid #000;
            font-weight: normal;
            min-width: 58%;
            padding: 0 2px 1px 4px;
        }
        .meta .line-short {
            min-width: 45%;
        }
        .header-box {
            width: 100%;
            border-collapse: collapse;
            table-layout: fixed;
            border: 1.5px solid #000;
        }
        .header-box td {
            border: 0.75px solid #000;
            vertical-align: middle;
            padding: 4px 6px;
            font-size: 12px;
            font-weight: normal;
            height: 22px;
        }
        .header-box .col-left {
            width: 66%;
        }
        .header-box .col-right {
            width: 34%;
        }
        .txn-table {
            width: 100%;
            border-collapse: collapse;
            table-layout: fixed;
            border: 1.5px solid #000;
            border-top: none;
            margin-top: 0;
        }
        .txn-table th,
        .txn-table td {
            border: 0.75px solid #000;
            vertical-align: middle;
            text-align: center;
            padding: 3px 4px;
            font-size: 11px;
        }
        .txn-table thead th {
            background: #fff;
            font-weight: bold;
            font-size: 12px;
        }
        .txn-table thead .group {
            font-style: italic;
            font-weight: bold;
        }
        .txn-table thead .sub {
            font-style: normal;
            font-weight: normal;
            font-size: 12px;
        }
        .txn-table td {
            height: 18px;
        }
        .col-date { width: 10%; }
        .col-ref { width: 13%; }
        .col-qty { width: 9%; }
        .col-office { width: 22%; }
        .col-bal { width: 12%; }
        .col-days { width: 16%; }
    </style>
</head>
<body>
@php
    $forDomPdf = (bool) ($forDomPdf ?? false);
    $showGeneratedOn = (bool) ($showGeneratedOn ?? false);
    $generatedOn = (string) ($generatedOn ?? now()->timezone(config('app.timezone'))->format('Y-m-d H:i'));

    $bagongRelative = (string) config('owwa_mail.logos.bagong_pilipinas', 'images/bagong-pilipinas-form-logo.png');
    $owwaRelative = (string) config('owwa_mail.logos.owwa', 'images/owwa-form-logo.png');
    $bagongAbsolute = public_path($bagongRelative);
    $owwaAbsolute = public_path($owwaRelative);

    // Preview: asset URLs (small HTML). DomPDF: absolute file paths. Never base64-per-card.
    $bagongPilipinasLogoSrc = is_readable($bagongAbsolute)
        ? ($forDomPdf ? $bagongAbsolute : asset($bagongRelative))
        : null;
    $owwaLogoSrc = is_readable($owwaAbsolute)
        ? ($forDomPdf ? $owwaAbsolute : asset($owwaRelative))
        : null;
@endphp
@foreach ($cards as $card)
    @php
        $transactions = $card['transactions'] ?? [];
        $minRows = 28;
        $padCount = max(0, $minRows - count($transactions));
        $entityLine = filled($card['office_name'] ?? null)
            ? (string) $card['office_name']
            : (string) ($entityName ?? '');
    @endphp
    <div class="card">
        <div class="brand-header-wrap">
            <table class="brand-header">
                <tr>
                    <td class="brand-logo brand-logo--left">
                        @if (filled($owwaLogoSrc))
                            <img src="{{ $owwaLogoSrc }}" alt="OWWA">
                        @endif
                    </td>
                    <td class="brand-title-block">
                        <div class="agency-line">Republic of the Philippines</div>
                        <div class="agency-line">OVERSEAS WORKERS WELFARE ADMINISTRATION</div>
                        <div class="brand-title">STOCK CARD</div>
                    </td>
                    <td class="brand-logo brand-logo--right">
                        @if (filled($bagongPilipinasLogoSrc))
                            <img src="{{ $bagongPilipinasLogoSrc }}" alt="Bagong Pilipinas">
                        @endif
                    </td>
                </tr>
            </table>
        </div>

        <table class="meta">
            <tr>
                <td class="entity">
                    Entity Name: <span class="line">{{ $entityLine }}&nbsp;</span>
                </td>
                <td class="fund">
                    Fund Cluster: <span class="line line-short">&nbsp;</span>
                </td>
            </tr>
        </table>

        <table class="header-box">
            <tr>
                <td class="col-left">Item : {{ $card['item_name'] }}</td>
                <td class="col-right">Stock No. : {{ $card['item_code'] }}</td>
            </tr>
            <tr>
                <td class="col-left">Description : {{ $card['description'] }}</td>
                <td class="col-right">Re-order Point : {{ $card['reorder_level'] }}</td>
            </tr>
            <tr>
                <td class="col-left">Unit of Measurement : {{ $card['unit'] }}</td>
                <td class="col-right">&nbsp;</td>
            </tr>
        </table>

        <table class="txn-table">
            <thead>
                <tr>
                    <th rowspan="2" class="col-date">Date</th>
                    <th rowspan="2" class="col-ref">Reference</th>
                    <th class="group col-qty">Receipt</th>
                    <th class="group" colspan="2">Issue</th>
                    <th class="group col-bal">Balance</th>
                    <th rowspan="2" class="col-days">No. of Days to Consume</th>
                </tr>
                <tr>
                    <th class="sub col-qty">Qty.</th>
                    <th class="sub col-qty">Qty.</th>
                    <th class="sub col-office">Office</th>
                    <th class="sub col-bal">Qty.</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($transactions as $txn)
                    <tr>
                        <td>{{ $txn['date'] ?? '' }}</td>
                        <td>{{ $txn['reference'] ?? '' }}</td>
                        <td>{{ $txn['receipt_qty'] ?? '' }}</td>
                        <td>{{ $txn['issue_qty'] ?? '' }}</td>
                        <td>{{ $txn['issue_office'] ?? '' }}</td>
                        <td>{{ $txn['balance'] ?? '' }}</td>
                        <td>{{ $card['days_to_consume'] }}</td>
                    </tr>
                @endforeach
                @for ($i = 0; $i < $padCount; $i++)
                    <tr>
                        <td>&nbsp;</td>
                        <td></td>
                        <td></td>
                        <td></td>
                        <td></td>
                        <td></td>
                        <td></td>
                    </tr>
                @endfor
            </tbody>
        </table>
        @if ($showGeneratedOn)
            <div class="generated-on">Generated on {{ $generatedOn }}</div>
        @endif
    </div>
@endforeach
</body>
</html>
