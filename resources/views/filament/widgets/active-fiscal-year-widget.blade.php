@php
    use App\Services\FiscalYearService;

    /** @var \Illuminate\Support\Collection $this->years */
    $years = $this->years;
    $service = app(FiscalYearService::class);
    $current = $service->current();
    // Show chooser whenever there is at least one fiscal year and none is active.
    $needsChoice = ((! $current && $years->count() > 0) || $forceChooser);
@endphp

<div>
    @if($needsChoice)
        {{-- Centered modal asking user to choose a fiscal year (no dashboard card) --}}
        <div class="fixed inset-0 z-50 flex items-center justify-center bg-gray-900/40">
            <div class="bg-white rounded-xl shadow-xl max-w-md w-full mx-4 p-6 space-y-4">
                <h2 class="text-lg font-semibold text-gray-900">
                    Select fiscal year
                </h2>

                <p class="text-sm text-gray-600">
                    Choose which fiscal year you want to work on. All inventory, issuances, and analytics will be filtered to this year.
                </p>

                <div class="space-y-1">
                    <label for="fy-select" class="text-sm font-medium text-gray-700">
                        Fiscal year
                    </label>
                    <select
                        id="fy-select"
                        wire:model="fiscalYearId"
                        class="block w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-primary-500 focus:ring-primary-500"
                    >
                        <option value="">-- Select year --</option>
                        @foreach($years as $year)
                            <option value="{{ $year->id }}">
                                {{ $year->name }} ({{ $year->start_date->format('M d, Y') }} – {{ $year->end_date->format('M d, Y') }})
                            </option>
                        @endforeach
                    </select>
                </div>

                <div class="flex justify-end">
                    <button
                        type="button"
                        wire:click="apply"
                        @disabled("! @js($this->fiscalYearId)")
                        class="inline-flex items-center rounded-lg bg-primary-600 px-4 py-2 text-sm font-medium text-white shadow-sm hover:bg-primary-700 focus:outline-none focus:ring-2 focus:ring-primary-500 focus:ring-offset-1 disabled:opacity-60 disabled:cursor-not-allowed"
                    >
                        Use selected year
                    </button>
                </div>
            </div>
        </div>
    @endif
</div>

