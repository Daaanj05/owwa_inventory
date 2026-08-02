@php
    $rows = $this->getRecentRows();
    $acquisitionsUrl = $this->getAcquisitionsUrl();
@endphp

<x-filament-widgets::widget>
    <div class="owwa-data-panel owwa-dashboard-data-panel">
        <div class="owwa-data-panel-header">
            <h2 class="owwa-data-panel-title">Recent acquisition</h2>
            <a href="{{ $acquisitionsUrl }}" class="owwa-status-badge owwa-status-ok" style="text-decoration:none;">
                View all acquisitions
            </a>
        </div>
        <div class="owwa-data-panel-body">
            <div class="owwa-table-wrap">
                <table class="owwa-data-table">
                    <thead>
                        <tr>
                            <th>Receive Date</th>
                            <th>Reference</th>
                            <th>Supplier</th>
                            <th>Office</th>
                            <th class="owwa-num">Total</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($rows as $row)
                            <tr>
                                <td class="owwa-cell-muted">{{ $row->received_at?->format('M d, Y') ?? '—' }}</td>
                                <td class="owwa-cell-primary">{{ $row->reference_code }}</td>
                                <td class="owwa-cell-muted">{{ filled($row->supplier) ? $row->supplier : '—' }}</td>
                                <td class="owwa-cell-muted">{{ $row->office?->name ?? '—' }}</td>
                                <td class="owwa-num owwa-cell-primary">₱{{ number_format($this->getTotalAmount($row), 2) }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5">
                                    <div class="owwa-empty">
                                        <p class="owwa-empty-title">No received acquisitions yet</p>
                                        <p class="owwa-empty-desc">Completed acquisition paperwork will appear here after custodian receipt.</p>
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
