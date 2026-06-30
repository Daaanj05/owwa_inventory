@php
    $rows = $this->getPreviewRows();
    $officeName = $this->getSupplyOfficeName();
    $catalogUrl = $this->getCatalogUrl();
@endphp

<x-filament-widgets::widget>
    <div class="owwa-data-panel">
        <div class="owwa-data-panel-header">
            <h2 class="owwa-data-panel-title">Regional supply catalog</h2>
            <div style="display:flex;align-items:center;gap:0.5rem;flex-wrap:wrap;">
                <span class="owwa-status-badge owwa-status-ok">{{ $officeName }}</span>
                <a href="{{ $catalogUrl }}" class="owwa-status-badge owwa-status-ok" style="text-decoration:none;">
                    View full catalog
                </a>
            </div>
        </div>
        <div class="owwa-data-panel-body">
            <p class="owwa-cell-muted" style="margin:0 0 0.75rem;font-size:0.875rem;">
                Top items available at the regional supply office — request through Requisitions.
            </p>
            <div class="owwa-table-wrap">
                <table class="owwa-data-table">
                    <thead>
                        <tr>
                            <th>Item</th>
                            <th>Category</th>
                            <th class="owwa-num">Available</th>
                            <th class="owwa-status">Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($rows as $row)
                            <tr class="{{ $row->is_low ? 'owwa-row-low' : '' }}">
                                <td class="owwa-cell-primary">{{ $row->item_name }}</td>
                                <td class="owwa-cell-muted">{{ $row->category_name }}</td>
                                <td class="owwa-num owwa-cell-primary">{{ number_format($row->stock) }}</td>
                                <td class="owwa-status">
                                    @if($row->is_low)
                                        <span class="owwa-status-badge owwa-status-low">Low</span>
                                    @else
                                        <span class="owwa-status-badge owwa-status-ok">OK</span>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="4">
                                    <div class="owwa-empty">
                                        <p class="owwa-empty-title">No regional stock yet</p>
                                        <p class="owwa-empty-desc">Open the full catalog or check back after the supply custodian records stock.</p>
                                    </div>
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</x-filament-widgets::widget>
