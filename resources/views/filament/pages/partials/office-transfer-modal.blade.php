@php
    /** @var array<string, mixed> $transfer */
@endphp

<div class="owwa-stock-ledger-modal">
    <dl class="owwa-stock-ledger-header">
        <div class="owwa-stock-ledger-header-item">
            <dt>PTR no.</dt>
            <dd>{{ $transfer['reference_code'] ?? '—' }}</dd>
        </div>
        <div class="owwa-stock-ledger-header-item">
            <dt>Direction</dt>
            <dd>{{ $transfer['direction'] ?? '—' }}</dd>
        </div>
        <div class="owwa-stock-ledger-header-item">
            <dt>Date</dt>
            <dd>{{ $transfer['transfer_date'] ?? '—' }}</dd>
        </div>
        <div class="owwa-stock-ledger-header-item">
            <dt>Item</dt>
            <dd>{{ $transfer['item_name'] ?? '—' }}</dd>
        </div>
        <div class="owwa-stock-ledger-header-item">
            <dt>Quantity</dt>
            <dd>{{ number_format((int) ($transfer['quantity'] ?? 0)) }}</dd>
        </div>
        <div class="owwa-stock-ledger-header-item">
            <dt>{{ $transfer['identifier_label'] ?? 'Property No.' }}</dt>
            <dd>{{ $transfer['identifier'] ?? '—' }}</dd>
        </div>
        <div class="owwa-stock-ledger-header-item">
            <dt>From office</dt>
            <dd>{{ $transfer['from_office_name'] ?? '—' }}</dd>
        </div>
        <div class="owwa-stock-ledger-header-item">
            <dt>To office</dt>
            <dd>{{ $transfer['to_office_name'] ?? '—' }}</dd>
        </div>
        <div class="owwa-stock-ledger-header-item">
            <dt>From accountable officer</dt>
            <dd>{{ $transfer['from_accountable_officer'] ?? '—' }}</dd>
        </div>
        <div class="owwa-stock-ledger-header-item">
            <dt>To accountable officer</dt>
            <dd>{{ $transfer['to_accountable_officer'] ?? '—' }}</dd>
        </div>
        <div class="owwa-stock-ledger-header-item">
            <dt>Condition</dt>
            <dd>{{ $transfer['condition'] ?? '—' }}</dd>
        </div>
        <div class="owwa-stock-ledger-header-item" style="grid-column: 1 / -1;">
            <dt>Remarks</dt>
            <dd>{{ $transfer['remarks'] ?? '—' }}</dd>
        </div>
    </dl>
</div>
