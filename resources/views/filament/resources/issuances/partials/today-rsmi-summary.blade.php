@php
    $summary = $this->getTodayRsmiSummary();
@endphp

<div class="owwa-inventory-layout" style="margin-bottom: 1rem;">
    <p class="owwa-cell-muted" style="margin-bottom: 0.75rem; font-size: 0.875rem;">
        Daily consumable issue report (Appendix 64 RSMI) for {{ now()->format('M d, Y') }}.
        Export RIS from Requisitions; use Export RSMI below for this report.
    </p>
    <div class="owwa-kpi-grid owwa-kpi-grid--4">
        <div class="owwa-kpi-card owwa-kpi-card-total">
            <div class="owwa-kpi-card-inner">
                <span class="owwa-kpi-card-value">{{ number_format($summary['batchCount']) }}</span>
                <span class="owwa-kpi-card-label">RSMI batches today</span>
            </div>
        </div>
        <div class="owwa-kpi-card owwa-kpi-card-total">
            <div class="owwa-kpi-card-inner">
                <span class="owwa-kpi-card-value">{{ number_format($summary['lineCount']) }}</span>
                <span class="owwa-kpi-card-label">Lines today</span>
            </div>
        </div>
        <div class="owwa-kpi-card owwa-kpi-card-ok">
            <div class="owwa-kpi-card-inner">
                <span class="owwa-kpi-card-value">{{ number_format($summary['totalQty']) }}</span>
                <span class="owwa-kpi-card-label">Total qty</span>
            </div>
        </div>
        <div class="owwa-kpi-card owwa-kpi-card-total">
            <div class="owwa-kpi-card-inner">
                <span class="owwa-kpi-card-value">₱{{ number_format($summary['totalAmount'], 2) }}</span>
                <span class="owwa-kpi-card-label">Total amount</span>
            </div>
        </div>
    </div>
</div>
