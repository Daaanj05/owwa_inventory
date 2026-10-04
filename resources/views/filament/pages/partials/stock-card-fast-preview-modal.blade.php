<div class="owwa-fast-export-preview-panel">
    <div class="owwa-fast-export-preview-panel__label">Print preview</div>
    @if (filled($previewUrl))
        <div class="owwa-fast-export-preview-panel__frame">
            <iframe
                title="Stock card print preview"
                src="{{ $previewUrl }}"
            ></iframe>
        </div>
    @else
        <p class="owwa-fast-export-preview-panel__empty">Preview is not available.</p>
    @endif
</div>
