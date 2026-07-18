@php
    $tab = $this->tab;
    $categoryOptions = $this->getCategoryOptions();
    $isTransfersTab = $tab === \App\Filament\Pages\OfficePropertyRegister::TAB_TRANSFERS;
    $rows = $isTransfersTab ? $this->getTransferRows() : $this->getStockCardRows();
    $sortBy = $this->sortBy;
    $sortDir = $this->sortDir;
    $highlight = $this->highlight;
@endphp

<x-filament-panels::page>
    <div class="owwa-inventory-layout">
        <div class="owwa-search-wrap" style="display:flex;gap:0.5rem;flex-wrap:wrap;align-items:center;">
            <button
                type="button"
                wire:click="$set('tab', 'on_hand')"
                class="owwa-search-bar {{ $tab === 'on_hand' ? 'ring-2 ring-primary-500' : '' }}"
                style="max-width:10rem;cursor:pointer;"
            >
                On hand
            </button>
            <button
                type="button"
                wire:click="$set('tab', 'transfers')"
                class="owwa-search-bar {{ $tab === 'transfers' ? 'ring-2 ring-primary-500' : '' }}"
                style="max-width:10rem;cursor:pointer;"
            >
                Transfers
            </button>
        </div>

        <div class="owwa-search-wrap" style="display:flex;gap:0.75rem;flex-wrap:wrap;align-items:center;margin-top:0.75rem;">
            <select wire:model.live="category" class="owwa-search-bar" style="max-width:16rem;" aria-label="Item category">
                @foreach ($categoryOptions as $value => $label)
                    <option value="{{ $value }}">{{ $label }}</option>
                @endforeach
            </select>

            @if ($isTransfersTab)
                <select wire:model.live="direction" class="owwa-search-bar" style="max-width:10rem;" aria-label="Transfer direction">
                    <option value="all">All</option>
                    <option value="incoming">Incoming</option>
                    <option value="outgoing">Outgoing</option>
                </select>
            @endif

            <input
                type="text"
                wire:model.live.debounce.300ms="search"
                placeholder="{{ $isTransfersTab ? 'Search PTR, item, or office…' : 'Search item or category…' }}"
                class="owwa-search-bar"
                style="width: 18rem; max-width: 100%;"
            />
        </div>

        <div class="owwa-data-panel">
            <div class="owwa-data-panel-body">
                <div class="owwa-table-wrap">
                    @if ($isTransfersTab)
                        <table class="owwa-data-table">
                            <thead>
                                <tr>
                                    <th>Date</th>
                                    <th>PTR no.</th>
                                    <th>Item</th>
                                    <th class="owwa-num">Qty</th>
                                    <th>From office</th>
                                    <th>To office</th>
                                    <th>Direction</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse($rows as $row)
                                    @php
                                        $isHighlighted = filled($highlight) && $highlight === ($row->reference_code ?? null);
                                    @endphp
                                    <tr @if ($isHighlighted) class="bg-primary-50 dark:bg-primary-950/30" @endif>
                                        <td class="owwa-cell-muted">
                                            {{ $row->transfer_date?->format('M d, Y') ?? '—' }}
                                        </td>
                                        <td class="owwa-cell-primary">
                                            <button
                                                type="button"
                                                wire:click="openTransfer({{ (int) $row->id }})"
                                                class="text-left font-medium text-primary-600 hover:text-primary-500 dark:text-primary-400 dark:hover:text-primary-300"
                                            >
                                                {{ $row->reference_code }}
                                            </button>
                                        </td>
                                        <td class="owwa-cell-primary" title="{{ $row->item_name }}">{{ $row->item_name }}</td>
                                        <td class="owwa-num owwa-cell-primary">{{ number_format((int) $row->quantity) }}</td>
                                        <td class="owwa-cell-muted">{{ $row->from_office_name }}</td>
                                        <td class="owwa-cell-muted">{{ $row->to_office_name }}</td>
                                        <td class="owwa-cell-primary">{{ $row->direction }}</td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="7">
                                            <div class="owwa-empty">
                                                <p class="owwa-empty-title">No transfers yet</p>
                                                <p class="owwa-empty-desc">Transfers into or out of your office will appear here with From/To offices.</p>
                                            </div>
                                        </td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    @else
                        <table class="owwa-data-table">
                            <thead>
                                <tr>
                                    @php
                                        $columns = [
                                            'item_name' => 'Item',
                                            'category_name' => 'Category',
                                            'received' => 'Received',
                                            'distributed' => 'Distributed',
                                            'balance' => 'Balance',
                                        ];
                                    @endphp
                                    @foreach($columns as $col => $label)
                                        <th
                                            wire:click="sortByColumn('{{ $col }}')"
                                            style="cursor: pointer; user-select: none;"
                                            class="{{ in_array($col, ['received', 'distributed', 'balance'], true) ? 'owwa-num' : '' }}"
                                        >
                                            {{ $label }}
                                            @if($sortBy === $col)
                                                <span style="font-size: 0.65rem; margin-left: 0.25rem;">{{ $sortDir === 'asc' ? '▲' : '▼' }}</span>
                                            @endif
                                        </th>
                                    @endforeach
                                </tr>
                            </thead>
                            <tbody>
                                @forelse($rows as $row)
                                    <tr>
                                        <td class="owwa-cell-primary">
                                            <button
                                                type="button"
                                                wire:click="openOfficeStockLedger({{ (int) $row->item_id }})"
                                                class="text-left font-medium text-primary-600 hover:text-primary-500 dark:text-primary-400 dark:hover:text-primary-300"
                                                title="{{ $row->item_name }}"
                                            >
                                                {{ $row->item_name }}
                                            </button>
                                        </td>
                                        <td class="owwa-cell-muted">{{ $row->category_name }}</td>
                                        <td class="owwa-num owwa-cell-primary">{{ number_format((int) $row->received) }}</td>
                                        <td class="owwa-num owwa-cell-muted">{{ number_format((int) $row->distributed) }}</td>
                                        <td class="owwa-num owwa-cell-primary">{{ number_format((int) $row->balance) }}</td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="5">
                                            <div class="owwa-empty">
                                                <p class="owwa-empty-title">No stock on record</p>
                                                <p class="owwa-empty-desc">Items received from SC or distributed from your office will appear here.</p>
                                            </div>
                                        </td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    @endif
                </div>
            </div>
        </div>

        <div class="mt-4">
            {{ $rows->links('vendor.pagination.owwa') }}
        </div>
    </div>
</x-filament-panels::page>
