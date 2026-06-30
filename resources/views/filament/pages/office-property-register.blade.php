@php
    use App\Support\SemiExpendableUsefulLife;

    $rows = $this->getPropertyRows();
    $sortBy = $this->sortBy;
    $sortDir = $this->sortDir;
@endphp

<x-filament-panels::page>
    <div class="owwa-inventory-layout">
        <div class="owwa-search-wrap">
            <input
                type="text"
                wire:model.live.debounce.300ms="search"
                placeholder="Search property number, item, or category…"
                class="owwa-search-bar"
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
                                        'property_number' => 'Property no.',
                                        'item_name' => 'Item',
                                        'category_name' => 'Category',
                                        'issuance_date' => 'Issued',
                                        'estimated_useful_life' => 'Useful life',
                                        'eul_expires_at' => 'Expires',
                                    ];
                                @endphp
                                @foreach($columns as $col => $label)
                                    <th
                                        wire:click="sortByColumn('{{ $col }}')"
                                        style="cursor: pointer; user-select: none;"
                                    >
                                        {{ $label }}
                                        @if($sortBy === $col)
                                            <span style="font-size: 0.65rem; margin-left: 0.25rem;">{{ $sortDir === 'asc' ? '▲' : '▼' }}</span>
                                        @endif
                                    </th>
                                @endforeach
                                <th class="owwa-status">EUL status</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($rows as $row)
                                @php
                                    $slug = $row->item?->category?->getTemplateSlug();
                                    $eulStatus = $slug === 'semi_expendable' ? SemiExpendableUsefulLife::statusForIssuance($row) : null;
                                @endphp
                                <tr>
                                    <td class="owwa-cell-primary">{{ $row->property_number ?? '—' }}</td>
                                    <td>{{ $row->item?->name ?? '—' }}</td>
                                    <td class="owwa-cell-muted">{{ $row->item?->category?->name ?? '—' }}</td>
                                    <td class="owwa-cell-muted">{{ $row->issuance_date?->format('M d, Y') ?? '—' }}</td>
                                    <td class="owwa-cell-muted">{{ $slug === 'semi_expendable' ? ($row->estimated_useful_life ?? '—') : 'N/A' }}</td>
                                    <td class="owwa-cell-muted">{{ $slug === 'semi_expendable' ? ($row->eul_expires_at?->format('M d, Y') ?? '—') : 'N/A' }}</td>
                                    <td class="owwa-status">
                                        @if($eulStatus === SemiExpendableUsefulLife::STATUS_EXPIRED)
                                            <span class="owwa-status-badge owwa-status-low">Expired</span>
                                        @elseif($eulStatus === SemiExpendableUsefulLife::STATUS_NEARING)
                                            <span class="owwa-status-badge owwa-status-low">Nearing</span>
                                        @elseif($slug === 'semi_expendable')
                                            <span class="owwa-status-badge owwa-status-ok">Active</span>
                                        @else
                                            <span class="owwa-cell-muted">N/A</span>
                                        @endif
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="7">
                                        <div class="owwa-empty">
                                            <p class="owwa-empty-title">No issued property</p>
                                            <p class="owwa-empty-desc">Property issued to you from accepted requisitions will appear here.</p>
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
            {{ $rows->links() }}
        </div>
    </div>
</x-filament-panels::page>
