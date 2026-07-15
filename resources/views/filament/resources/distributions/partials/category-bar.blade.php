@php
    $categoryOptions = \App\Support\InventoryCategoryOptions::allActiveCategoryOptions();
@endphp

<select
    wire:model.live="category"
    class="owwa-search-bar owwa-distributions-category-select"
    style="max-width:16rem;"
    aria-label="Item category"
>
    @foreach ($categoryOptions as $value => $label)
        <option value="{{ $value }}">{{ $label }}</option>
    @endforeach
</select>
