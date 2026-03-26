@php
    use App\Services\DataCoverageService;
    use App\Services\FiscalYearService;
    use App\Models\FiscalYear;

    $user = filament()->auth()->user();
    $hour = now()->hour;
    $greeting = match (true) {
        $hour < 12 => 'Good morning',
        $hour < 17 => 'Good afternoon',
        default    => 'Good evening',
    };
    $role = match ($user->role ?? '') {
        'supply_custodian'     => 'Supply Custodian',
        'authorized_personnel' => 'Unit Head',
        'employee'             => 'Employee',
        default                => 'Staff',
    };
    $dateLabel = now()->format('l, F j, Y');
    $coverageRange = app(DataCoverageService::class)->getDataRange();
    $coverageLabel = $coverageRange['label'];
    $initial = strtoupper(substr($user->name ?? 'U', 0, 1));

    $service = app(FiscalYearService::class);
    $fiscalYear = $service->current();
    $fiscalYearLabel = $fiscalYear?->name ?? 'Not set';
    $allYears = FiscalYear::orderByDesc('start_date')->get();
    $needsFiscalYearChoice = ! $fiscalYear && $allYears->isNotEmpty();
@endphp

@if($needsFiscalYearChoice)
    {{-- Small centered modal when no fiscal year is active --}}
    <div class="fixed inset-0 z-50 flex items-center justify-center bg-gray-900/40">
        <div class="bg-white rounded-xl shadow-xl max-w-sm w-full mx-4 p-6 space-y-4">
            <h2 class="text-base font-semibold text-gray-900">Select fiscal year</h2>
            <p class="text-sm text-gray-500">Choose the fiscal year to work on. All data will be filtered accordingly.</p>
            <form method="POST" action="{{ route('filament.admin.fiscal-year.set') }}">
                @csrf
                <div class="space-y-2">
                    <select name="fiscal_year_id" class="block w-full rounded-lg border-gray-300 text-sm shadow-sm">
                        <option value="">-- Select year --</option>
                        @foreach($allYears as $year)
                            <option value="{{ $year->id }}">
                                {{ $year->name }} ({{ $year->start_date->format('M d, Y') }} – {{ $year->end_date->format('M d, Y') }})
                            </option>
                        @endforeach
                    </select>
                </div>
                <div class="flex justify-end mt-4">
                    <button type="submit"
                        class="inline-flex items-center rounded-lg bg-primary-600 px-4 py-2 text-sm font-medium text-white shadow-sm hover:bg-primary-700">
                        Use selected year
                    </button>
                </div>
            </form>
        </div>
    </div>
@endif

<x-filament-widgets::widget>
    <div class="owwa-welcome">
        <div class="owwa-welcome-body">
            <div class="owwa-welcome-left">
                <div class="owwa-welcome-avatar-placeholder">{{ $initial }}</div>
                <div>
                    <div class="owwa-welcome-greeting">{{ $greeting }}, {{ $user->name }}.</div>
                    <div class="owwa-welcome-role">{{ $role }} &middot; OWWA-4A Inventory System</div>
                </div>
            </div>
            <div class="owwa-welcome-right">
                <span class="owwa-welcome-fy">
                    Fiscal year: <strong>{{ $fiscalYearLabel }}</strong>
                    @if($fiscalYear && $allYears->count() > 1)
                        <a href="{{ route('filament.admin.fiscal-year.change') }}" class="ml-1 text-primary-600 hover:text-primary-700 text-sm font-normal">(change)</a>
                    @endif
                </span>
                <span class="owwa-welcome-date">
                    <svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:-1px;margin-right:4px;opacity:0.7"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect><line x1="16" y1="2" x2="16" y2="6"></line><line x1="8" y1="2" x2="8" y2="6"></line><line x1="3" y1="10" x2="21" y2="10"></line></svg>
                    {{ $dateLabel }}
                </span>
                <span class="owwa-data-badge">
                    <span class="owwa-data-badge-dot"></span>
                    Analysis based on {{ $coverageLabel }} of historical data
                </span>
            </div>
        </div>
    </div>
</x-filament-widgets::widget>
