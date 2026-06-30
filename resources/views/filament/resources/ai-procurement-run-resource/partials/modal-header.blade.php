<div class="owwa-ai-run-modal-header">
    <div class="owwa-ai-run-modal-header-top">
        <h2 class="owwa-ai-run-modal-reference">{{ $reference ?? '—' }}</h2>
        @if (filled($status ?? null))
            <span
                class="owwa-ai-run-modal-status"
                style="background:{{ $status['bg'] }}; color:{{ $status['text'] }};"
            >
                <span class="owwa-ai-run-modal-status-dot" style="background:{{ $status['dot'] }};"></span>
                {{ $status['label'] }}
            </span>
        @endif
    </div>

    @if (count($meta ?? []) > 0)
        <dl class="owwa-ai-run-modal-meta">
            @foreach ($meta as $item)
                <div>
                    <dt>{{ $item['label'] }}</dt>
                    <dd>{{ $item['value'] }}</dd>
                </div>
            @endforeach
        </dl>
    @endif
</div>
