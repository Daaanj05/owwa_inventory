{{-- Appendix 66 RPCI lookalike (Fast DomPDF). Consumable physical count only. --}}
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Report on the Physical Count of Inventories</title>
    <style>
        @page {
            size: A4 landscape;
            /* Word Narrow on the top and sides. Bottom margin is the footer band; content cannot enter it. */
            margin: 12.7mm 12.7mm 16mm 12.7mm;
        }
        * {
            box-sizing: border-box;
        }
        body {
            font-family: "Times New Roman", Times, serif;
            font-size: 9px;
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
            width: 100%;
            text-align: right;
            font-size: 10px;
            line-height: 10mm;
            font-family: "Times New Roman", Times, serif;
            color: #000;
        }
        .brand-header-wrap {
            width: 100%;
            text-align: center;
            margin: 0 0 6px 0;
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
        .inventory-type {
            text-align: center;
            font-size: 11px;
            font-weight: bold;
            margin: 4px 0 0 0;
            text-decoration: underline;
            min-height: 14px;
        }
        .inventory-type-label {
            text-align: center;
            font-size: 9px;
            margin: 0 0 4px 0;
        }
        .as-at {
            text-align: center;
            font-size: 10px;
            margin: 0 0 6px 0;
        }
        .meta-line {
            font-size: 10px;
            margin: 0 0 4px 0;
            text-align: left;
        }
        .meta-line .value {
            display: inline-block;
            border-bottom: 1px solid #000;
            min-width: 40%;
            padding: 0 4px 1px 4px;
            font-weight: bold;
        }
        .accountable {
            font-size: 9px;
            margin: 0 0 8px 0;
            text-align: left;
            line-height: 1.35;
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
            font-size: 8px;
            font-weight: normal;
            text-align: center;
        }
        .col-article { width: 9%; }
        .col-desc { width: 18%; }
        .col-stock { width: 10%; }
        .col-uom { width: 8%; }
        .col-uval { width: 9%; }
        .col-bal { width: 8%; }
        .col-onhand { width: 8%; }
        .col-sqty { width: 7%; }
        .col-sval { width: 9%; }
        .col-remarks { width: 14%; }
        .form-table .col-head {
            font-weight: bold;
            text-align: center;
            vertical-align: middle;
            line-height: 1.15;
        }
        .form-table .sub-head {
            font-weight: bold;
            font-size: 7.5px;
            text-align: center;
            height: 14px;
        }
        .form-table .line-cell {
            height: 13px;
            vertical-align: middle;
        }
        .form-table .cell-left {
            text-align: left;
        }
        .form-table .cell-right {
            text-align: right;
        }
        .sig-wrap {
            width: 100%;
            border-collapse: collapse;
            margin-top: 10px;
            table-layout: fixed;
        }
        .sig-wrap td {
            border: none;
            vertical-align: top;
            width: 33.33%;
            padding: 0 8px;
            font-size: 9px;
        }
        .sig-label {
            text-align: left;
            font-weight: bold;
            margin-bottom: 18px;
        }
        /* Name sits above the underline (Excel: printed name, then line, then caption). */
        .sig-name {
            text-align: center;
            font-weight: bold;
            border-bottom: 1px solid #000;
            padding: 0 4px 2px 4px;
            min-height: 16px;
            margin: 0 6px;
        }
        .sig-caption {
            text-align: center;
            font-size: 8px;
            margin-top: 2px;
            line-height: 1.2;
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

    $formatMoney = static function (mixed $value): string {
        if ($value === null || $value === '') {
            return '';
        }

        return number_format((float) $value, 2, '.', ',');
    };
@endphp
@if ($showGeneratedOn)
    <div class="generated-on">Generated on {{ $generatedOn }}</div>
@endif
@foreach ($pages as $page)
    @php
        $lines = $page['lines'] ?? [];
        $detailMin = (int) ($page['min_detail_rows'] ?? $minRows);
        $padCount = max(0, $detailMin - count($lines));
        $inventoryType = (string) ($page['inventory_type'] ?? '');
        $countDate = (string) ($page['count_date'] ?? '');
        $fundCluster = (string) ($page['fund_cluster'] ?? '');
        $accountableClause = (string) ($page['accountable_officer_clause'] ?? '');
        $certifiedBy = (string) ($page['certified_by'] ?? '');
        $approvedBy = (string) ($page['approved_by'] ?? '');
        $verifiedBy = (string) ($page['verified_by'] ?? '');
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
                        <div class="brand-title">REPORT ON THE PHYSICAL COUNT OF INVENTORIES</div>
                    </td>
                    <td class="brand-logo brand-logo--right">
                        @if (filled($bagongPilipinasLogoSrc))
                            <img src="{{ $bagongPilipinasLogoSrc }}" alt="Bagong Pilipinas">
                        @endif
                    </td>
                </tr>
            </table>
        </div>

        <div class="inventory-type">{!! filled($inventoryType) ? e($inventoryType) : '&nbsp;' !!}</div>
        <div class="inventory-type-label">(Type of Inventory Item)</div>
        <div class="as-at">As at {{ filled($countDate) ? $countDate : '________________________' }}</div>

        <div class="meta-line">Fund Cluster : <span class="value">{{ $fundCluster }}&nbsp;</span></div>
        <div class="accountable">
            For which {{ filled($accountableClause) ? $accountableClause : '________________ (Name of Accountable Officer), ________________ (Official Designation), ________________ (Entity Name) is accountable, having assumed such accountability on ________________ (Date of Assumption).' }}
        </div>

        <table class="form-table">
            <colgroup>
                <col class="col-article">
                <col class="col-desc">
                <col class="col-stock">
                <col class="col-uom">
                <col class="col-uval">
                <col class="col-bal">
                <col class="col-onhand">
                <col class="col-sqty">
                <col class="col-sval">
                <col class="col-remarks">
            </colgroup>
            <tr>
                <th rowspan="2" class="col-head">Article</th>
                <th rowspan="2" class="col-head">Description</th>
                <th rowspan="2" class="col-head">Stock Number</th>
                <th rowspan="2" class="col-head">Unit of Measure</th>
                <th rowspan="2" class="col-head">Unit Value</th>
                <th class="col-head">Balance Per Card</th>
                <th class="col-head">On Hand Per Count</th>
                <th colspan="2" class="col-head">Shortage/Overage</th>
                <th rowspan="2" class="col-head">Remarks</th>
            </tr>
            <tr>
                <th class="sub-head">(Quantity)</th>
                <th class="sub-head">(Quantity)</th>
                <th class="sub-head">Quantity</th>
                <th class="sub-head">Value</th>
            </tr>
            @foreach ($lines as $line)
                <tr>
                    <td class="line-cell cell-left">{{ $line['article'] ?? '' }}</td>
                    <td class="line-cell cell-left">{{ $line['description'] ?? '' }}</td>
                    <td class="line-cell">{{ $line['stock_number'] ?? '' }}</td>
                    <td class="line-cell">{{ $line['unit_of_measure'] ?? '' }}</td>
                    <td class="line-cell cell-right">{{ $formatMoney($line['unit_value'] ?? null) }}</td>
                    <td class="line-cell">{{ $line['balance_per_card'] ?? '' }}</td>
                    <td class="line-cell">{{ $line['on_hand_count'] ?? '' }}</td>
                    <td class="line-cell">{{ $line['shortage_qty'] ?? '' }}</td>
                    <td class="line-cell cell-right">{{ $formatMoney($line['shortage_value'] ?? null) }}</td>
                    <td class="line-cell cell-left">{{ $line['remarks'] ?? '' }}</td>
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
                    <td class="line-cell"></td>
                    <td class="line-cell"></td>
                </tr>
            @endfor
        </table>

        <table class="sig-wrap">
            <tr>
                <td>
                    <div class="sig-label">Certified Correct by:</div>
                    <div class="sig-name">{!! filled($certifiedBy) ? e($certifiedBy) : '&nbsp;' !!}</div>
                    <div class="sig-caption">Signature over Printed Name of Inventory Committee Chair and Members</div>
                </td>
                <td>
                    <div class="sig-label">Approved by:</div>
                    <div class="sig-name">{!! filled($approvedBy) ? e($approvedBy) : '&nbsp;' !!}</div>
                    <div class="sig-caption">Signature over Printed Name of Head of Agency/Entity or Authorized Representative</div>
                </td>
                <td>
                    <div class="sig-label">Verified by:</div>
                    <div class="sig-name">{!! filled($verifiedBy) ? e($verifiedBy) : '&nbsp;' !!}</div>
                    <div class="sig-caption">Signature over Printed Name of COA Representative</div>
                </td>
            </tr>
        </table>
    </div>
@endforeach
</body>
</html>
