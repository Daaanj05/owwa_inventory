@php
    $checks = $report['checks'] ?? [];
    $capacity = $report['capacity'] ?? [];
    $history = $report['history'] ?? [];
    $activeWindow = (int) ($capacity['active_window_minutes'] ?? 15);

    $failCount = collect($checks)->where('status', 'fail')->count();
    $warnCount = collect($checks)->where('status', 'warn')->count();
    $checkTotal = count($checks);
    $summaryStatus = $failCount > 0 ? 'fail' : ($warnCount > 0 ? 'warn' : 'ok');
    $summaryBadge = match ($summaryStatus) {
        'fail' => 'Unhealthy',
        'warn' => 'Attention',
        default => 'Healthy',
    };
    $summaryLabel = match ($summaryStatus) {
        'fail' => 'System unhealthy',
        'warn' => 'Needs attention',
        default => 'System healthy',
    };
    $summaryNote = match ($summaryStatus) {
        'fail' => $failCount === 1
            ? '1 live check failed. Review the failed row below.'
            : "{$failCount} live checks failed. Review the failed rows below.",
        'warn' => $warnCount === 1
            ? '1 live check needs a look. The rest are OK.'
            : "{$warnCount} live checks need a look. The rest are OK.",
        default => $checkTotal > 0
            ? "All {$checkTotal} live checks passed."
            : 'Live checks have not reported yet.',
    };
    $summaryClass = match ($summaryStatus) {
        'fail' => 'owwa-health-summary--fail',
        'warn' => 'owwa-health-summary--warn',
        default => 'owwa-health-summary--ok',
    };
    $statusBadge = fn (string $status): string => match ($status) {
        'ok' => 'OK',
        'fail' => 'Failed',
        default => 'Warning',
    };
@endphp

<x-filament-panels::page>
    <div class="owwa-health-shell" wire:poll.45s="refreshReport">
        <div class="owwa-health-summary {{ $summaryClass }}" role="status">
            <span class="owwa-health-summary-badge" aria-hidden="true">{{ $summaryBadge }}</span>
            <div class="owwa-health-summary-copy">
                <span class="owwa-health-summary-label">{{ $summaryLabel }}</span>
                <span class="owwa-health-summary-note">{{ $summaryNote }}</span>
            </div>
        </div>

        <div class="owwa-health-layout">
            <section class="owwa-health-main">
                <div class="owwa-health-section-header">
                    <h2 class="owwa-health-section-title">Live checks</h2>
                    <p class="owwa-health-section-note">Gray chip = what we measured. Text under the name = how we checked it.</p>
                </div>

                <div class="owwa-health-check-list">
                    @foreach ($checks as $check)
                        @php
                            $status = $check['status'] ?? 'warn';
                            $rowClass = match ($status) {
                                'ok' => 'owwa-health-check--ok',
                                'fail' => 'owwa-health-check--fail',
                                default => 'owwa-health-check--warn',
                            };
                            $badgeClass = match ($status) {
                                'ok' => 'owwa-health-badge--ok',
                                'fail' => 'owwa-health-badge--fail',
                                default => 'owwa-health-badge--warn',
                            };
                            $isScheduleWarn = ($check['key'] ?? '') === 'schedule' && $status === 'warn';
                        @endphp
                        <article class="owwa-health-check {{ $rowClass }}">
                            <div class="owwa-health-check-top">
                                <div class="owwa-health-check-identity">
                                    <span class="owwa-health-pip" aria-hidden="true"></span>
                                    <div>
                                        <h3 class="owwa-health-check-title">{{ $check['label'] ?? 'Check' }}</h3>
                                        <p class="owwa-health-check-detail">{{ $check['detail'] ?? $check['message'] ?? '' }}</p>
                                    </div>
                                </div>
                                <div class="owwa-health-check-aside">
                                    <span class="owwa-health-evidence">{{ $check['evidence'] ?? '—' }}</span>
                                    <span class="owwa-health-badge {{ $badgeClass }}">{{ $statusBadge($status) }}</span>
                                </div>
                            </div>
                            @if ($isScheduleWarn)
                                <button
                                    type="button"
                                    class="owwa-health-inline-action"
                                    wire:click="mountAction('captureSnapshot')"
                                >
                                    Capture snapshot now
                                </button>
                            @endif
                        </article>
                    @endforeach
                </div>
            </section>

            <aside class="owwa-health-aside">
                <section class="owwa-health-aside-block">
                    <div class="owwa-health-section-header">
                        <h2 class="owwa-health-section-title">Capacity</h2>
                        <p class="owwa-health-section-note">Using now = last {{ $activeWindow }} min. Signed in = still within session lifetime.</p>
                    </div>
                    <div class="owwa-health-metric-grid owwa-health-metric-grid--aside">
                        <button
                            type="button"
                            class="owwa-health-metric owwa-health-metric--primary owwa-health-metric--link"
                            wire:click="mountAction('viewStatusOverview')"
                        >
                            <div class="owwa-health-metric-value">{{ number_format((int) ($capacity['active_sessions'] ?? 0)) }}</div>
                            <div class="owwa-health-metric-label">Using now</div>
                            <div class="owwa-health-metric-cue">View list</div>
                        </button>
                        <div class="owwa-health-metric">
                            <div class="owwa-health-metric-value">{{ number_format((int) ($capacity['open_sessions'] ?? 0)) }}</div>
                            <div class="owwa-health-metric-label">Signed in</div>
                        </div>
                        @if ($capacity['laravel_sessions'] !== null)
                            <div class="owwa-health-metric">
                                <div class="owwa-health-metric-value">{{ number_format((int) $capacity['laravel_sessions']) }}</div>
                                <div class="owwa-health-metric-label">Browser sessions</div>
                            </div>
                        @endif
                        <div class="owwa-health-metric {{ ((int) ($capacity['failed_jobs_hour'] ?? 0)) > 0 ? 'owwa-health-metric--danger' : 'owwa-health-metric--ok' }}">
                            <div class="owwa-health-metric-value">
                                {{ $capacity['pending_jobs'] === null ? '—' : number_format((int) $capacity['pending_jobs']) }}
                                /
                                {{ $capacity['failed_jobs_hour'] === null ? '—' : number_format((int) $capacity['failed_jobs_hour']) }}
                            </div>
                            <div class="owwa-health-metric-label">Waiting / failed jobs</div>
                        </div>
                    </div>
                </section>

                <section class="owwa-health-aside-block">
                    <div class="owwa-health-section-header">
                        <h2 class="owwa-health-section-title">History</h2>
                        <p class="owwa-health-section-note">Scheduler heartbeat every 15 min (proves cron is running).</p>
                    </div>
                    <div class="owwa-health-metric-grid owwa-health-metric-grid--aside">
                        <div class="owwa-health-metric">
                            <div class="owwa-health-metric-value">{{ number_format((int) ($history['peak_active_today'] ?? 0)) }}</div>
                            <div class="owwa-health-metric-label">Peak today</div>
                        </div>
                        <div class="owwa-health-metric">
                            <div class="owwa-health-metric-value">{{ number_format((int) ($history['peak_active_7d'] ?? 0)) }}</div>
                            <div class="owwa-health-metric-label">Peak 7 days</div>
                        </div>
                        <div class="owwa-health-metric owwa-health-metric--primary owwa-health-metric--span2">
                            <div class="owwa-health-metric-value owwa-health-metric-value--text">
                                @if (! empty($history['last_snapshot_at']))
                                    {{ \Illuminate\Support\Carbon::parse($history['last_snapshot_at'])->diffForHumans() }}
                                @else
                                    None yet
                                @endif
                            </div>
                            <div class="owwa-health-metric-label">Last snapshot</div>
                        </div>
                    </div>
                </section>
            </aside>
        </div>
    </div>
</x-filament-panels::page>
