@php
    /** @var \App\Models\UserLog|null $record */
    $record = $record ?? null;

    if ($record === null) {
        return;
    }

    $activities = $record->sessionActivities()->limit(50)->get();
    $totalCount = $record->sessionActivitiesCount();
@endphp

<div class="owwa-user-log-session">
    @if ($activities->isEmpty())
        <p class="owwa-user-log-session-empty">No recorded actions during this session.</p>
    @else
        <p class="owwa-user-log-session-subtitle">
            {{ $totalCount === 1 ? '1 action during this session' : "{$totalCount} actions during this session" }}
        </p>
        <div class="owwa-user-log-session-table-wrap">
            <table class="owwa-user-log-session-table">
                <thead>
                    <tr>
                        <th>Time</th>
                        <th>Action</th>
                        <th>Summary</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($activities as $activity)
                        <tr>
                            <td>{{ $activity->created_at?->format('M j, g:i A') }}</td>
                            <td><span class="owwa-user-log-action-badge">{{ str_replace('_', ' ', $activity->action) }}</span></td>
                            <td>{{ $activity->summary }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        @if ($activities->count() >= 50)
            <p class="owwa-user-log-session-note">Showing the latest 50 actions.</p>
        @endif
    @endif
</div>
