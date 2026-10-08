@php
    /** @var array{status_label: string, active_window_minutes: int, active: list<array{name: string, role: string, portal: string, last_activity: string}>, idle: list<array{name: string, role: string, portal: string, last_activity: string}>, by_role: array<string, int>, by_portal: array<string, int>} $overview */
    $active = $overview['active'] ?? [];
    $idle = $overview['idle'] ?? [];
    $byRole = $overview['by_role'] ?? [];
    $byPortal = $overview['by_portal'] ?? [];
    $window = (int) ($overview['active_window_minutes'] ?? 15);
    $idleCount = count($idle);
@endphp

<div class="owwa-health-status-modal">
    <p class="owwa-health-status-modal-lead">
        <strong>{{ $overview['status_label'] ?? 'Status' }}</strong>
        — Active in the last {{ $window }} minutes.
    </p>

    <h3 class="owwa-health-status-modal-heading">Who is using the system now</h3>
    @if ($active === [])
        <p class="owwa-health-status-modal-empty">Nobody has been active in the last {{ $window }} minutes.</p>
    @else
        <div class="owwa-health-status-table-wrap">
            <table class="owwa-health-status-table">
                <thead>
                    <tr>
                        <th>Name</th>
                        <th>Job</th>
                        <th>Signed in to</th>
                        <th>Last activity</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($active as $person)
                        <tr>
                            <td>{{ $person['name'] }}</td>
                            <td>{{ $person['role'] }}</td>
                            <td>{{ $person['portal'] }}</td>
                            <td>{{ $person['last_activity'] }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif

    <div class="owwa-health-status-modal-breakdown">
        <div>
            <h3 class="owwa-health-status-modal-heading">Active by role</h3>
            @if ($byRole === [])
                <p class="owwa-health-status-modal-empty">No active users to group by role.</p>
            @else
                <div class="owwa-health-status-modal-chips">
                    @foreach ($byRole as $role => $total)
                        <span class="owwa-health-status-chip">{{ $role }}: {{ $total }}</span>
                    @endforeach
                </div>
            @endif
        </div>
        <div>
            <h3 class="owwa-health-status-modal-heading">Active by portal</h3>
            @if ($byPortal === [])
                <p class="owwa-health-status-modal-empty">No active users to group by portal.</p>
            @else
                <div class="owwa-health-status-modal-chips">
                    @foreach ($byPortal as $portal => $total)
                        <span class="owwa-health-status-chip owwa-health-status-chip--portal">{{ $portal }}: {{ $total }}</span>
                    @endforeach
                </div>
            @endif
        </div>
    </div>

    @if ($idleCount > 0)
        <h3 class="owwa-health-status-modal-heading">Signed in but idle ({{ $idleCount }})</h3>
        <p class="owwa-health-status-modal-help">
            Still within the session lifetime, but no activity in the last {{ $window }} minutes.
        </p>
        <div class="owwa-health-status-table-wrap">
            <table class="owwa-health-status-table">
                <thead>
                    <tr>
                        <th>Name</th>
                        <th>Job</th>
                        <th>Signed in to</th>
                        <th>Last activity</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($idle as $person)
                        <tr>
                            <td>{{ $person['name'] }}</td>
                            <td>{{ $person['role'] }}</td>
                            <td>{{ $person['portal'] }}</td>
                            <td>{{ $person['last_activity'] }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</div>
