<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Annex A.4 Registry</title>
    @include('owwa.partials.card-print-styles')
    <style>
        .owwa-form-ledger th,
        .owwa-form-ledger td {
            font-size: 7px;
            padding: 2px 3px;
        }
    </style>
</head>
<body>
@include('owwa.partials.pdf-computer-generated-footer')

@foreach ($cards as $index => $card)
    <div class="owwa-form-card">
        @include('owwa.partials.form-brand-header', [
            'appendix' => $card['appendix'] ?? 'Annex A.4',
            'title' => $card['title'] ?? 'REGISTRY OF SEMI-EXPENDABLE PROPERTY ISSUED',
        ])

        <table class="owwa-form-header" style="margin-top: 6px;">
            <tr>
                <td class="field-label">Entity Name:</td>
                <td class="field-value">{{ $card['header']['entity_name'] ?? '' }}</td>
                <td class="field-label-right">Fund Cluster:</td>
                <td class="field-value">{{ $card['header']['fund_cluster'] ?? '' }}</td>
            </tr>
            <tr>
                <td class="field-label">Semi-Expendable Property:</td>
                <td class="field-span" colspan="3">{{ $card['header']['property_type'] ?? $card['sheetName'] ?? '' }}</td>
            </tr>
        </table>

        <table class="owwa-form-ledger">
            <thead>
                <tr>
                    <th style="width: 7%;">Date</th>
                    <th style="width: 9%;">Reference</th>
                    <th style="width: 10%;">Property No.</th>
                    <th style="width: 14%;">Description</th>
                    <th style="width: 6%;">Useful Life</th>
                    <th style="width: 5%;">Issued Qty</th>
                    <th style="width: 9%;">Issued Office</th>
                    <th style="width: 5%;">Returned Qty</th>
                    <th style="width: 8%;">Returned Office</th>
                    <th style="width: 5%;">Reissued Qty</th>
                    <th style="width: 8%;">Reissued Office</th>
                    <th style="width: 5%;">Disposed Qty</th>
                    <th style="width: 5%;">Balance</th>
                    <th style="width: 6%;">Amount</th>
                    <th style="width: 8%;">Remarks</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($card['entries'] as $row)
                    <tr>
                        <td class="c">{{ $row['date'] ?? '' }}</td>
                        <td class="l">{{ $row['reference'] ?? '' }}</td>
                        <td class="l">{{ $row['property_number'] ?? '' }}</td>
                        <td class="l">{{ $row['description'] ?? '' }}</td>
                        <td class="c">{{ $row['estimated_useful_life'] ?? $row['useful_life'] ?? '' }}</td>
                        <td class="r">{{ filled($row['issued_qty'] ?? null) ? number_format((int) $row['issued_qty']) : '' }}</td>
                        <td class="l">{{ $row['issued_office'] ?? '' }}</td>
                        <td class="r">{{ filled($row['returned_qty'] ?? null) ? number_format((int) $row['returned_qty']) : '' }}</td>
                        <td class="l">{{ $row['returned_office'] ?? '' }}</td>
                        <td class="r">{{ filled($row['reissued_qty'] ?? null) ? number_format((int) $row['reissued_qty']) : '' }}</td>
                        <td class="l">{{ $row['reissued_office'] ?? '' }}</td>
                        <td class="r">{{ filled($row['disposed_qty'] ?? null) ? number_format((int) $row['disposed_qty']) : '' }}</td>
                        <td class="r">{{ number_format((int) ($row['balance_qty'] ?? $row['balance'] ?? 0)) }}</td>
                        <td class="r">
                            {{ isset($row['amount']) && $row['amount'] !== null && $row['amount'] !== ''
                                ? number_format((float) $row['amount'], 2)
                                : '' }}
                        </td>
                        <td class="l">{{ $row['remarks'] ?? '' }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="15" class="owwa-form-empty">No registry rows.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    @if (! $loop->last)
        <div class="owwa-pdf-page-break"></div>
    @endif
@endforeach
</body>
</html>
