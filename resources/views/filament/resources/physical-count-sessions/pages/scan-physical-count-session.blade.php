<x-filament-panels::page>
    @php($summary = $this->countSummary())
    @php($percent = $this->tallyProgressPercent())
    @php($scanOnly = $summary['scan_only'] ?? false)

    @if ($showFinishSummary)
        <div class="owwa-physical-count-scan-page owwa-pc-scan-container">
            <div class="owwa-pc-scan-card">
                <h2 class="owwa-pc-scan-title">Tally summary</h2>
                <p class="owwa-pc-scan-subtitle">Counting is paused. Complete header fields and signatories on desktop.</p>

                @if ($scanOnly)
                    <dl class="owwa-pc-stat-grid owwa-pc-stat-grid--single">
                        <div>
                            <dt class="owwa-pc-stat-label">Tags scanned</dt>
                            <dd class="owwa-pc-stat-value">{{ $summary['scanned'] }}</dd>
                        </div>
                    </dl>
                    <p class="owwa-pc-scan-hint">Load expected assets on desktop to compare against the book list before export.</p>
                @else
                    <dl class="owwa-pc-stat-grid">
                        <div>
                            <dt class="owwa-pc-stat-label">Expected</dt>
                            <dd class="owwa-pc-stat-value">{{ $summary['expected'] }}</dd>
                        </div>
                        <div>
                            <dt class="owwa-pc-stat-label">Scanned</dt>
                            <dd class="owwa-pc-stat-value">{{ $summary['scanned'] }}</dd>
                        </div>
                        <div>
                            <dt class="owwa-pc-stat-label">Missing</dt>
                            <dd class="owwa-pc-stat-value owwa-pc-stat-value--danger">{{ $summary['shortages'] }}</dd>
                        </div>
                        <div>
                            <dt class="owwa-pc-stat-label">Overage</dt>
                            <dd class="owwa-pc-stat-value owwa-pc-stat-value--warning">{{ $summary['overages'] }}</dd>
                        </div>
                    </dl>

                    <div class="owwa-pc-progress">
                        <div class="owwa-pc-progress-bar" style="width: {{ $percent }}%"></div>
                    </div>
                @endif
            </div>

            <div class="owwa-pc-action-stack">
                @if (! $scanOnly && $summary['shortages'] > 0)
                    <button type="button" wire:click="openTallyView('missing')" class="fi-btn fi-btn-size-md fi-color fi-color-danger w-full justify-center">
                        Review missing items
                    </button>
                @endif
                <a href="{{ $this->desktopViewUrl() }}" class="fi-btn fi-btn-size-md fi-color fi-color-primary w-full justify-center">
                    Complete on desktop
                </a>
                <button type="button" wire:click="closeTallyView" class="fi-btn fi-btn-size-md fi-color fi-color-gray w-full justify-center">
                    Back to scanner
                </button>
            </div>
        </div>
    @elseif ($showTallyView)
        <div class="owwa-physical-count-scan-page owwa-pc-scan-container">
            @if (! $scanOnly)
                <div class="owwa-pc-filter-row">
                    @foreach (['all' => 'All', 'missing' => 'Missing', 'found' => 'Found', 'overage' => 'Overage'] as $key => $label)
                        <button
                            type="button"
                            wire:click="setTallyFilter('{{ $key }}')"
                            @class([
                                'owwa-pc-filter-chip',
                                'owwa-pc-filter-chip--active' => $tallyFilter === $key,
                            ])
                        >
                            {{ $label }}
                        </button>
                    @endforeach
                </div>
            @endif

            <ul class="owwa-pc-line-list">
                @forelse ($this->tallyLines() as $line)
                    @php($variance = $line->shortageOverageQuantity())
                    <li class="owwa-pc-line-item">
                        <div class="owwa-pc-line-title">{{ $line->property_number }}</div>
                        <div class="owwa-pc-line-meta">{{ $line->article ?? $line->item?->name }}</div>
                        <div class="owwa-pc-line-footer">
                            @if ($scanOnly)
                                <span>Scanned</span>
                                <span class="owwa-pc-line-status owwa-pc-line-status--ok">OK</span>
                            @else
                                <span>Book {{ $line->balance_per_card }} / On hand {{ $line->on_hand_count }}</span>
                                <span @class([
                                    'owwa-pc-line-status',
                                    'owwa-pc-line-status--danger' => $variance < 0,
                                    'owwa-pc-line-status--warning' => $variance > 0,
                                    'owwa-pc-line-status--ok' => $variance === 0,
                                ])>
                                    @if ($variance < 0)
                                        Missing {{ abs($variance) }}
                                    @elseif ($variance > 0)
                                        +{{ $variance }} over
                                    @else
                                        OK
                                    @endif
                                </span>
                            @endif
                        </div>
                    </li>
                @empty
                    <li class="owwa-pc-line-empty">No lines in this filter.</li>
                @endforelse
            </ul>

            <button type="button" wire:click="closeTallyView" class="fi-btn fi-btn-size-md fi-color fi-color-gray w-full justify-center">
                Back to scanner
            </button>
        </div>
    @else
        <script src="https://unpkg.com/html5-qrcode@2.3.8/html5-qrcode.min.js"></script>
        <script src="{{ asset('js/physical-count-scanner.js') }}"></script>

        <div class="owwa-physical-count-scan-page owwa-pc-scan-container">
            <div class="owwa-pc-scan-card">
                @if ($scanOnly)
                    <div class="owwa-pc-scan-mode-label">Scan mode</div>
                    <dl class="owwa-pc-stat-grid owwa-pc-stat-grid--single">
                        <div>
                            <dt class="owwa-pc-stat-label">Tags scanned</dt>
                            <dd class="owwa-pc-stat-value">{{ $summary['scanned'] }}</dd>
                        </div>
                    </dl>
                    <p class="owwa-pc-scan-hint">Book comparison happens on desktop after Load expected assets.</p>
                @else
                    <div class="owwa-pc-progress-header">
                        <span class="owwa-pc-stat-label">Progress</span>
                        <span class="owwa-pc-progress-text">{{ $summary['scanned'] }} / {{ $summary['expected'] }} ({{ $percent }}%)</span>
                    </div>
                    <div class="owwa-pc-progress">
                        <div class="owwa-pc-progress-bar" style="width: {{ $percent }}%"></div>
                    </div>

                    <dl class="owwa-pc-stat-grid">
                        <div>
                            <dt class="owwa-pc-stat-label">Expected (book)</dt>
                            <dd class="owwa-pc-stat-value">{{ $summary['expected'] }}</dd>
                        </div>
                        <div>
                            <dt class="owwa-pc-stat-label">Scanned (on hand)</dt>
                            <dd class="owwa-pc-stat-value">{{ $summary['scanned'] }}</dd>
                        </div>
                        <div>
                            <dt class="owwa-pc-stat-label">Shortage lines</dt>
                            <dd class="owwa-pc-stat-value owwa-pc-stat-value--danger">{{ $summary['shortages'] }}</dd>
                        </div>
                        <div>
                            <dt class="owwa-pc-stat-label">Overage lines</dt>
                            <dd class="owwa-pc-stat-value owwa-pc-stat-value--warning">{{ $summary['overages'] }}</dd>
                        </div>
                    </dl>
                @endif

                <div class="owwa-pc-action-row">
                    <button type="button" wire:click="openTallyView" class="fi-btn fi-btn-size-sm fi-color fi-color-gray flex-1 justify-center">
                        View tally
                    </button>
                    <button type="button" wire:click="finishCounting" wire:confirm="Finish counting for this session?" class="fi-btn fi-btn-size-sm fi-color fi-color-primary flex-1 justify-center">
                        Finish counting
                    </button>
                </div>
            </div>

            @if ($lastScanMessage)
                <div @class([
                    'owwa-pc-feedback',
                    'owwa-pc-feedback--success' => $lastScanTone === 'success',
                    'owwa-pc-feedback--warning' => $lastScanTone === 'warning',
                    'owwa-pc-feedback--danger' => $lastScanTone === 'danger',
                    'owwa-pc-feedback--info' => $lastScanTone === 'info',
                ])>
                    {{ $lastScanMessage }}
                </div>
            @endif

            <div
                wire:ignore
                x-data="physicalCountScanner({ componentId: @js($this->getId()), countedPropertyNumbers: @js($this->countedPropertyNumbers()) })"
                x-init="init()"
                x-on:destroy="destroy()"
                x-on:physical-count-scan-processed.window="handleScanProcessed($event)"
            >
                <div x-show="cameraUnavailable" x-cloak class="owwa-pc-camera-notice">
                    Camera access is unavailable (HTTPS required on mobile). Enter property numbers manually below.
                </div>

                <div class="owwa-pc-camera-box">
                    <div id="physical-count-qr-reader" class="owwa-pc-qr-reader"></div>
                </div>
            </div>

            <p class="owwa-pc-camera-hint">
                Point your phone camera at the property QR tag. HTTPS is required for camera access on mobile browsers.
            </p>

            <form wire:submit="submitManualCode" class="owwa-pc-manual-form">
                <input
                    type="text"
                    wire:model="manualCode"
                    placeholder="Enter property number manually"
                    class="fi-input owwa-pc-manual-input"
                />
                <button type="submit" class="fi-btn fi-btn-size-md fi-color fi-color-primary shrink-0">
                    Submit
                </button>
            </form>

            @if (count($recentScans) > 0)
                <div class="owwa-pc-scan-card">
                    <h3 class="owwa-pc-recent-title">Recent scans</h3>
                    <ul class="owwa-pc-recent-list">
                        @foreach ($recentScans as $scan)
                            <li class="owwa-pc-recent-item">
                                <div>
                                    <div class="owwa-pc-line-title">{{ $scan['property_number'] }}</div>
                                    <div class="owwa-pc-line-meta">{{ $scan['message'] }}</div>
                                </div>
                                <div class="owwa-pc-recent-meta">
                                    <div>{{ $scan['result'] }}</div>
                                    <div>{{ $scan['time'] }}</div>
                                </div>
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endif
        </div>
    @endif
</x-filament-panels::page>
