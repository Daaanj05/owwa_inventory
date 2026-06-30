@php
    /** @var array{title: string, header: array<string, string|null>, columns: array<string, string>, rows: array<int, array<string, mixed>>} $ledger */
    $header = $ledger['header'];
    $columns = $ledger['columns'];
    $rows = $ledger['rows'];
    $isConsumable = isset($header['stock_no']);
@endphp

<div class="owwa-stock-ledger-modal">
    <dl class="owwa-stock-ledger-header">
        <div class="owwa-stock-ledger-header-item">
            <dt>Entity</dt>
            <dd>{{ $header['entity_name'] }}</dd>
        </div>
        <div class="owwa-stock-ledger-header-item">
            <dt>Fund cluster</dt>
            <dd>{{ $header['fund_cluster'] ?? '—' }}</dd>
        </div>
        <div class="owwa-stock-ledger-header-item">
            <dt>Item</dt>
            <dd>{{ $header['item_name'] }}</dd>
        </div>
        @if ($isConsumable)
            <div class="owwa-stock-ledger-header-item">
                <dt>Stock No.</dt>
                <dd>{{ $header['stock_no'] ?? '—' }}</dd>
            </div>
            <div class="owwa-stock-ledger-header-item">
                <dt>Unit</dt>
                <dd>{{ $header['unit'] ?? '—' }}</dd>
            </div>
            <div class="owwa-stock-ledger-header-item">
                <dt>Re-order point</dt>
                <dd>{{ $header['reorder_level'] ?? '0' }}</dd>
            </div>
        @else
            <div class="owwa-stock-ledger-header-item">
                <dt>Property No.</dt>
                <dd>{{ $header['property_number'] ?? '—' }}</dd>
            </div>
        @endif
        @if (filled($header['description'] ?? null))
            <div class="owwa-stock-ledger-header-item owwa-stock-ledger-header-item--wide">
                <dt>Description</dt>
                <dd>{{ $header['description'] }}</dd>
            </div>
        @endif
    </dl>

    <div class="owwa-table-wrap owwa-stock-ledger-table-wrap">
        <table class="owwa-data-table">
            <thead>
                <tr>
                    @foreach ($columns as $key => $label)
                        <th class="{{ in_array($key, ['receipt_qty', 'issue_qty', 'balance', 'days_to_consume'], true) ? 'owwa-num' : '' }}">
                            {{ $label }}
                        </th>
                    @endforeach
                </tr>
            </thead>
            <tbody>
                @forelse ($rows as $row)
                    <tr>
                        @foreach ($columns as $key => $label)
                            <td class="{{ in_array($key, ['receipt_qty', 'issue_qty', 'balance', 'days_to_consume'], true) ? 'owwa-num' : '' }}">
                                @php
                                    $value = $row[$key] ?? null;
                                @endphp
                                @if (in_array($key, ['receipt_qty', 'issue_qty', 'balance'], true) && $value !== null && $value !== '')
                                    {{ number_format((int) $value) }}
                                @elseif (filled($value))
                                    {{ $value }}
                                @else
                                    <span class="owwa-cell-muted">—</span>
                                @endif
                            </td>
                        @endforeach
                    </tr>
                @empty
                    <tr>
                        <td colspan="{{ count($columns) }}">
                            <div class="owwa-empty">
                                <p class="owwa-empty-title">No movements recorded</p>
                                <p class="owwa-empty-desc">No movements recorded for this item at this office.</p>
                            </div>
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
