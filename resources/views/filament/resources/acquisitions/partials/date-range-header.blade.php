<div class="owwa-acquisition-date-range-header" wire:ignore.self>
    <label class="owwa-acquisition-date-range-header__field">
        <span>From</span>
        <input
            type="date"
            wire:model.live="filterDateFrom"
            class="owwa-acquisition-date-range-header__input"
        />
    </label>
    <label class="owwa-acquisition-date-range-header__field">
        <span>To</span>
        <input
            type="date"
            wire:model.live="filterDateUntil"
            class="owwa-acquisition-date-range-header__input"
        />
    </label>
</div>
