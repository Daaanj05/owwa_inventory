@php
    /** @var array{title: string, header: array<string, string|null>, columns: array<string, array{label: string, tooltip?: string}|string>, rows: array<int, array<string, mixed>>} $ledger */
    $header = $ledger['header'];
    $columns = $ledger['columns'];
    $rows = $ledger['rows'];
    $isConsumable = isset($header['stock_no']);
    $numericKeys = ['receipt_qty', 'issue_qty', 'balance', 'days_to_consume'];
@endphp

<div
    class="owwa-stock-ledger-modal"
    x-data="{
        tipOpen: false,
        tipText: '',
        tipStyle: '',
        showTip(detail) {
            const el = detail?.target;
            if (! el || ! detail?.text) {
                return;
            }

            this.tipText = detail.text;
            this.tipOpen = true;
            this.$nextTick(() => {
                const rect = el.getBoundingClientRect();
                const left = rect.left + (rect.width / 2);
                const top = rect.top - 8;
                this.tipStyle = `left:${left}px;top:${top}px;`;
            });
        },
        hideTip() {
            this.tipOpen = false;
        },
    }"
    x-init="
        const wrap = $el.querySelector('.owwa-stock-ledger-table-wrap');
        if (wrap) {
            wrap.addEventListener('scroll', () => hideTip(), { passive: true });
        }
    "
    @owwa-header-tooltip-show="showTip($event.detail)"
    @owwa-header-tooltip-hide="hideTip()"
>
    <dl class="owwa-stock-ledger-header">
        <div class="owwa-stock-ledger-header-item">
            <dt>Entity</dt>
            <dd>{{ $header['entity_name'] }}</dd>
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
                <dt>{{ $header['asset_identifier_label'] ?? 'Property No.' }}</dt>
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
                    @foreach ($columns as $key => $column)
                        <th class="{{ in_array($key, $numericKeys, true) ? 'owwa-num' : '' }}">
                            @include('filament.partials.owwa-column-header', ['label' => $column])
                        </th>
                    @endforeach
                </tr>
            </thead>
            <tbody>
                @forelse ($rows as $row)
                    <tr>
                        @foreach ($columns as $key => $column)
                            <td class="owwa-ledger-data-cell {{ in_array($key, $numericKeys, true) ? 'owwa-num' : '' }}">
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

    <template x-teleport="body">
        <div
            x-cloak
            x-show="tipOpen"
            x-bind:style="tipStyle"
            class="owwa-th-tooltip--portal"
            x-text="tipText"
            role="tooltip"
        ></div>
    </template>
</div>
