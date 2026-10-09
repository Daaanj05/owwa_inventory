@php
    $rows = $rows ?? collect();
    $embedded = (bool) ($embedded ?? false);
@endphp

@if (! $embedded)
<div class="owwa-pa-card fi-section rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
    <div class="fi-section-header-ctn px-5 py-3 border-b border-gray-200 dark:border-white/10">
        <h2 class="fi-section-header-heading">Replacement due — semi-expendable</h2>
        <p class="fi-section-header-description mt-1">
            Issued semi-expendable units nearing or past useful life. A date alone does not create a purchase.
        </p>
    </div>
@endif
    <div class="owwa-pa-slide-table">
        @if($rows->isEmpty())
            <div class="owwa-empty-state">
                <h3 class="owwa-empty-state-title">Nothing due for replacement</h3>
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
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($rows as $row)
                                <tr
                                    class="owwa-pa-click-row"
                                    wire:click="openReplacementIssuance({{ (int) $row->issuance_id }})"
                                    wire:keydown.enter="openReplacementIssuance({{ (int) $row->issuance_id }})"
                                    tabindex="0"
                                    role="button"
                                    aria-label="View {{ $row->item_name }}"
                                >
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
                                    <td>
                                        <span class="owwa-pa-eul-badge owwa-pa-eul-badge--{{ $row->action ?? 'awaiting_review' }}">
                                            {{ $row->action_label ?? 'Awaiting review' }}
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
@if (! $embedded)
</div>
@endif
