@php
    $rows = $rows ?? collect();
@endphp

<div class="owwa-pa-card fi-section rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
    <div class="fi-section-header-ctn px-5 py-3 border-b border-gray-200 dark:border-white/10">
        <h2 class="fi-section-header-heading">Useful life — semi-expendable</h2>
        <p class="fi-section-header-description mt-1">
            Replacement / review signals based on estimated useful life (EUL).
        </p>
    </div>
    <div class="px-5 py-3">
        @if($rows->isEmpty())
            <div class="owwa-empty-state">
                <h3 class="owwa-empty-state-title">No EUL review items</h3>
                <p class="owwa-empty-state-text">
                    No semi-expendable issuances for your office are nearing or past useful life right now.
                </p>
            </div>
        @else
            <div class="owwa-pa-table-shell">
                <div class="owwa-pa-table-scroll">
                    <table class="owwa-data-table">
                        <thead>
                            <tr>
                                <th>Item</th>
                                <th>Property / Ref</th>
                                <th>Issued to</th>
                                <th>EUL</th>
                                <th>Expires</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($rows as $row)
                                <tr>
                                    <td>{{ $row->item_name }}</td>
                                    <td>
                                        {{ $row->property_number ?? $row->reference_code ?? '—' }}
                                    </td>
                                    <td>{{ $row->issued_to_name ?? '—' }}</td>
                                    <td>{{ $row->estimated_useful_life ?? '—' }}</td>
                                    <td>{{ $row->eul_expires_at ?? '—' }}</td>
                                    <td>
                                        <span class="owwa-pa-eul-badge owwa-pa-eul-badge--{{ $row->status }}">
                                            {{ $row->status_label }}
                                        </span>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        @endif
    </div>
</div>
