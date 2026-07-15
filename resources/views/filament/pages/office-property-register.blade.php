@php
    $rows = $this->getStockCardRows();
    $sortBy = $this->sortBy;
    $sortDir = $this->sortDir;
    $categoryOptions = $this->getCategoryOptions();
@endphp

<x-filament-panels::page>
    <div class="owwa-inventory-layout">
        <div class="owwa-search-wrap" style="display:flex;gap:0.75rem;flex-wrap:wrap;align-items:center;">
            <select wire:model.live="category" class="owwa-search-bar" style="max-width:16rem;" aria-label="Item category">
                @foreach ($categoryOptions as $value => $label)
                    <option value="{{ $value }}">{{ $label }}</option>
                @endforeach
            </select>
            <input
                type="text"
                wire:model.live.debounce.300ms="search"
                placeholder="Search item or category…"
                class="owwa-search-bar"
                style="width: 18rem; max-width: 100%;"
            />
        </div>

        <div class="owwa-data-panel">
            <div class="owwa-data-panel-body">
                <div class="owwa-table-wrap">
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
                </div>
            </div>
        </div>

        <div class="mt-4">
            {{ $rows->links('vendor.pagination.owwa') }}
        </div>
    </div>
</x-filament-panels::page>
