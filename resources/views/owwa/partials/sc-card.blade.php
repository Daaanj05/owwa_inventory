<div class="owwa-form-card">
    @include('owwa.partials.form-brand-header', [
        'appendix' => $card['appendix'] ?? 'Appendix 58',
        'title' => $card['title'] ?? 'STOCK CARD',
    ])

    <table class="owwa-form-header">
        <tr>
            <td class="field-label">Entity Name:</td>
            <td class="field-value">{{ $card['header']['entity_name'] ?? '' }}</td>
            <td class="field-label-right">Fund Cluster:</td>
            <td class="field-value">{{ $card['header']['fund_cluster'] ?? '' }}</td>
        </tr>
        <tr>
            <td class="field-label">Item:</td>
            <td class="field-value">{{ $card['header']['item_name'] ?? '' }}</td>
            <td class="field-label-right">Stock No.:</td>
            <td class="field-value">{{ $card['header']['stock_no'] ?? '' }}</td>
        </tr>
        <tr>
            <td class="field-label">Description:</td>
            <td class="field-span" colspan="3">{{ $card['header']['description'] ?? '' }}</td>
        </tr>
        <tr>
            <td class="field-label">Unit of Measurement:</td>
            <td class="field-value">{{ $card['header']['unit'] ?? '' }}</td>
            <td class="field-label-right">Re-order Point:</td>
            <td class="field-value">{{ $card['header']['reorder_level'] ?? '' }}</td>
        </tr>
    </table>

    <table class="owwa-form-ledger">
        <thead>
            <tr>
                <th rowspan="2" style="width: 9%;">Date</th>
                <th rowspan="2" style="width: 14%;">Reference</th>
                <th style="width: 10%;">Receipt</th>
                <th colspan="2" style="width: 22%;">Issue</th>
                <th style="width: 10%;">Balance</th>
                <th rowspan="2" style="width: 14%;">No. of Days to Consume</th>
            </tr>
            <tr class="subhead">
                <th>Qty.</th>
                <th>Qty.</th>
                <th>Office</th>
                <th>Qty.</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($card['rows'] as $row)
                <tr>
                    <td class="c">{{ $row['date'] !== '' ? $row['date'] : '' }}</td>
                    <td class="l">{{ $row['reference'] ?? '' }}</td>
                    <td class="r">{{ $row['receipt_qty'] !== null ? number_format((int) $row['receipt_qty']) : '' }}</td>
                    <td class="r">{{ $row['issue_qty'] !== null ? number_format((int) $row['issue_qty']) : '' }}</td>
                    <td class="l">{{ $row['issue_office'] ?? '' }}</td>
                    <td class="r">{{ number_format((int) ($row['balance'] ?? 0)) }}</td>
                    <td class="c">{{ $row['days_to_consume'] ?? '' }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="7" class="owwa-form-empty">No transactions recorded.</td>
                </tr>
            @endforelse
        </tbody>
    </table>
</div>
