@php
    $summary = $this->getStockSummary();
    $cards = $this->getTaskCards();
@endphp

<x-filament-panels::page>
    <div class="owwa-inventory-layout">
        <div class="owwa-kpi-grid">
            <div class="owwa-kpi-card owwa-kpi-card-total">
                <span class="owwa-kpi-tooltip">Number of listed items in this category.</span>
                <div class="owwa-kpi-card-inner">
                    <span class="owwa-kpi-card-value">{{ number_format($summary['total']) }}</span>
                    <span class="owwa-kpi-card-label">Total Items</span>
                </div>
            </div>
            <div class="owwa-kpi-card owwa-kpi-card-ok">
                <span class="owwa-kpi-tooltip">Number of listed items that currently have available stock.</span>
                <div class="owwa-kpi-card-inner">
                    <span class="owwa-kpi-card-value">{{ number_format($summary['okCount']) }}</span>
                    <span class="owwa-kpi-card-label">In stock</span>
                </div>
            </div>
            <div class="owwa-kpi-card owwa-kpi-card-low">
                <span class="owwa-kpi-tooltip">Number of listed items currently at or below reorder level.</span>
                <div class="owwa-kpi-card-inner">
                    <span class="owwa-kpi-card-value">{{ number_format($summary['lowCount']) }}</span>
                    <span class="owwa-kpi-card-label">Low stock</span>
                </div>
            </div>
        </div>

        <div class="owwa-data-panel-header">
            <h2 class="owwa-data-panel-title">Select a task</h2>
            <div style="display: flex; align-items: center; gap: 0.5rem; flex-wrap: wrap;">
                <span class="owwa-status-badge owwa-status-ok">{{ $this->getCategoryLabel() }}</span>
            </div>
        </div>

        <div class="owwa-data-panel">
            <div class="owwa-data-panel-body owwa-category-task-body">
                <div class="owwa-category-task-grid">
                    @foreach ($cards as $card)
                        <a href="{{ $card['url'] }}" class="owwa-category-task-card">
                            <div class="owwa-category-task-icon">
                                <x-filament::icon :icon="$card['icon']" class="h-5 w-5" />
                            </div>
                            <div>
                                <h3 class="owwa-category-task-title">{{ $card['title'] }}</h3>
                                <p class="owwa-category-task-desc">{{ $card['description'] }}</p>
                            </div>
                        </a>
                    @endforeach
                </div>
            </div>
        </div>

        @if ($summary['lowCount'] > 0)
            <div class="owwa-data-panel-alert owwa-data-panel-alert-full">
                <svg class="owwa-alert-icon" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126z" />
                </svg>
                <div>
                    {{ number_format($summary['lowCount']) }} items are currently at or below reorder point in this category.
                </div>
            </div>
        @endif
    </div>
</x-filament-panels::page>
