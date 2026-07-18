<div class="owwa-form-card">
    @include('owwa.partials.form-brand-header', [
        'appendix' => $card['appendix'] ?? 'Annex A.1',
        'title' => $card['title'] ?? 'SEMI-EXPENDABLE PROPERTY CARD',
    ])

    <table class="owwa-form-header" style="margin-top: 8px;">
        <tr>
            <td class="field-label">Entity Name:</td>
            <td class="field-value">{{ $card['header']['entity_name'] ?? '' }}</td>
            <td class="field-label-right">Fund Cluster:</td>
            <td class="field-value">{{ $card['header']['fund_cluster'] ?? '' }}</td>
        </tr>
        <tr>
            <td class="field-label">Semi-expendable Property:</td>
            <td class="field-value">{{ $card['header']['property_type'] ?? '' }}</td>
            <td class="field-label-right">Semi-expendable Property Number:</td>
            <td class="field-value">{{ $card['header']['property_number'] ?? '' }}</td>
        </tr>
        <tr>
            <td class="field-label">Description:</td>
            <td class="field-span" colspan="3">{{ $card['header']['description'] ?? $card['header']['item_name'] ?? '' }}</td>
        </tr>
    </table>

    <table class="owwa-form-ledger">
        <thead>
            <tr>
                <th rowspan="2" style="width: 7%;">Date</th>
                <th rowspan="2" style="width: 10%;">Reference</th>
                <th colspan="3" style="width: 22%;">Receipt</th>
                <th style="width: 6%;">Receipt</th>
                <th colspan="3" style="width: 24%;">Issue / Transfer / Disposal</th>
                <th style="width: 7%;">Balance</th>
                <th rowspan="2" style="width: 8%;">Amount</th>
                <th rowspan="2" style="width: 10%;">Remarks</th>
            </tr>
            <tr class="subhead">
                <th>Qty.</th>
                <th>Unit Cost</th>
                <th>Total Cost</th>
                <th>Qty.</th>
                <th>Item No.</th>
                <th>Qty.</th>
                <th>Office / Officer</th>
                <th>Qty.</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($card['rows'] as $row)
                <tr>
                    <td class="c">{{ $row['date'] ?? '' }}</td>
                    <td class="l">{{ $row['reference'] ?? '' }}</td>
                    <td class="r">{{ $row['receipt_qty'] !== null ? number_format((int) $row['receipt_qty']) : '' }}</td>
                    <td class="r">
                        {{ isset($row['unit_cost']) && $row['unit_cost'] !== null ? number_format((float) $row['unit_cost'], 2) : '' }}
                    </td>
                    <td class="r">
                        {{ isset($row['total_cost']) && $row['total_cost'] !== null ? number_format((float) $row['total_cost'], 2) : '' }}
                    </td>
                    <td class="r">{{ $row['receipt_qty'] !== null ? number_format((int) $row['receipt_qty']) : '' }}</td>
                    <td class="l">{{ $row['item_no'] ?? '' }}</td>
                    <td class="r">{{ $row['issue_qty'] !== null ? number_format((int) $row['issue_qty']) : '' }}</td>
                    <td class="l">{{ $row['office_officer'] ?? '' }}</td>
                    <td class="r">{{ number_format((int) ($row['balance'] ?? 0)) }}</td>
                    <td class="r">
                        {{ isset($row['amount']) && $row['amount'] !== null ? number_format((float) $row['amount'], 2) : '' }}
                    </td>
                    <td class="l">{{ $row['remarks'] ?? '' }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="12" class="owwa-form-empty">No transactions recorded.</td>
                </tr>
            @endforelse
        </tbody>
    </table>
</div>
