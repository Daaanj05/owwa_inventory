@php
    /** @var array{summary: string|null, empty_title: string, empty_desc: string, columns: array<string, string>, numeric_keys: array<int, string>, rows?: array<int, array<string, mixed>>, sections?: array<int, array{heading: string, columns?: array<string, string>, rows: array<int, array<string, mixed>>}>, category_filter?: array{key: string, value: int|null, options: array<int, string>}, pagination?: array{key: string, current: int, last: int, total: int}} $detail */
    $summary = $detail['summary'] ?? null;
    $columns = $detail['columns'];
    $rows = $detail['rows'] ?? [];
    $sections = $detail['sections'] ?? [];
    $numericKeys = $detail['numeric_keys'] ?? [];
    $emptyTitle = $detail['empty_title'] ?? 'No records';
    $emptyDesc = $detail['empty_desc'] ?? 'Nothing to show.';
    $pagination = $detail['pagination'] ?? null;
    $categoryFilter = $detail['category_filter'] ?? null;
    $hasSections = $sections !== [];
    $isEmpty = $hasSections
        ? collect($sections)->every(fn (array $section): bool => ($section['rows'] ?? []) === [])
        : $rows === [];
@endphp

<div class="owwa-stock-ledger-modal">
    @if (is_array($categoryFilter))
        <div style="display:flex;align-items:center;gap:0.75rem;margin:0 0 0.75rem;flex-wrap:wrap;">
            <label for="kpi-category-{{ $categoryFilter['key'] }}" style="font-size:0.8125rem;color:#64748b;font-weight:500;">
                Category
            </label>
            <select
                id="kpi-category-{{ $categoryFilter['key'] }}"
                wire:change="setKpiCategory('{{ $categoryFilter['key'] }}', $event.target.value || null)"
                style="min-width:14rem;border:1px solid #cbd5e1;border-radius:0.375rem;padding:0.375rem 0.625rem;font-size:0.8125rem;background:#fff;color:#0f172a;"
            >
                <option value="" @selected(($categoryFilter['value'] ?? null) === null)>All categories</option>
                @foreach (($categoryFilter['options'] ?? []) as $id => $label)
                    <option value="{{ $id }}" @selected((int) ($categoryFilter['value'] ?? 0) === (int) $id)>
                        {{ $label }}
                    </option>
                @endforeach
            </select>
        </div>
    @endif

    @if (filled($summary))
        <p class="owwa-stock-ledger-header" style="margin:0 0 0.75rem;color:#64748b;font-size:0.8125rem;">
            {{ $summary }}
        </p>
    @endif

    @if ($hasSections)
        @forelse ($sections as $section)
            @php
                $sectionColumns = $section['columns'] ?? $columns;
                $sectionRows = $section['rows'] ?? [];
            @endphp
            @if (($categoryFilter['value'] ?? null) === null)
                <h3 style="margin:1rem 0 0.5rem;font-size:0.875rem;font-weight:600;color:#0f172a;">
                    {{ $section['heading'] }}
                </h3>
            @endif
            <div class="owwa-table-wrap owwa-stock-ledger-table-wrap">
                <table class="owwa-data-table">
                    <thead>
                        <tr>
                            @foreach ($sectionColumns as $key => $label)
                                <th class="{{ in_array($key, $numericKeys, true) ? 'owwa-num' : '' }}">
                                    {{ $label }}
                                </th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($sectionRows as $row)
                            <tr>
                                @foreach ($sectionColumns as $key => $label)
                                    <td class="{{ in_array($key, $numericKeys, true) ? 'owwa-num' : '' }}">
                                        @php
                                            $value = $row[$key] ?? null;
                                        @endphp
                                        @if (in_array($key, $numericKeys, true) && $value !== null && $value !== '')
                                            {{ number_format((int) $value) }}
                                        @elseif (filled($value))
                                            {{ $value }}
                                        @else
                                            <span class="owwa-cell-muted">—</span>
                                        @endif
                                    </td>
                                @endforeach
                            </tr>
                        @empty
                            <tr>
                                <td colspan="{{ count($sectionColumns) }}">
                                    <div class="owwa-empty">
                                        <p class="owwa-empty-title">No items in this category on this page</p>
                                    </div>
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        @empty
            <div class="owwa-table-wrap owwa-stock-ledger-table-wrap">
                <div class="owwa-empty" style="padding:1.5rem;">
                    <p class="owwa-empty-title">{{ $emptyTitle }}</p>
                    <p class="owwa-empty-desc">{{ $emptyDesc }}</p>
                </div>
            </div>
        @endforelse

        @if ($isEmpty && $sections === [])
            <div class="owwa-empty" style="padding:1.5rem;">
                <p class="owwa-empty-title">{{ $emptyTitle }}</p>
                <p class="owwa-empty-desc">{{ $emptyDesc }}</p>
            </div>
        @endif
    @else
        <div class="owwa-table-wrap owwa-stock-ledger-table-wrap">
            <table class="owwa-data-table">
                <thead>
                    <tr>
                        @foreach ($columns as $key => $label)
                            <th class="{{ in_array($key, $numericKeys, true) ? 'owwa-num' : '' }}">
                                {{ $label }}
                            </th>
                        @endforeach
                    </tr>
                </thead>
                <tbody>
                    @forelse ($rows as $row)
                        <tr>
                            @foreach ($columns as $key => $label)
                                <td class="{{ in_array($key, $numericKeys, true) ? 'owwa-num' : '' }}">
                                    @php
                                        $value = $row[$key] ?? null;
                                    @endphp
                                    @if (in_array($key, $numericKeys, true) && $value !== null && $value !== '')
                                        {{ number_format((int) $value) }}
                                    @elseif (filled($value))
                                        {{ $value }}
                                    @else
                                        <span class="owwa-cell-muted">—</span>
                                    @endif
                                </td>
                            @endforeach
                        </tr>
                    @empty
                        <tr>
                            <td colspan="{{ count($columns) }}">
                                <div class="owwa-empty">
                                    <p class="owwa-empty-title">{{ $emptyTitle }}</p>
                                    <p class="owwa-empty-desc">{{ $emptyDesc }}</p>
                                </div>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    @endif

    @if (is_array($pagination) && ($pagination['last'] ?? 1) > 1)
        @php
            $pageKey = (string) ($pagination['key'] ?? 'page');
            $current = (int) ($pagination['current'] ?? 1);
            $last = (int) ($pagination['last'] ?? 1);
        @endphp
        <div style="display:flex;align-items:center;justify-content:space-between;gap:0.75rem;margin-top:0.75rem;">
            <button
                type="button"
                class="fi-btn fi-btn-size-sm fi-color-gray"
                wire:click="setKpiPage('{{ $pageKey }}', {{ max(1, $current - 1) }})"
                @disabled($current <= 1)
            >
                Previous
            </button>
            <span style="font-size:0.8125rem;color:#64748b;">
                Page {{ $current }} of {{ $last }}
                @if (isset($pagination['total']))
                    ({{ number_format((int) $pagination['total']) }} total)
                @endif
            </span>
            <button
                type="button"
                class="fi-btn fi-btn-size-sm fi-color-gray"
                wire:click="setKpiPage('{{ $pageKey }}', {{ min($last, $current + 1) }})"
                @disabled($current >= $last)
            >
                Next
            </button>
        </div>
    @endif
</div>
