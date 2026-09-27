@php
    /** @var list<array{label: string, action?: string, url?: string, style?: string}> $buttons */
    $buttons = $buttons ?? [];
@endphp

@if ($buttons !== [])
    <div class="owwa-search-row-actions">
        @foreach ($buttons as $button)
            @php
                $style = $button['style'] ?? 'primary';
                $class = $style === 'gray'
                    ? 'fi-btn fi-btn-color-gray fi-size-md fi-btn-outlined'
                    : 'fi-btn fi-color-primary fi-bg-color-400 fi-text-color-950 fi-btn-color-primary fi-size-md fi-btn-solid';
            @endphp
            @if (filled($button['url'] ?? null))
                <a href="{{ $button['url'] }}" class="{{ $class }}">
                    <span class="fi-btn-label">{{ $button['label'] }}</span>
                </a>
            @else
                <button
                    type="button"
                    class="{{ $class }}"
                    @if (filled($button['schema'] ?? null))
                        x-on:click="mountAction('{{ $button['action'] }}', {}, { schemaComponent: '{{ $button['schema'] }}' })"
                    @else
                        wire:click="mountAction('{{ $button['action'] }}')"
                    @endif
                >
                    <span class="fi-btn-label">{{ $button['label'] }}</span>
                </button>
            @endif
        @endforeach
    </div>
@endif
