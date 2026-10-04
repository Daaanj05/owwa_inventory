{{-- Inspection and Acceptance Report lookalike (Fast DomPDF). Shared for consumable / PPE / semi-expendable. --}}
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Inspection and Acceptance Report</title>
    <style>
        @page {
            size: A4 portrait;
            /* Match Official IAR page margins (1.25" / 1" / 1" / 0.5"). */
            margin: 25.4mm 25.4mm 12.7mm 31.75mm;
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
            font-size: 11px;
            font-weight: bold;
            vertical-align: bottom;
            padding: 0;
            border: none;
            text-align: left;
        }
        /* Entity / Fund Cluster follows Official A:C | D:F split. */
        .meta .entity { width: 65.5%; padding-right: 8px; }
        .meta .fund { width: 34.5%; }
        .meta .line {
            display: inline-block;
            border-bottom: 1px solid #000;
            font-weight: bold;
            min-width: 55%;
            padding: 0 2px 1px 4px;
        }
        .meta .line-short { min-width: 42%; }
        /*
         * Split meta / lines / footer into separate tables so DomPDF does not
         * redistribute line column widths from header/footer colspan="2" rows.
         */
        .form-meta,
        .form-lines,
        .form-footer {
            width: 100%;
            border-collapse: collapse;
            table-layout: fixed;
        }
        .form-meta {
            border: 1.5px solid #000;
            border-bottom: none;
        }
        .form-lines {
            border-left: 1.5px solid #000;
            border-right: 1.5px solid #000;
        }
        .form-footer {
            border: 1.5px solid #000;
            border-top: none;
        }
        .form-meta th,
        .form-meta td,
        .form-lines th,
        .form-lines td,
        .form-footer th,
        .form-footer td {
            border: 0.75px solid #000;
            vertical-align: middle;
            padding: 2px 4px;
            font-size: 11px;
            font-weight: normal;
            text-align: left;
        }
        /*
         * Official IAR (PhpSpreadsheet A–F):
         * Meta left A:C = 65.5% | right D:F = 34.5% (IAR No. / Date / Invoice No. / Date).
         * Detail: A=16.8% | B:C=48.7% | D=14.0% | E:F=20.5%.
         * Footer aligns with detail split (Stock+Desc | Unit+Qty).
         */
        .form-meta .meta-left { width: 65.5%; }
        .form-meta .meta-right { width: 34.5%; }
        .col-stock { width: 16.8%; }
        .col-desc { width: 48.7%; }
        .col-unit { width: 14.0%; }
        .col-qty { width: 20.5%; }
        .form-meta .hdr-bold { font-weight: bold; }
        .form-meta .hdr-cell {
            border-bottom: none;
            vertical-align: bottom;
        }
        .form-meta .hdr-cell-bot {
            border-top: none;
            vertical-align: top;
        }
        .form-lines .col-head {
            text-align: center;
            font-weight: bold;
            vertical-align: middle;
            height: 28px;
        }
        .form-lines .stock-head {
            line-height: 1.15;
            text-align: center;
            font-weight: bold;
        }
        .form-lines .line-cell {
            height: 14px;
            vertical-align: top;
        }
        .form-lines td.cell-center {
            text-align: center;
        }
        .form-footer .section-head {
            text-align: center;
            font-weight: bold;
            height: 18px;
        }
        .form-footer .footer-left { width: 65.5%; }
        .form-footer .footer-right { width: 34.5%; }
        .form-footer .inspect-text {
            vertical-align: top;
            font-size: 10px;
            padding: 4px;
        }
        .form-footer .accept-box {
            vertical-align: top;
            padding: 4px 6px;
        }
        .form-footer .sig-line-cell {
            border-bottom: none;
            text-align: center;
            vertical-align: bottom;
            height: 24px;
        }
        .form-footer .sig-caption {
            border-top: none;
            text-align: center;
            font-size: 10px;
            vertical-align: top;
        }
        .form-footer .underline {
            display: inline-block;
            border-bottom: 1px solid #000;
            min-width: 75%;
            min-height: 13px;
            padding: 0 2px 1px 2px;
        }
        .form-footer .hdr-cell {
            vertical-align: bottom;
        }
        .field-line {
            display: inline-block;
            border-bottom: 1px solid #000;
            min-width: 42%;
            max-width: 70%;
            padding: 0 2px 1px 4px;
            font-weight: bold;
        }
        .field-line-short {
            min-width: 28%;
            max-width: 55%;
        }
        .form-meta .meta-right .field-line,
        .form-meta .meta-right .field-line-short {
            min-width: 24%;
            max-width: 48%;
        }
        .checkbox {
            display: inline-block;
            width: 11px;
            height: 11px;
            border: 1px solid #000;
            margin-right: 6px;
            vertical-align: middle;
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

    $minRows = (int) ($minRows ?? 13);
@endphp
@if ($showGeneratedOn)
    <div class="generated-on">Generated on {{ $generatedOn }}</div>
@endif
@foreach ($pages as $page)
    @php
        $lines = $page['lines'] ?? [];
        $padCount = max(0, $minRows - count($lines));
        $includeFooter = (bool) ($page['include_footer'] ?? false);
        $dateInspected = $includeFooter ? (string) ($page['date_inspected'] ?? '') : '';
        $dateReceived = $includeFooter ? (string) ($page['date_received'] ?? '') : '';
        $officer = $includeFooter ? (string) ($page['inspection_officer_name'] ?? '') : '';
        $custodian = $includeFooter ? (string) ($page['custodian_name'] ?? '') : '';
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
                        <div class="brand-title">INSPECTION AND ACCEPTANCE REPORT</div>
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
                    Entity Name : <span class="line">{{ $page['entity_name'] ?? '' }}&nbsp;</span>
                </td>
                <td class="fund">
                    Fund Cluster : <span class="line line-short">&nbsp;</span>
                </td>
            </tr>
        </table>

        <table class="form-meta">
            <colgroup>
                <col class="meta-left" style="width:65.5%">
                <col class="meta-right" style="width:34.5%">
            </colgroup>
            <tr>
                <td class="hdr-cell">Supplier : <span class="field-line">{{ $page['supplier'] ?? '' }}&nbsp;</span></td>
                <td class="hdr-cell hdr-bold meta-right">IAR No. : {{ $page['iar_no'] ?? '' }}</td>
            </tr>
            <tr>
                <td class="hdr-cell hdr-cell-bot">PO No./Date : <span class="field-line">{{ $page['po_no_date'] ?? '' }}&nbsp;</span></td>
                <td class="hdr-cell hdr-cell-bot hdr-bold meta-right">Date : {{ $page['date'] ?? '' }}</td>
            </tr>
            <tr>
                <td class="hdr-cell hdr-cell-bot">Requisitioning Office/Dept. : <span class="field-line">{{ $page['requisitioning_office'] ?? '' }}&nbsp;</span></td>
                <td class="hdr-cell hdr-cell-bot meta-right">Invoice No. : <span class="field-line field-line-short">{{ $page['invoice_no'] ?? '' }}&nbsp;</span></td>
            </tr>
            <tr>
                <td class="hdr-cell hdr-cell-bot">Responsibility Center Code : <span class="field-line">{{ $page['responsibility_center_code'] ?? '' }}&nbsp;</span></td>
                <td class="hdr-cell hdr-cell-bot meta-right">Date : <span class="field-line field-line-short">{{ $page['invoice_date'] ?? '' }}&nbsp;</span></td>
            </tr>
        </table>

        <table class="form-lines">
            <colgroup>
                <col class="col-stock" style="width:16.8%">
                <col class="col-desc" style="width:48.7%">
                <col class="col-unit" style="width:14.0%">
                <col class="col-qty" style="width:20.5%">
            </colgroup>
            <tr>
                <th class="stock-head">Stock/<br>Property No.</th>
                <th class="col-head">Description</th>
                <th class="col-head">Unit</th>
                <th class="col-head">Quantity</th>
            </tr>
            @foreach ($lines as $line)
                <tr>
                    <td class="line-cell cell-center">{{ $line['stock_no'] ?? '' }}</td>
                    <td class="line-cell cell-center">{{ $line['description'] ?? '' }}</td>
                    <td class="line-cell cell-center">{{ $line['unit'] ?? '' }}</td>
                    <td class="line-cell cell-center">{{ $line['quantity'] ?? '' }}</td>
                </tr>
            @endforeach
            @for ($i = 0; $i < $padCount; $i++)
                <tr>
                    <td class="line-cell">&nbsp;</td>
                    <td class="line-cell"></td>
                    <td class="line-cell"></td>
                    <td class="line-cell"></td>
                </tr>
            @endfor
        </table>

        <table class="form-footer">
            <colgroup>
                <col class="footer-left" style="width:65.5%">
                <col class="footer-right" style="width:34.5%">
            </colgroup>
            <tr>
                <td class="section-head">INSPECTION</td>
                <td class="section-head">ACCEPTANCE</td>
            </tr>
            <tr>
                <td class="hdr-cell">Date Inspected : <span class="field-line">{{ $dateInspected }}&nbsp;</span></td>
                <td class="hdr-cell">Date Received : <span class="field-line field-line-short">{{ $dateReceived }}&nbsp;</span></td>
            </tr>
            <tr>
                <td class="inspect-text">
                    Inspected, verified and found in order as to quantity and specifications
                </td>
                <td class="accept-box">
                    <div><span class="checkbox"></span>Complete</div>
                    <div style="margin-top:6px;"><span class="checkbox"></span>Partial (pls. specify quantity)</div>
                </td>
            </tr>
            <tr>
                <td class="sig-line-cell"><span class="underline">{{ $officer }}&nbsp;</span></td>
                <td class="sig-line-cell"><span class="underline">{{ $custodian }}&nbsp;</span></td>
            </tr>
            <tr>
                <td class="sig-caption">Inspection Officer/Inspection Committee</td>
                <td class="sig-caption">Supply and/or Property Custodian</td>
            </tr>
        </table>
    </div>
@endforeach
</body>
</html>
