{{-- Appendix 71 PAR lookalike (Fast DomPDF). PPE issuances only. --}}
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Property Acknowledgment Receipt</title>
    <style>
        @page {
            size: A4 portrait;
            /* Stock Card Fast default margins (see .cursor/rules/owwa-fast-export-margins.mdc). */
            margin: 14mm 12mm 12mm 12mm;
        }
        * {
            box-sizing: border-box;
        }
        body {
            font-family: "Times New Roman", Times, serif;
            font-size: 10px;
            color: #000;
            margin: 0;
            padding: 0;
        }
        .page {
            page-break-after: always;
            width: 100%;
        }
        .page:last-child {
            page-break-after: auto;
        }
        .generated-on {
            position: fixed;
            bottom: -12mm;
            right: 0;
            left: 0;
            height: 10mm;
            line-height: 10mm;
            text-align: right;
            font-size: 10px;
            font-family: "Times New Roman", Times, serif;
            color: #000;
        }
        .brand-header-wrap {
            width: 100%;
            text-align: center;
            margin: 0 0 8px 0;
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
            line-height: 1.2;
        }
        .brand-header .brand-title {
            font-size: 14px;
            font-weight: bold;
            line-height: 1.25;
            margin-top: 2px;
        }
        .meta {
            width: 100%;
            border-collapse: collapse;
            margin: 0 0 6px 0;
            font-size: 10px;
            table-layout: fixed;
        }
        .meta td {
            border: none;
            padding: 1px 0;
            vertical-align: bottom;
            font-weight: bold;
        }
        .meta .meta-left {
            /* Official: Entity/Fund underlines end at col D (~A–D band). */
            width: 55%;
            text-align: left;
        }
        .meta .meta-right {
            width: 45%;
            text-align: right;
            padding-left: 8px;
            white-space: nowrap;
        }
        .meta .line {
            display: inline-block;
            /* Same underline length for Entity Name and Fund Cluster. */
            width: 55%;
            border-bottom: 1px solid #000;
            font-weight: bold;
            padding: 0 4px 1px 4px;
            text-align: left;
            box-sizing: border-box;
        }
        .meta .line-no {
            display: inline-block;
            min-width: 3.2cm;
            border-bottom: 1px solid #000;
            font-weight: bold;
            padding: 0 2px 1px 4px;
            text-align: left;
            vertical-align: bottom;
        }
        .form-table {
            width: 100%;
            border-collapse: collapse;
            table-layout: fixed;
            font-size: 9px;
        }
        .form-table th,
        .form-table td {
            border: 1px solid #000;
            padding: 2px 3px;
            vertical-align: middle;
        }
        .form-table thead {
            display: table-header-group;
        }
        .form-table .col-head {
            text-align: center;
            font-weight: bold;
            vertical-align: middle;
        }
        .form-table .line-cell {
            text-align: center;
            vertical-align: middle;
            height: 14px;
        }
        /* Official .xls shares: A11 B11 C31 D14 E14 F19 */
        .col-qty { width: 11%; }
        .col-unit { width: 11%; }
        .col-desc { width: 31%; }
        .col-prop { width: 14%; }
        .col-date { width: 14%; }
        .col-amount { width: 19%; }
        .sig-wrap {
            width: 100%;
            border-collapse: collapse;
            margin-top: 0;
            font-size: 10px;
            /* No top border — the last detail-row line already closes the table. */
            border-left: 1px solid #000;
            border-right: 1px solid #000;
            border-bottom: 1px solid #000;
            border-top: none;
        }
        .sig-wrap td {
            width: 50%;
            vertical-align: top;
            padding: 8px 10px 6px 10px;
            border: none;
        }
        .sig-wrap td.sig-left {
            border-right: 1px solid #000;
        }
        .sig-heading {
            font-weight: bold;
            margin-bottom: 10px;
        }
        .sig-value {
            border-bottom: 1px solid #000;
            min-height: 16px;
            padding: 2px 2px 1px 2px;
            text-align: center;
        }
        .sig-caption {
            text-align: center;
            font-size: 8px;
            margin: 2px 0 10px 0;
        }
        .sig-caption:last-child {
            margin-bottom: 0;
        }
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

    $bagongPilipinasLogoSrc = is_readable($bagongAbsolute)
        ? ($forDomPdf ? $bagongAbsolute : asset($bagongRelative))
        : null;
    $owwaLogoSrc = is_readable($owwaAbsolute)
        ? ($forDomPdf ? $owwaAbsolute : asset($owwaRelative))
        : null;

    $minRows = (int) ($minRows ?? 30);
@endphp
@if ($showGeneratedOn)
    <div class="generated-on">Generated on {{ $generatedOn }}</div>
@endif
@foreach ($pages as $page)
    @php
        $lines = $page['lines'] ?? [];
        $detailMin = (int) ($page['min_detail_rows'] ?? $minRows);
        $padCount = max(0, $detailMin - count($lines));
    @endphp
    <div class="page">
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
                        <div class="brand-title">PROPERTY ACKNOWLEDGMENT RECEIPT</div>
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
                <td class="meta-left">Entity Name : <span class="line">{{ $page['entity_name'] ?? '' }}&nbsp;</span></td>
                <td class="meta-right"></td>
            </tr>
            <tr>
                <td class="meta-left">Fund Cluster: <span class="line">{{ $page['fund_cluster'] ?? '' }}&nbsp;</span></td>
                <td class="meta-right">PAR No.: <span class="line-no">{{ $page['par_no'] ?? '' }}&nbsp;</span></td>
            </tr>
        </table>

        <table class="form-table">
            <colgroup>
                <col class="col-qty">
                <col class="col-unit">
                <col class="col-desc">
                <col class="col-prop">
                <col class="col-date">
                <col class="col-amount">
            </colgroup>
            <thead>
                <tr>
                    <th class="col-head">Quantity</th>
                    <th class="col-head">Unit</th>
                    <th class="col-head">Description</th>
                    <th class="col-head">Property Number</th>
                    <th class="col-head">Date Acquired</th>
                    <th class="col-head">Amount</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($lines as $line)
                    <tr>
                        <td class="line-cell">{{ $line['quantity'] ?? '' }}</td>
                        <td class="line-cell">{{ $line['unit'] ?? '' }}</td>
                        <td class="line-cell">{{ $line['description'] ?? '' }}</td>
                        <td class="line-cell">{{ $line['property_number'] ?? '' }}</td>
                        <td class="line-cell">{{ $line['date_acquired'] ?? '' }}</td>
                        <td class="line-cell">{{ $line['amount'] ?? '' }}</td>
                    </tr>
                @endforeach
                @for ($i = 0; $i < $padCount; $i++)
                    <tr>
                        <td class="line-cell">&nbsp;</td>
                        <td class="line-cell"></td>
                        <td class="line-cell"></td>
                        <td class="line-cell"></td>
                        <td class="line-cell"></td>
                        <td class="line-cell"></td>
                    </tr>
                @endfor
            </tbody>
        </table>

        <table class="sig-wrap">
            <tr>
                <td class="sig-left">
                    <div class="sig-heading">Received by:</div>
                    <div class="sig-value">{{ $page['received_by_name'] ?? '' }}</div>
                    <div class="sig-caption">Signature over Printed Name of End-User</div>
                    <div class="sig-value">{{ $page['received_by_position'] ?? '' }}</div>
                    <div class="sig-caption">Position/Office</div>
                    <div class="sig-value">{{ $page['received_by_date'] ?? '' }}</div>
                    <div class="sig-caption">Date</div>
                </td>
                <td>
                    <div class="sig-heading">Issued by:</div>
                    <div class="sig-value">{{ $page['issued_by_name'] ?? '' }}</div>
                    <div class="sig-caption">Signature over Printed Name of Supply and/or Property Custodian</div>
                    <div class="sig-value">{{ $page['issued_by_position'] ?? '' }}</div>
                    <div class="sig-caption">Position/Office</div>
                    <div class="sig-value">{{ $page['issued_by_date'] ?? '' }}</div>
                    <div class="sig-caption">Date</div>
                </td>
            </tr>
        </table>
    </div>
@endforeach
</body>
</html>
