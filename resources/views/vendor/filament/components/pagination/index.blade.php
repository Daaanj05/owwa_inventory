@props([
    'currentPageOptionProperty' => 'tableRecordsPerPage',
    'extremeLinks' => false,
    'paginator',
    'pageOptions' => [],
])

@php
    use Illuminate\Contracts\Pagination\CursorPaginator;
    use Illuminate\Pagination\UrlWindow;

    $isCursor = $paginator instanceof CursorPaginator;
    $isLengthAware = $paginator instanceof \Illuminate\Pagination\LengthAwarePaginator;

    $currentPage = method_exists($paginator, 'currentPage') ? (int) $paginator->currentPage() : 1;
    $lastPage = $isLengthAware ? (int) $paginator->lastPage() : 1;

    $firstItem = $isLengthAware ? ($paginator->firstItem() ?? 0) : 0;
    $lastItem = $isLengthAware ? ($paginator->lastItem() ?? 0) : 0;
    $total = $isLengthAware ? (int) $paginator->total() : 0;

    $previousWireClick = null;
    $nextWireClick = null;

    if ($isCursor) {
        $previousWireClick = $paginator->previousCursor()
            ? "setPage('{$paginator->previousCursor()->encode()}', '{$paginator->getCursorName()}')"
            : null;

        $nextWireClick = $paginator->nextCursor()
            ? "setPage('{$paginator->nextCursor()->encode()}', '{$paginator->getCursorName()}')"
            : null;
    } else {
        $previousWireClick = "previousPage('{$paginator->getPageName()}')";
        $nextWireClick = "nextPage('{$paginator->getPageName()}')";
    }

    $pageOptions = array_values(array_unique(array_map('strval', $pageOptions)));
@endphp

<nav
    aria-label="{{ __('filament::components/pagination.label') }}"
    role="navigation"
    {{ $attributes->class(['fi-pagination']) }}
>
    <span class="fi-pagination-overview">
        @if ($isLengthAware)
            Showing {{ \Illuminate\Support\Number::format($firstItem) }} to {{ \Illuminate\Support\Number::format($lastItem) }} of {{ \Illuminate\Support\Number::format($total) }} results
        @else
            Showing results
        @endif
    </span>

    @if (count($pageOptions) > 1)
        <div class="fi-pagination-records-per-page-select-ctn">
            <label class="fi-pagination-records-per-page-select">
                <x-filament::input.wrapper :prefix="'Per page'">
                    <x-filament::input.select :wire:model.live="$currentPageOptionProperty">
                        @foreach ($pageOptions as $option)
                            <option value="{{ $option }}">{{ $option }}</option>
                        @endforeach
                    </x-filament::input.select>
                </x-filament::input.wrapper>
            </label>
        </div>
    @endif

    <x-filament::button
        color="gray"
        rel="prev"
        :disabled="method_exists($paginator, 'onFirstPage') ? $paginator->onFirstPage() : false"
        :wire:click="$previousWireClick"
        :wire:key="$this->getId() . '.pagination.previous'"
        class="fi-pagination-previous-btn"
    >
        Previous
    </x-filament::button>

    <ol class="fi-pagination-items">
        @if ($isLengthAware)
            @if ($lastPage <= 1)
                <li class="fi-pagination-item fi-active">
                    <button type="button" class="fi-pagination-item-btn" disabled>
                        <span class="fi-pagination-item-label">1</span>
                    </button>
                </li>
            @else
                @php
                    $window = UrlWindow::make($paginator);
                    $paginationElements = array_values(array_filter([
                        $window['first'],
                        is_array($window['slider']) ? '...' : null,
                        $window['slider'],
                        is_array($window['last']) ? '...' : null,
                        $window['last'],
                    ]));
                @endphp
                @foreach ($paginationElements as $element)
                    @if (is_string($element))
                        <li class="fi-pagination-item fi-pagination-item--ellipsis" aria-hidden="true">
                            <span class="fi-pagination-item-label">...</span>
                        </li>
                    @elseif (is_array($element))
                        @foreach ($element as $page => $url)
                            <li @class(['fi-pagination-item', 'fi-active' => (int) $page === $currentPage])>
                                <button
                                    type="button"
                                    class="fi-pagination-item-btn"
                                    wire:click='gotoPage({{ (int) $page }}, {{ json_encode($paginator->getPageName()) }})'
                                    wire:key="{{ $this->getId() }}.pagination.{{ $paginator->getPageName() }}.{{ (int) $page }}"
                                >
                                    <span class="fi-pagination-item-label">{{ \Illuminate\Support\Number::format($page) }}</span>
                                </button>
                            </li>
                        @endforeach
                    @endif
                @endforeach
            @endif
        @else
            <li class="fi-pagination-item fi-active">
                <button type="button" class="fi-pagination-item-btn" disabled>
                    <span class="fi-pagination-item-label">1</span>
                </button>
            </li>
        @endif
    </ol>

    <x-filament::button
        color="gray"
        rel="next"
        :disabled="method_exists($paginator, 'hasMorePages') ? (! $paginator->hasMorePages()) : false"
        :wire:click="$nextWireClick"
        :wire:key="$this->getId() . '.pagination.next'"
        class="fi-pagination-next-btn"
    >
        Next
    </x-filament::button>
</nav>

