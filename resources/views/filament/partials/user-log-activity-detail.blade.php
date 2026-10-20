<div class="owwa-user-log-detail">
    @if ($lines === [])
        <p class="owwa-user-log-session-empty">No details for this action.</p>
    @else
        <dl>
            @foreach ($lines as $line)
                <dt>{{ $line['label'] }}</dt>
                <dd>{{ $line['value'] }}</dd>
            @endforeach
        </dl>
    @endif
</div>
