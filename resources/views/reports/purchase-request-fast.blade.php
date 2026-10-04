{{-- Appendix 60 Purchase Request lookalike (Fast DomPDF). Shared for consumable / PPE / semi-expendable. --}}
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Purchase Request</title>
    <style>
        @page {
            size: A4 portrait;
            margin: 18mm 25mm 14mm 28mm;
        }
        * {
            box-sizing: border-box;
        }
        body {
            font-family: "Times New Roman", Times, serif;
            font-size: 11px;
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
            bottom: 8mm;
            right: 0;
            left: 0;
            width: 100%;
            text-align: right;
            font-size: 10px;
            font-family: "Times New Roman", Times, serif;
            color: #000;
        }
        .brand-header-wrap {
            width: 100%;
            text-align: center;
            margin: 0 0 10px 0;
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
            margin-bottom: 4px;
            table-layout: fixed;
        }
        .meta td {
            font-size: 11px;
            font-weight: bold;
            vertical-align: bottom;
            padding: 0;
            border: none;
            text-align: left;
        }
        .meta .entity {
            width: 58%;
            padding-right: 8px;
        }
        .meta .fund {
            width: 42%;
        }
        .meta .line {
            display: inline-block;
            border-bottom: 1px solid #000;
            font-weight: bold;
            min-width: 55%;
            padding: 0 2px 1px 4px;
        }
        .meta .line-short {
            min-width: 42%;
        }
        .form-table {
            width: 100%;
            border-collapse: collapse;
            table-layout: fixed;
            border: 1.5px solid #000;
        }
        .form-table th,
        .form-table td {
            border: 0.75px solid #000;
            vertical-align: middle;
            padding: 2px 4px;
            font-size: 11px;
            font-weight: normal;
            text-align: left;
        }
        /* Official PR HTML bands: A–B ~31.7%, C–D ~45.5%, E–F ~22.4% (Date = Unit Cost + Total Cost) */
        .col-stock { width: 15.4%; }
        .col-unit { width: 16.2%; }
        .col-desc { width: 19.5%; }
        .col-qty { width: 25.9%; }
        .col-ucost { width: 9.2%; }
        .col-tcost { width: 13.1%; }
        .form-table .hdr-bold {
            font-weight: bold;
        }
        /* Office / PR / Date header: two visual rows, no mid horizontal rule (official xls) */
        .form-table tr.hdr-top td {
            border-bottom: none;
            vertical-align: top;
        }
        .form-table tr.hdr-bot td {
            border-top: none;
            vertical-align: top;
        }
        .form-table tr.hdr-top td.date-ef-band {
            font-weight: bold;
            vertical-align: top;
            border: 0.75px solid #000;
        }
        .form-table .col-head {
            text-align: center;
            font-weight: bold;
            vertical-align: middle;
            height: 28px;
        }
        .form-table .stock-head {
            line-height: 1.15;
            text-align: center;
            font-weight: bold;
        }
        .form-table .line-cell {
            height: 14px;
            vertical-align: top;
        }
        .form-table .cell-center {
            text-align: center;
        }
        .form-table .cell-right {
            text-align: right;
        }
        /* One Purpose row: underline lines inside a single cell (no internal grid) */
        .form-table .purpose-block {
            vertical-align: top;
            text-align: left;
            padding: 2px 4px 4px 4px;
        }
        .form-table .purpose-line {
            display: block;
            border-bottom: 1px solid #000;
            min-height: 14px;
            padding: 0 2px 1px 0;
            margin: 0 0 2px 0;
        }
        .form-table .sig-head {
            text-align: center;
            height: 16px;
        }
        .form-table .sig-label {
            text-align: left;
            width: 15%;
        }
        .form-table .sig-line {
            border-bottom: 1px solid #000;
            min-height: 13px;
            display: block;
            padding: 0 2px 1px 2px;
        }
        .office-value {
            min-height: 14px;
            display: block;
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

    $minRows = (int) ($minRows ?? 22);
@endphp
@if ($showGeneratedOn)
    <div class="generated-on">Generated on {{ $generatedOn }}</div>
@endif
@foreach ($pages as $page)
    @php
        $lines = $page['lines'] ?? [];
        $padCount = max(0, $minRows - count($lines));
        $includeFooter = (bool) ($page['include_footer'] ?? false);
        $purposeText = $includeFooter ? (string) ($page['purpose'] ?? '') : '';
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
                        <div class="brand-title">PURCHASE REQUEST</div>
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
                    Entity Name: <span class="line">{{ $page['entity_name'] ?? '' }}&nbsp;</span>
                </td>
                <td class="fund">
                    Fund Cluster: <span class="line line-short">&nbsp;</span>
                </td>
            </tr>
        </table>

        <table class="form-table">
            <colgroup>
                <col class="col-stock">
                <col class="col-unit">
                <col class="col-desc">
                <col class="col-qty">
                <col class="col-ucost">
                <col class="col-tcost">
            </colgroup>
            <tr class="hdr-top">
                <td colspan="2">Office/Section : _____________</td>
                <td colspan="2" class="hdr-bold">PR No.: {{ $page['pr_no'] ?? '' }}</td>
                <td colspan="2" rowspan="2" class="date-ef-band">Date: {{ $page['date'] ?? '' }}</td>
            </tr>
            <tr class="hdr-bot">
                <td colspan="2"><span class="office-value">{{ $page['office_section'] ?? '' }}&nbsp;</span></td>
                <td colspan="2" class="hdr-bold">Responsibility Center Code : {{ $page['responsibility_center_code'] ?? '' }}</td>
            </tr>
            <tr>
                <th class="stock-head">Stock/ Property<br>No.</th>
                <th class="col-head">Unit</th>
                <th class="col-head">Item Description</th>
                <th class="col-head">Quantity</th>
                <th class="col-head">Unit Cost</th>
                <th class="col-head">Total Cost</th>
            </tr>
            @foreach ($lines as $line)
                <tr>
                    <td class="line-cell cell-center">{{ $line['stock_no'] ?? '' }}</td>
                    <td class="line-cell cell-center">{{ $line['unit'] ?? '' }}</td>
                    <td class="line-cell">{{ $line['description'] ?? '' }}</td>
                    <td class="line-cell cell-center">{{ $line['quantity'] ?? '' }}</td>
                    <td class="line-cell cell-right">{{ $line['unit_cost'] ?? '' }}</td>
                    <td class="line-cell cell-right">{{ $line['total_cost'] ?? '' }}</td>
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
            <tr>
                <td colspan="6" class="purpose-block">
                    <span class="purpose-line">Purpose: {{ $purposeText }}</span>
                    <span class="purpose-line">&nbsp;</span>
                    <span class="purpose-line">&nbsp;</span>
                </td>
            </tr>
            <tr>
                <td>&nbsp;</td>
                <td colspan="2" class="sig-head">Requested by:</td>
                <td colspan="3" class="sig-head">Approved by:</td>
            </tr>
            <tr>
                <td class="sig-label">Signature :</td>
                <td colspan="2"><span class="sig-line">&nbsp;</span></td>
                <td colspan="3"><span class="sig-line">&nbsp;</span></td>
            </tr>
            <tr>
                <td class="sig-label">Printed Name :</td>
                <td colspan="2"><span class="sig-line">@if ($includeFooter){{ $page['requested_by_name'] ?? '' }}@endif&nbsp;</span></td>
                <td colspan="3"><span class="sig-line">@if ($includeFooter){{ $page['approved_by_name'] ?? '' }}@endif&nbsp;</span></td>
            </tr>
            <tr>
                <td class="sig-label">Designation :</td>
                <td colspan="2"><span class="sig-line">@if ($includeFooter){{ $page['requested_by_designation'] ?? '' }}@endif&nbsp;</span></td>
                <td colspan="3"><span class="sig-line">@if ($includeFooter){{ $page['approved_by_designation'] ?? '' }}@endif&nbsp;</span></td>
            </tr>
        </table>
    </div>
@endforeach
</body>
</html>
