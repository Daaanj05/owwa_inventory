@php
    use App\Support\SemiExpendableUsefulLife;

    /** @var array{header: array<string, string|null>, columns: array<string, string>, rows: array<int, array<string, mixed>>, custody_tab: string, category_slug: string|null} $ledger */
    /** @var \App\Filament\Pages\MyInventory|\App\Filament\Pages\EmployeeCustody|null $page */
    $header = $ledger['header'];
    $columns = $ledger['columns'];
    $rows = $ledger['rows'];
    $categorySlug = $ledger['category_slug'] ?? null;
    $readOnly = ! $page || ! method_exists($page, 'propertyActionUrl');
    $actionLabel = $page instanceof \App\Filament\Pages\EmployeeCustody
        ? 'Request action'
        : 'Start property action';
@endphp

<div class="owwa-stock-ledger-modal">
    <dl class="owwa-stock-ledger-header">
        <div class="owwa-stock-ledger-header-item">
            <dt>Item</dt>
            <dd>{{ $header['item_name'] }}</dd>
        </div>
        <div class="owwa-stock-ledger-header-item">
            <dt>Total qty</dt>
            <dd>{{ $header['total_quantity'] ?? '—' }}</dd>
        </div>
    </dl>

    <div class="owwa-table-wrap owwa-stock-ledger-table-wrap">
        <table class="owwa-data-table">
            <thead>
                <tr>
                    @foreach ($columns as $key => $label)
                        <th>{{ $label }}</th>
                    @endforeach
                    <th>Custody history</th>
                    @if (! $readOnly)
                        <th>Action</th>
                    @endif
                </tr>
            </thead>
            <tbody>
                @forelse ($rows as $row)
                    <tr>
                        <td class="owwa-cell-primary">{{ $row['property_number'] }}</td>
                        <td class="owwa-cell-muted">{{ $row['issued_date'] }}</td>
                        <td class="owwa-cell-muted">
                            @if ($categorySlug === 'semi_expendable')
                                {{ $row['useful_life'] }}
                            @else
                                <span class="owwa-cell-muted">N/A</span>
                            @endif
                        </td>
                        <td class="owwa-cell-muted">
                            @if ($categorySlug === 'semi_expendable')
                                {{ $row['expires_at'] }}
                            @else
                                <span class="owwa-cell-muted">N/A</span>
                            @endif
                        </td>
                        <td class="owwa-status">
                            @if ($categorySlug === 'semi_expendable')
                                @php $eulStatus = $row['eul_status'] ?? null; @endphp
                                @if ($eulStatus === SemiExpendableUsefulLife::STATUS_EXPIRED)
                                    <span class="owwa-status-badge owwa-status-low">Expired</span>
                                @elseif ($eulStatus === SemiExpendableUsefulLife::STATUS_NEARING)
                                    <span class="owwa-status-badge owwa-status-low">Nearing</span>
                                @elseif ($eulStatus === SemiExpendableUsefulLife::STATUS_OK)
                                    <span class="owwa-status-badge owwa-status-ok">Active</span>
                                @else
                                    <span class="owwa-cell-muted">—</span>
                                @endif
                            @else
                                <span class="owwa-cell-muted">N/A</span>
                            @endif
                        </td>
                        <td>
                            @if ($page && method_exists($page, 'openPropertyCustodyLedger'))
                                <button
                                    type="button"
                                    wire:click="openPropertyCustodyLedger({{ (int) $row['issuance_id'] }})"
                                    class="text-sm font-medium text-primary-600 hover:text-primary-500 dark:text-primary-400"
                                >
                                    View
                                </button>
                            @else
                                <span class="owwa-cell-muted">—</span>
                            @endif
                        </td>
                        @if (! $readOnly)
                            <td>
                                @if (($row['show_property_action'] ?? false) && filled($row['property_action_url'] ?? null))
                                    <a
                                        href="{{ $row['property_action_url'] }}"
                                        class="owwa-inline-action"
                                    >
                                        {{ $actionLabel }}
                                    </a>
                                @else
                                    <span class="owwa-cell-muted">—</span>
                                @endif
                            </td>
                        @endif
                    </tr>
                @empty
                    <tr>
                        <td colspan="{{ count($columns) + ($readOnly ? 1 : 2) }}">
                            <div class="owwa-empty">
                                <p class="owwa-empty-title">No property units</p>
                                <p class="owwa-empty-desc">Issued units for this item will appear here.</p>
                            </div>
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
