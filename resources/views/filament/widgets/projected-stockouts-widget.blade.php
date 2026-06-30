@php
    $rows = $this->getRows();
@endphp

<x-filament-widgets::widget>
    <div class="owwa-pa-card">
        <x-filament::section heading="Projected stockouts" description="Items likely to run out soon based on forecasted monthly usage.">
            <div class="flex items-center justify-between gap-2 flex-wrap">
                <div class="text-sm text-gray-500">
                    Showing stockouts within the next ~2 months (approximation based on 30-day months).
                </div>
                @if($this->showCategoryFilter)
                    <div class="min-w-[220px]">
                        {{ $this->form }}
                    </div>
                @endif
            </div>

            @if($rows->isEmpty())
                <div class="owwa-empty-state mt-3">
                    <svg class="owwa-empty-state-icon" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5"
                              d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126z" />
                    </svg>
                    <h3 class="owwa-empty-state-title">No imminent stockouts</h3>
                    <p class="owwa-empty-state-text">No item-office pairs are forecasted to stock out within the next two months for your current scope.</p>
                </div>
            @else
                <div class="owwa-table-wrap mt-3 owwa-pa-table-shell">
                    <table class="owwa-data-table owwa-data-table--zebra">
                        <thead>
                        <tr>
                            <th style="width: 92px;">Priority</th>
                            <th>Item</th>
                            <th>Office</th>
                            <th class="owwa-num">Cover (mo)</th>
                            <th>Projected stockout</th>
                        </tr>
                        </thead>
                        <tbody>
                        @foreach($rows as $row)
                            <tr class="owwa-data-table__row {{ $row->priority === 'High' ? 'owwa-row-low' : '' }}">
                                <td>
                                    @if($row->priority === 'High')
                                        <span class="owwa-status-badge owwa-status-low">High</span>
                                    @else
                                        <span class="owwa-status-badge" style="background:#fff7ed;color:#9a3412;border:1px solid #fed7aa;">Medium</span>
                                    @endif
                                </td>
                                <td class="owwa-cell-primary">{{ $row->item_name }}</td>
                                <td class="owwa-cell-muted">{{ $row->office_name }}</td>
                                <td class="owwa-num owwa-cell-muted">{{ number_format($row->months_cover, 1) }}</td>
                                <td class="owwa-cell-muted">{{ $row->projected_stockout_date }}</td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </x-filament::section>
    </div>
</x-filament-widgets::widget>
