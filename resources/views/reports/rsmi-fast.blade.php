{{-- Appendix 64 RSMI lookalike (Fast DomPDF). Consumable issuances only. --}}
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Report of Supplies and Materials Issued</title>
    <style>
        @page {
            size: A4 portrait;
            /* Extra bottom margin so expanding detail/recap rows stop above the fixed Generated on footer. */
            margin: 12mm 12mm 20mm 12mm;
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
            font-size: 10px;
            font-weight: bold;
            vertical-align: bottom;
            padding: 1px 0;
            border: none;
            text-align: left;
        }
        .meta .meta-left {
            width: 55%;
            padding-right: 8px;
        }
        .meta .meta-right {
            width: 45%;
        }
        .meta .line {
            display: inline-block;
            border-bottom: 1px solid #000;
            font-weight: bold;
            min-width: 58%;
            padding: 0 2px 1px 4px;
        }
        .meta .line-short {
            min-width: 48%;
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
            padding: 2px 3px;
            font-size: 9px;
            font-weight: normal;
            text-align: center;
        }
        .col-ris { width: 12%; }
        .col-rc { width: 14%; }
        .col-stock { width: 11%; }
        .col-item { width: 22%; }
        .col-unit { width: 8%; }
        .col-qty { width: 10%; }
        .col-ucost { width: 11%; }
        .col-amount { width: 12%; }
        .form-table .banner {
            font-weight: bold;
            font-size: 9px;
            text-align: center;
            height: 18px;
        }
        .form-table .col-head {
            font-weight: bold;
            text-align: center;
            vertical-align: middle;
            height: 28px;
            line-height: 1.15;
        }
        .form-table .line-cell {
            height: 14px;
            vertical-align: middle;
        }
        .form-table .cell-left {
            text-align: left;
        }
        .form-table .recap-label {
            font-weight: bold;
            text-align: left;
            border-bottom: none;
        }
        .form-table .recap-head {
            font-weight: bold;
            text-align: center;
        }
        .form-table .sig-cert {
            text-align: left;
            font-size: 9px;
            border-bottom: none;
            vertical-align: bottom;
            height: 22px;
        }
        .form-table .sig-posted {
            text-align: left;
            font-weight: bold;
            border-bottom: none;
            vertical-align: bottom;
        }
        .form-table .sig-line {
            border-top: none;
            border-bottom: none;
            text-align: center;
            vertical-align: bottom;
            height: 22px;
            font-weight: bold;
        }
        .form-table .sig-caption {
            border-top: none;
            text-align: center;
            font-size: 8px;
            vertical-align: top;
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

    $minRows = (int) ($minRows ?? 21);
    $minRecapRows = (int) ($minRecapRows ?? 16);
@endphp
@if ($showGeneratedOn)
    <div class="generated-on">Generated on {{ $generatedOn }}</div>
@endif
@foreach ($pages as $page)
    @php
        $lines = $page['lines'] ?? [];
        $detailMin = (int) ($page['min_detail_rows'] ?? $minRows);
        $padCount = max(0, $detailMin - count($lines));
        $includeFooter = (bool) ($page['include_footer'] ?? false);
        $recapLines = $includeFooter ? ($page['recap_lines'] ?? []) : [];
        $recapMin = (int) ($page['min_recap_rows'] ?? $minRecapRows);
        $recapPad = $includeFooter ? max(0, $recapMin - count($recapLines)) : 0;
        $custodianName = (string) ($page['custodian_name'] ?? '');
        $accountingName = (string) ($page['accounting_staff_name'] ?? '');
        $postedDate = (string) ($page['posted_date'] ?? '');
        $serialDisplay = (string) ($page['serial_no'] ?? '');
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
                        <div class="brand-title">REPORT OF SUPPLIES AND MATERIALS ISSUED</div>
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
                <td class="meta-left">Entity Name: <span class="line">{{ $page['entity_name'] ?? '' }}&nbsp;</span></td>
                <td class="meta-right">Serial No. : <span class="line line-short">{{ $serialDisplay }}&nbsp;</span></td>
            </tr>
            <tr>
                <td class="meta-left">Fund Cluster: <span class="line">{{ $page['fund_cluster'] ?? '' }}&nbsp;</span></td>
                <td class="meta-right">Date : <span class="line line-short">{{ $page['date'] ?? '' }}&nbsp;</span></td>
            </tr>
        </table>

        <table class="form-table">
            <colgroup>
                <col class="col-ris">
                <col class="col-rc">
                <col class="col-stock">
                <col class="col-item">
                <col class="col-unit">
                <col class="col-qty">
                <col class="col-ucost">
                <col class="col-amount">
            </colgroup>
            <tr>
                <td colspan="6" class="banner">To be filled up by the Supply and/or Property Division/Unit</td>
                <td colspan="2" class="banner">To be filled up by the Accounting Division/Unit</td>
            </tr>
            <tr>
                <th class="col-head">RIS No.</th>
                <th class="col-head">Responsibility<br>Center Code</th>
                <th class="col-head">Stock No.</th>
                <th class="col-head">Item</th>
                <th class="col-head">Unit</th>
                <th class="col-head">Quantity<br>Issued</th>
                <th class="col-head">Unit Cost</th>
                <th class="col-head">Amount</th>
            </tr>
            @foreach ($lines as $line)
                <tr>
                    <td class="line-cell">{{ $line['ris_no'] ?? '' }}</td>
                    <td class="line-cell">{{ $line['responsibility_center'] ?? '' }}</td>
                    <td class="line-cell">{{ $line['stock_no'] ?? '' }}</td>
                    <td class="line-cell cell-left">{{ $line['item'] ?? '' }}</td>
                    <td class="line-cell">{{ $line['unit'] ?? '' }}</td>
                    <td class="line-cell">{{ $line['quantity'] ?? '' }}</td>
                    <td class="line-cell">{{ $line['unit_cost'] ?? '' }}</td>
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
                    <td class="line-cell"></td>
                    <td class="line-cell"></td>
                </tr>
            @endfor

            @if ($includeFooter)
                <tr>
                    <td class="recap-label" style="border-right: none;">&nbsp;</td>
                    <td colspan="2" class="recap-label">Recapitulation:</td>
                    <td colspan="2" class="recap-label" style="border-left: none; border-right: none;">&nbsp;</td>
                    <td colspan="3" class="recap-label">Recapitulation:</td>
                </tr>
                <tr>
                    <td style="border-right: none;">&nbsp;</td>
                    <td class="recap-head">Stock No.</td>
                    <td class="recap-head">Quantity</td>
                    <td colspan="2" style="border-left: none; border-right: none;">&nbsp;</td>
                    <td class="recap-head">Unit Cost</td>
                    <td class="recap-head">Total Cost</td>
                    <td class="recap-head">UACS Object Code</td>
                </tr>
                @foreach ($recapLines as $recap)
                    <tr>
                        <td style="border-right: none;">&nbsp;</td>
                        <td class="line-cell">{{ $recap['stock_no'] ?? '' }}</td>
                        <td class="line-cell">{{ $recap['quantity'] ?? '' }}</td>
                        <td colspan="2" style="border-left: none; border-right: none;">&nbsp;</td>
                        <td class="line-cell">{{ $recap['unit_cost'] ?? '' }}</td>
                        <td class="line-cell">{{ $recap['total_cost'] ?? '' }}</td>
                        <td class="line-cell">{{ $recap['uacs'] ?? '' }}</td>
                    </tr>
                @endforeach
                @for ($i = 0; $i < $recapPad; $i++)
                    <tr>
                        <td style="border-right: none;">&nbsp;</td>
                        <td class="line-cell">&nbsp;</td>
                        <td class="line-cell"></td>
                        <td colspan="2" style="border-left: none; border-right: none;">&nbsp;</td>
                        <td class="line-cell"></td>
                        <td class="line-cell"></td>
                        <td class="line-cell"></td>
                    </tr>
                @endfor
                <tr>
                    <td colspan="5" class="sig-cert">I hereby certify to the correctness of the above information.</td>
                    <td colspan="3" class="sig-posted">Posted by:</td>
                </tr>
                <tr>
                    <td colspan="5" class="sig-line">
                        {{ filled($custodianName) ? $custodianName : '_____________________________________________' }}
                    </td>
                    <td colspan="2" class="sig-line">
                        {{ filled($accountingName) ? $accountingName : '__________________________' }}
                    </td>
                    <td class="sig-line">
                        {{ filled($postedDate) ? $postedDate : '______________' }}
                    </td>
                </tr>
                <tr>
                    <td colspan="5" class="sig-caption">Signature over Printed Name of Supply and/or Property Custodian</td>
                    <td colspan="2" class="sig-caption">Signature over Printed Name of Designated Accounting Staff</td>
                    <td class="sig-caption">Date</td>
                </tr>
            @endif
        </table>
    </div>
@endforeach
</body>
</html>
