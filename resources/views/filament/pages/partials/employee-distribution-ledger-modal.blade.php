@php
    /** @var array{header: array<string, string|null>, columns: array<string, array{label: string, tooltip?: string}|string>, rows: array<int, array<string, mixed>>, paginator: \Illuminate\Contracts\Pagination\LengthAwarePaginator} $ledger */
    $header = $ledger['header'];
    $columns = $ledger['columns'];
    $rows = $ledger['rows'];
    $paginator = $ledger['paginator'];
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
            <dt>Item</dt>
            <dd>{{ $header['item_name'] }}</dd>
        </div>
        <div class="owwa-stock-ledger-header-item">
            <dt>Category</dt>
            <dd>{{ $header['category_name'] }}</dd>
        </div>
        @if (filled($header['stock_no'] ?? null))
            <div class="owwa-stock-ledger-header-item">
                <dt>Stock No.</dt>
                <dd>{{ $header['stock_no'] }}</dd>
            </div>
        @endif
        <div class="owwa-stock-ledger-header-item">
            <dt>Total on hand</dt>
            <dd>{{ number_format((int) ($header['total_on_hand'] ?? 0)) }}</dd>
        </div>
    </dl>

    <div class="owwa-table-wrap owwa-stock-ledger-table-wrap">
        <table class="owwa-data-table">
            <thead>
                <tr>
                    @foreach ($columns as $key => $column)
                        <th class="{{ in_array($key, ['quantity', 'balance'], true) ? 'owwa-num' : '' }} {{ $key === 'action' ? 'owwa-status' : '' }}">
                            @include('filament.partials.owwa-column-header', ['label' => $column])
                        </th>
                    @endforeach
                </tr>
            </thead>
            <tbody>
                @forelse ($rows as $row)
                    <tr>
                        @foreach ($columns as $key => $column)
                            <td class="{{ in_array($key, ['quantity', 'balance'], true) ? 'owwa-num' : '' }} {{ $key === 'action' ? 'owwa-status' : '' }}">
                                @php
                                    $value = $row[$key] ?? null;
                                @endphp
                                @if ($key === 'action' && filled($row['property_action_url'] ?? null))
                                    <a
                                        href="{{ $row['property_action_url'] }}"
                                        class="text-sm font-medium text-primary-600 hover:text-primary-500 dark:text-primary-400"
                                    >
                                        {{ $value ?? 'Start property action' }}
                                    </a>
                                @elseif (in_array($key, ['quantity', 'balance'], true) && $value !== null && $value !== '')
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

    @if ($paginator->lastPage() > 1)
        {{ $paginator->links('vendor.pagination.owwa', ['pageName' => 'ledgerPage']) }}
    @endif

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
