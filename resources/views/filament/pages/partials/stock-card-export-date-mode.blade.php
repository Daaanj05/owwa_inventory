@php
    $modePath = is_callable($getStatePath ?? null)
        ? $getStatePath()
        : (isset($field) && method_exists($field, 'getStatePath') ? $field->getStatePath() : 'date_mode');
    $basePath = str_ends_with($modePath, '.date_mode')
        ? substr($modePath, 0, -strlen('.date_mode'))
        : $modePath;
    $fromPath = $basePath === $modePath ? 'date_from' : $basePath.'.date_from';
    $toPath = $basePath === $modePath ? 'date_to' : $basePath.'.date_to';
@endphp

<div
    wire:ignore.self
    class="owwa-sc-export-date-mode"
    x-data="{
        mode: $wire.$entangle(@js($modePath), true),
        dateFrom: $wire.$entangle(@js($fromPath), true),
        dateTo: $wire.$entangle(@js($toPath), true),
    }"
>
    <label class="owwa-sc-export-date-mode__option">
        <input type="radio" value="range" x-model="mode" class="owwa-sc-export-date-mode__radio">
        <span>Date range</span>
    </label>

    <div class="owwa-sc-export-date-range" x-show="mode === 'range'" x-cloak>
        <label class="owwa-sc-export-date-range__field">
            <span class="owwa-sc-export-date-range__label">From</span>
            <input type="date" x-model="dateFrom" class="owwa-sc-export-date-range__input">
        </label>
        <label class="owwa-sc-export-date-range__field">
            <span class="owwa-sc-export-date-range__label">Until</span>
            <input type="date" x-model="dateTo" class="owwa-sc-export-date-range__input">
        </label>
    </div>

    <label class="owwa-sc-export-date-mode__option">
        <input type="radio" value="all" x-model="mode" class="owwa-sc-export-date-mode__radio">
        <span>All dates</span>
    </label>
</div>
