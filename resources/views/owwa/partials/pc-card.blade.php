<div class="owwa-form-card">
    @include('owwa.partials.form-brand-header', [
        'appendix' => $card['appendix'] ?? 'Appendix 69',
        'title' => $card['title'] ?? 'PROPERTY CARD',
    ])

    <table class="owwa-form-header">
        <tr>
            <td class="field-label">Entity Name:</td>
            <td class="field-value">{{ $card['header']['entity_name'] ?? '' }}</td>
            <td class="field-label-right">Fund Cluster:</td>
            <td class="field-value">{{ $card['header']['fund_cluster'] ?? '' }}</td>
        </tr>
        <tr>
            <td class="field-label">Property, Plant and Equipment:</td>
            <td class="field-value">{{ $card['header']['property_type'] ?? $card['header']['item_name'] ?? '' }}</td>
            <td class="field-label-right">Property Number:</td>
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
                <th rowspan="2" style="width: 8%;">Date</th>
                <th rowspan="2" style="width: 14%;">Reference / PAR No.</th>
                <th style="width: 8%;">Receipt</th>
                <th colspan="2" style="width: 24%;">Issue / Transfer / Disposal</th>
                <th style="width: 8%;">Balance</th>
                <th rowspan="2" style="width: 10%;">Amount</th>
                <th rowspan="2" style="width: 14%;">Remarks</th>
            </tr>
            <tr class="subhead">
                <th>Qty.</th>
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
                    <td colspan="8" class="owwa-form-empty">No transactions recorded.</td>
                </tr>
            @endforelse
        </tbody>
    </table>
</div>
