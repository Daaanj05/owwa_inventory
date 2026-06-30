<x-filament-panels::page>
    <div class="owwa-physical-count-start-page owwa-pc-scan-container">
        <div class="owwa-pc-scan-card owwa-pc-start-info">
            <p class="owwa-pc-start-info-title">Mobile-first count</p>
            <p class="owwa-pc-scan-hint">
                Scan property QR tags as you find them. On desktop, load expected assets to compare against the book list, then export.
            </p>
            <ol class="owwa-pc-start-steps">
                <li>Start scanning on your phone</li>
                <li>Finish counting when done in the field</li>
                <li>On desktop: load expected assets, then mark complete</li>
            </ol>
        </div>

        <form wire:submit="startCount" class="owwa-pc-scan-card owwa-pc-start-form">
            @if ($this->hasFixedOffice())
                <div class="owwa-pc-office-chip">
                    <span class="owwa-pc-stat-label">Counting at</span>
                    <span class="owwa-pc-office-chip-name">{{ $this->fixedOfficeName() }}</span>
                </div>
            @else
                <div class="owwa-pc-form-field">
                    <label class="owwa-pc-field-label" for="officeId">Office</label>
                    <select
                        id="officeId"
                        wire:model="officeId"
                        required
                        class="fi-input owwa-pc-manual-input"
                    >
                        <option value="">Select office…</option>
                        @foreach ($this->officeOptions() as $office)
                            <option value="{{ $office['id'] }}">{{ $office['name'] }}</option>
                        @endforeach
                    </select>
                    @error('officeId')
                        <p class="owwa-pc-field-error">{{ $message }}</p>
                    @enderror
                </div>
            @endif

            <div class="owwa-pc-form-field">
                <label class="owwa-pc-field-label" for="itemCategoryId">Category (PPE / Semi only)</label>
                <select
                    id="itemCategoryId"
                    wire:model="itemCategoryId"
                    required
                    class="fi-input owwa-pc-manual-input"
                >
                    <option value="">Select category…</option>
                    @foreach ($this->categoryOptions() as $category)
                        <option value="{{ $category['id'] }}">{{ $category['name'] }}</option>
                    @endforeach
                </select>
                @error('itemCategoryId')
                    <p class="owwa-pc-field-error">{{ $message }}</p>
                @enderror
            </div>

            <button type="submit" class="fi-btn fi-btn-size-md fi-color fi-color-primary w-full justify-center">
                Start scanning
            </button>
        </form>
    </div>
</x-filament-panels::page>
