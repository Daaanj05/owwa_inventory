@php
    use App\Support\SemiExpendableUsefulLife;

    /** @var array{header: array<string, string|null>, columns: array<string, string>, rows: array<int, array<string, mixed>>, property_units: array<int, array<string, mixed>>, show_property_units: bool, paginator: \Illuminate\Contracts\Pagination\LengthAwarePaginator} $ledger */
    $header = $ledger['header'];
    $columns = $ledger['columns'];
    $rows = $ledger['rows'];
    $paginator = $ledger['paginator'];
    $propertyUnits = $ledger['property_units'] ?? [];
    $showPropertyUnits = (bool) ($ledger['show_property_units'] ?? false);
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
            <dt>Balance on hand</dt>
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
                                <p class="owwa-empty-title">No history yet</p>
                                <p class="owwa-empty-desc">Received and distributed movements will appear here.</p>
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

    @if ($showPropertyUnits && count($propertyUnits) > 0)
        <div class="owwa-stock-ledger-property-units" style="margin-top: 1.5rem;">
            <h3 class="text-sm font-semibold" style="margin-bottom: 0.75rem;">Tagged property units</h3>
            <div class="owwa-table-wrap">
                <table class="owwa-data-table">
                    <thead>
                        <tr>
                            <th>Property no.</th>
                            <th>Control ref.</th>
                            <th>Accountable</th>
                            <th>Issued</th>
                            <th>EUL</th>
                            <th class="owwa-status">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($propertyUnits as $unit)
                            <tr>
                                <td class="owwa-cell-primary">{{ $unit['property_number'] }}</td>
                                <td class="owwa-cell-muted">{{ $unit['reference_code'] }}</td>
                                <td class="owwa-cell-muted">{{ $unit['issued_to'] }}</td>
                                <td class="owwa-cell-muted">{{ $unit['issuance_date'] }}</td>
                                <td class="owwa-status">
                                    @if(($unit['eul_status'] ?? null) === SemiExpendableUsefulLife::STATUS_EXPIRED)
                                        <span class="owwa-status-badge owwa-status-low">Expired</span>
                                    @elseif(($unit['eul_status'] ?? null) === SemiExpendableUsefulLife::STATUS_NEARING)
                                        <span class="owwa-status-badge owwa-status-low">Nearing</span>
                                    @elseif(($unit['category_slug'] ?? null) === 'semi_expendable')
                                        <span class="owwa-status-badge owwa-status-ok">Active</span>
                                    @else
                                        <span class="owwa-cell-muted">N/A</span>
                                    @endif
                                </td>
                                <td class="owwa-status">
                                    @if($this->showPropertyActionCta($unit))
                                        <a
                                            href="{{ $this->propertyActionUrl((int) $unit['issuance_id'], $this->suggestedPropertyActionType($unit)) }}"
                                            class="owwa-inline-action"
                                        >
                                            Start property action
                                        </a>
                                    @else
                                        <span class="owwa-cell-muted">—</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endif
</div>
