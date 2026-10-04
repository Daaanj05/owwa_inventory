{{-- Purchase Order lookalike (Fast DomPDF). Shared for consumable / PPE / semi-expendable. --}}
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Purchase Order</title>
    <style>
        @page {
            size: A4 portrait;
            margin: 12mm 14mm 14mm 14mm;
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
            margin-bottom: 6px;
            table-layout: fixed;
        }
        .meta td {
            font-size: 11px;
            font-weight: bold;
            vertical-align: bottom;
            padding: 0;
            border: none;
            text-align: center;
        }
        .meta .entity-line {
            display: inline-block;
            border-bottom: 1px solid #000;
            font-weight: bold;
            min-width: 55%;
            max-width: 72%;
            padding: 0 8px 1px 8px;
            text-align: center;
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
        /* Official PO xls bands: A–F ≈ 12.8 / 13.2 / 33.3 / 12.3 / 12.4 / 16.1 */
        .col-stock { width: 12.8%; }
        .col-unit { width: 13.2%; }
        .col-desc { width: 33.3%; }
        .col-qty { width: 12.3%; }
        .col-ucost { width: 12.4%; }
        .col-amount { width: 16.0%; }
        .form-table .hdr-bold {
            font-weight: bold;
        }
        .form-table .hdr-cell {
            border-bottom: none;
            vertical-align: bottom;
            padding: 2px 4px;
        }
        /* Plain-cell supplier (no nested markup) — most reliable DomPDF text path. */
        .form-table .hdr-supplier {
            font-weight: bold;
            border-bottom: 1px solid #000;
        }
        .form-table .hdr-cell-bot {
            border-top: none;
            vertical-align: top;
            padding: 2px 4px;
        }
        /* Keep horizontal rule above Gentlemen (Official xls separator after TIN / Mode). */
        .form-table .gentlemen {
            border-bottom: none;
            padding: 4px 4px 2px 4px;
            font-style: normal;
        }
        .form-table .gentlemen-body {
            border-top: none;
            padding: 0 4px 4px 18px;
        }
        .form-table .address-cell .fill-line td {
            white-space: normal;
            word-wrap: break-word;
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
        .form-table .words-label {
            font-weight: normal;
            vertical-align: middle;
        }
        .form-table .penalty {
            border-top: none;
            padding: 6px 4px;
            font-size: 10px;
            text-align: justify;
        }
        .form-table .sig-head {
            text-align: left;
            font-weight: bold;
            border-bottom: none;
            height: 18px;
        }
        .form-table .sig-line-cell {
            border-top: none;
            border-bottom: none;
            text-align: center;
            vertical-align: bottom;
            height: 22px;
            font-weight: bold;
        }
        .form-table .sig-caption {
            border-top: none;
            border-bottom: none;
            text-align: center;
            font-size: 10px;
            vertical-align: top;
        }
        .form-table .sig-date-caption {
            border-top: none;
            border-bottom: none;
            text-align: center;
            font-size: 10px;
            vertical-align: top;
        }
        /* Accounting box: Official uses underscore blanks, no mid horizontal rule (A45–A46). */
        .form-table .acct-top {
            border-bottom: none;
            vertical-align: bottom;
            height: 16px;
        }
        .form-table .acct-mid {
            border-top: none;
            border-bottom: none;
            vertical-align: top;
            height: 16px;
        }
        .form-table .acct-bot {
            border-top: none;
            vertical-align: top;
            padding-top: 4px;
            text-align: center;
        }
        .form-table .acct-bot .acct-sign-caption {
            display: block;
            text-align: center;
            font-size: 10px;
            font-weight: normal;
            margin-top: 2px;
        }
        .form-table .acct-blank {
            font-weight: normal;
        }
        /*
         * DomPDF-safe filled fields: nested table + td border-bottom.
         * Avoid inline-block spans and div underlines (clip / missing glyphs in some viewers).
         */
        .fill-line {
            width: 100%;
            border-collapse: collapse;
            table-layout: fixed;
        }
        .fill-line td {
            border: none;
            border-bottom: 1px solid #000;
            padding: 0 2px 1px 0;
            vertical-align: bottom;
            font-size: 11px;
            font-weight: bold;
            line-height: 1.25;
        }
        .fill-line .fill-label {
            font-weight: normal;
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

    $minRows = (int) ($minRows ?? 15);
@endphp
@if ($showGeneratedOn)
    <div class="generated-on">Generated on {{ $generatedOn }}</div>
@endif
@foreach ($pages as $page)
    @php
        $lines = $page['lines'] ?? [];
        $padCount = max(0, $minRows - count($lines));
        $includeFooter = (bool) ($page['include_footer'] ?? false);
        $totalFormatted = $includeFooter ? (string) ($page['total_amount_formatted'] ?? '') : '';
        $totalWords = $includeFooter ? (string) ($page['total_amount_in_words'] ?? '') : '';
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
                        <div class="brand-title">PURCHASE ORDER</div>
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
                <td>
                    <span class="entity-line">Entity Name: {{ $page['entity_name'] ?? '' }}&nbsp;</span>
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
                <col class="col-amount">
            </colgroup>
            <tr>
                <td colspan="3" class="hdr-cell hdr-supplier">
                    Supplier : {{ $page['supplier'] ?? '' }}
                </td>
                <td colspan="3" class="hdr-cell hdr-bold">P.O. No. : {{ $page['po_no'] ?? '' }}</td>
            </tr>
            <tr>
                <td colspan="3" class="hdr-cell hdr-cell-bot address-cell">
                    <table class="fill-line"><tr><td>
                        <span class="fill-label">Address :</span> {{ $page['address'] ?? '' }}
                    </td></tr></table>
                </td>
                <td colspan="3" class="hdr-cell hdr-cell-bot hdr-bold">Date : {{ $page['date'] ?? '' }}</td>
            </tr>
            <tr>
                <td colspan="3" class="hdr-cell hdr-cell-bot">
                    <table class="fill-line"><tr><td>
                        <span class="fill-label">TIN :</span> {{ $page['tin'] ?? '' }}
                    </td></tr></table>
                </td>
                <td colspan="3" class="hdr-cell hdr-cell-bot">
                    <table class="fill-line"><tr><td>
                        <span class="fill-label">Mode of Procurement :</span> {{ $page['mode_of_procurement'] ?? '' }}
                    </td></tr></table>
                </td>
            </tr>
            <tr>
                <td colspan="6" class="gentlemen">Gentlemen:</td>
            </tr>
            <tr>
                <td colspan="6" class="gentlemen-body">Please furnish this Office the following articles subject to the terms and conditions contained herein:</td>
            </tr>
            <tr>
                <td colspan="3" class="hdr-cell">
                    <table class="fill-line"><tr><td>
                        <span class="fill-label">Place of Delivery :</span> {{ $page['place_of_delivery'] ?? '' }}
                    </td></tr></table>
                </td>
                <td colspan="3" class="hdr-cell">
                    <table class="fill-line"><tr><td>
                        <span class="fill-label">Delivery Term :</span> {{ $page['delivery_term'] ?? '' }}
                    </td></tr></table>
                </td>
            </tr>
            <tr>
                <td colspan="3" class="hdr-cell hdr-cell-bot">
                    <table class="fill-line"><tr><td>
                        <span class="fill-label">Date of Delivery :</span> {{ $page['date_of_delivery'] ?? '' }}
                    </td></tr></table>
                </td>
                <td colspan="3" class="hdr-cell hdr-cell-bot">
                    <table class="fill-line"><tr><td>
                        <span class="fill-label">Payment Term :</span> {{ $page['payment_term'] ?? '' }}
                    </td></tr></table>
                </td>
            </tr>
            <tr>
                <th class="stock-head">Stock/ Property<br>No.</th>
                <th class="col-head">Unit</th>
                <th class="col-head">Description</th>
                <th class="col-head">Quantity</th>
                <th class="col-head">Unit Cost</th>
                <th class="col-head">Amount</th>
            </tr>
            @foreach ($lines as $line)
                <tr>
                    <td class="line-cell cell-center">{{ $line['stock_no'] ?? '' }}</td>
                    <td class="line-cell cell-center">{{ $line['unit'] ?? '' }}</td>
                    <td class="line-cell">{{ $line['description'] ?? '' }}</td>
                    <td class="line-cell cell-center">{{ $line['quantity'] ?? '' }}</td>
                    <td class="line-cell cell-right">{{ $line['unit_cost'] ?? '' }}</td>
                    <td class="line-cell cell-right">{{ $line['amount'] ?? '' }}</td>
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
                <td colspan="5" class="words-label">(Total Amount in Words) {{ $totalWords }}</td>
                <td class="cell-right hdr-bold">{{ $totalFormatted }}</td>
            </tr>
            <tr>
                <td colspan="6" class="penalty">
                    In case of failure to make the full delivery within the time specified above, a penalty of one-tenth (1/10) of one percent for every day of delay shall be imposed on the undelivered item/s.
                </td>
            </tr>
            <tr>
                <td colspan="3" class="sig-head">Conforme:</td>
                <td colspan="3" class="sig-head">Very truly yours,</td>
            </tr>
            <tr>
                <td colspan="3" class="sig-line-cell">
                    {{ filled($page['supplier'] ?? null) ? ($page['supplier'] ?? '') : '__________________________' }}
                </td>
                <td colspan="3" class="sig-line-cell">________________________________</td>
            </tr>
            <tr>
                <td colspan="3" class="sig-caption">Signature over Printed Name of Supplier</td>
                <td colspan="3" class="sig-caption">Signature over Printed Name of Authorized Official</td>
            </tr>
            <tr>
                <td colspan="3" class="sig-line-cell">___________________________</td>
                <td colspan="3" class="sig-line-cell">_____________________________</td>
            </tr>
            <tr>
                <td colspan="3" class="sig-date-caption">Date</td>
                <td colspan="3" class="sig-date-caption">Designation</td>
            </tr>
            <tr>
                <td colspan="3" class="acct-top acct-blank">Fund Cluster : ___________________________________</td>
                <td colspan="3" class="acct-top acct-blank">ORS/BURS No. : ______________________</td>
            </tr>
            <tr>
                <td colspan="3" class="acct-mid acct-blank">Funds Available : _________________________________</td>
                <td colspan="3" class="acct-mid acct-blank">Date of the ORS/BURS: _______________</td>
            </tr>
            <tr>
                <td colspan="3" class="acct-bot">
                    __________________________
                    <span class="acct-sign-caption">Signature over Printed Name of Chief Accountant/Head of Accounting Division/Unit</span>
                </td>
                <td colspan="3" class="acct-bot acct-blank" style="text-align: left; vertical-align: top;">
                    Amount : ____________________________
                </td>
            </tr>
        </table>
    </div>
@endforeach
</body>
</html>
