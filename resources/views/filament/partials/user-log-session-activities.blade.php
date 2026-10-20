@php
    /** @var \App\Models\UserLog|null $record */
    $record = $record ?? null;

    if ($record === null) {
        return;
    }

    $perPage = 25;
    $activities = $record->sessionActivities()->limit(200)->get();
    $totalCount = $record->sessionActivitiesCount();
    \App\Support\UserActivityLogDisplay::hydrateSummaries($activities);
@endphp

<div
    class="owwa-user-log-session"
    x-data="{
        page: 1,
        perPage: {{ $perPage }},
        total: {{ $activities->count() }},
        openId: null,
        loadingId: null,
        details: {},
        get lastPage() { return Math.max(1, Math.ceil(this.total / this.perPage)); },
        showPage() {
            const rows = Array.from(this.$refs.rows?.querySelectorAll('tr') ?? []);
            const start = (this.page - 1) * this.perPage;
            const end = start + this.perPage;
            rows.forEach((row, index) => {
                row.style.display = (index >= start && index < end) ? '' : 'none';
            });
        },
        prev() { this.page = Math.max(1, this.page - 1); this.openId = null; this.showPage(); },
        next() { this.page = Math.min(this.lastPage, this.page + 1); this.openId = null; this.showPage(); },
        async toggle(id) {
            if (this.openId === id) {
                this.openId = null;
                return;
            }
            this.openId = id;
            if (this.details[id]) {
                return;
            }
            this.loadingId = id;
            try {
                const lines = await $wire.loadActivityDetails(id);
                this.details = { ...this.details, [id]: lines ?? [] };
            } catch (error) {
                this.details = { ...this.details, [id]: [{ label: 'Details', value: 'Could not load this row.' }] };
            }
            if (this.loadingId === id) {
                this.loadingId = null;
            }
        },
    }"
    x-init="showPage()"
>
    @if ($activities->isEmpty())
        <p class="owwa-user-log-session-empty">No recorded actions during this session.</p>
    @else
        <p class="owwa-user-log-session-subtitle">
            {{ $totalCount === 1 ? '1 action during this session' : "{$totalCount} actions during this session" }}
            @if ($totalCount > $activities->count())
                (showing latest {{ $activities->count() }})
            @endif
        </p>
        <p class="owwa-user-log-session-hint">Click a row to see the record details.</p>
        <div class="owwa-user-log-session-table-wrap">
            <table class="owwa-user-log-session-table">
                <thead>
                    <tr>
                        <th>Time</th>
                        <th>Action</th>
                        <th>Summary</th>
                    </tr>
                </thead>
                <tbody x-ref="rows">
                    @foreach ($activities as $activity)
                        @php
                            $summary = \App\Support\UserActivityLogDisplay::summary($activity);
                            $activityId = (int) $activity->id;
                        @endphp
                        <tr
                            class="owwa-user-log-session-row"
                            tabindex="0"
                            role="button"
                            :aria-expanded="openId === {{ $activityId }} ? 'true' : 'false'"
                            :class="{ 'is-open': openId === {{ $activityId }} }"
                            @click="toggle({{ $activityId }})"
                            @keydown.enter.prevent="toggle({{ $activityId }})"
                            @keydown.space.prevent="toggle({{ $activityId }})"
                        >
                            <td>{{ $activity->created_at?->format('M j, g:i A') }}</td>
                            <td><span class="owwa-user-log-action-badge">{{ str_replace('_', ' ', $activity->action) }}</span></td>
                            <td>
                                <div class="owwa-user-log-summary">
                                    <span>{{ $summary }}</span>
                                    <span class="owwa-user-log-summary-chevron" aria-hidden="true">›</span>
                                </div>
                                <div
                                    class="owwa-user-log-detail"
                                    x-show="openId === {{ $activityId }}"
                                    x-cloak
                                    @click.stop
                                >
                                    <p class="owwa-user-log-session-hint" x-show="loadingId === {{ $activityId }}">Loading details…</p>
                                    <dl x-show="details[{{ $activityId }}]">
                                        <template x-for="line in details[{{ $activityId }}] ?? []" :key="line.label">
                                            <div class="owwa-user-log-detail-line">
                                                <dt x-text="line.label"></dt>
                                                <dd x-text="line.value"></dd>
                                            </div>
                                        </template>
                                    </dl>
                                </div>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <div
            style="display:flex;align-items:center;justify-content:space-between;gap:0.75rem;margin-top:0.75rem;"
            x-show="lastPage > 1"
        >
            <button type="button" class="fi-btn fi-btn-size-sm fi-color-gray" @click="prev()" :disabled="page <= 1">
                Previous
            </button>
            <span style="font-size:0.8125rem;color:#64748b;" x-text="`Page ${page} of ${lastPage}`"></span>
            <button type="button" class="fi-btn fi-btn-size-sm fi-color-gray" @click="next()" :disabled="page >= lastPage">
                Next
            </button>
        </div>
    @endif
</div>
