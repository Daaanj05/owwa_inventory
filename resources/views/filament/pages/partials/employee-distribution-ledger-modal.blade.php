@php
    /** @var array{header: array<string, string|null>, columns: array<string, string>, rows: array<int, array<string, mixed>>} $ledger */
    $header = $ledger['header'];
    $columns = $ledger['columns'];
    $rows = $ledger['rows'];
@endphp

<div class="owwa-stock-ledger-modal">
    <dl class="owwa-stock-ledger-header">
        <div class="owwa-stock-ledger-header-item">
            <dt>Item</dt>
            <dd>{{ $header['item_name'] }}</dd>
        </div>
        <div class="owwa-stock-ledger-header-item">
            <dt>Category</dt>
            <dd>{{ $header['category_name'] }}</dd>
        </div>
        <div class="owwa-stock-ledger-header-item">
            <dt>Total on hand</dt>
            <dd>{{ number_format((int) ($header['total_on_hand'] ?? 0)) }}</dd>
        </div>
    </dl>

    <div class="owwa-table-wrap owwa-stock-ledger-table-wrap">
        <table class="owwa-data-table">
            <thead>
                <tr>
                    @foreach ($columns as $key => $label)
                        <th class="{{ in_array($key, ['quantity', 'balance'], true) ? 'owwa-num' : '' }}">
                            {{ $label }}
                        </th>
                    @endforeach
                </tr>
            </thead>
            <tbody>
                @forelse ($rows as $row)
                    <tr>
                        @foreach ($columns as $key => $label)
                            <td class="{{ in_array($key, ['quantity', 'balance'], true) ? 'owwa-num' : '' }}">
                                @php
                                    $value = $row[$key] ?? null;
                                @endphp
                                @if (in_array($key, ['quantity', 'balance'], true) && $value !== null && $value !== '')
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
                                <p class="owwa-empty-title">No distributions recorded</p>
                                <p class="owwa-empty-desc">No distribution history for this item.</p>
                            </div>
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
