@php
    use Illuminate\Contracts\Pagination\LengthAwarePaginator as LengthAwarePaginatorContract;
    use Illuminate\Pagination\UrlWindow;

    $firstItem = $paginator->firstItem() ?? 0;
    $lastItem = $paginator->lastItem() ?? 0;
    $total = method_exists($paginator, 'total') ? ($paginator->total() ?? 0) : 0;
    $currentPage = method_exists($paginator, 'currentPage') ? $paginator->currentPage() : 1;
    $lastPage = method_exists($paginator, 'lastPage') ? $paginator->lastPage() : 1;
    $usesLivewirePagination = isset($this) && method_exists($this, 'gotoPage');

    $paginationElements = [];
    if ($paginator instanceof LengthAwarePaginatorContract && $lastPage > 1) {
        $window = UrlWindow::make($paginator);
        $paginationElements = array_values(array_filter([
            $window['first'],
            is_array($window['slider']) ? '...' : null,
            $window['slider'],
            is_array($window['last']) ? '...' : null,
            $window['last'],
        ]));
    }
@endphp

<nav role="navigation" class="owwa-pagination-nav">
    <span class="owwa-pagination-info">
        Showing {{ $firstItem }} to {{ $lastItem }} of {{ $total }} results
    </span>

    <div class="owwa-pagination-controls">
        {{-- Previous --}}
        @if (method_exists($paginator, 'onFirstPage') && $paginator->onFirstPage())
            <span class="owwa-pagination-btn owwa-pagination-disabled">Previous</span>
        @elseif ($usesLivewirePagination)
            <button type="button" wire:click="previousPage('page')" class="owwa-pagination-btn">Previous</button>
        @else
            <a href="{{ $paginator->previousPageUrl() }}" class="owwa-pagination-btn" rel="prev">Previous</a>
        @endif

        {{-- Page numbers --}}
        @if ($lastPage <= 1)
            <span class="owwa-pagination-page owwa-pagination-page-active">1</span>
        @else
            @foreach ($paginationElements as $element)
                @if (is_string($element))
                    <span class="owwa-pagination-ellipsis" aria-hidden="true">...</span>
                @elseif (is_array($element))
                    @foreach ($element as $page => $url)
                        @if ((int) $page === (int) $currentPage)
                            <span class="owwa-pagination-page owwa-pagination-page-active">{{ $page }}</span>
                        @elseif ($usesLivewirePagination)
                            <button type="button" wire:click="gotoPage({{ (int) $page }}, 'page')" class="owwa-pagination-page">{{ $page }}</button>
                        @else
                            <a href="{{ $url }}" class="owwa-pagination-page">{{ $page }}</a>
                        @endif
                    @endforeach
                @endif
            @endforeach
        @endif

        {{-- Next --}}
        @if (method_exists($paginator, 'hasMorePages') && $paginator->hasMorePages())
            @if ($usesLivewirePagination)
                <button type="button" wire:click="nextPage('page')" class="owwa-pagination-btn" rel="next">Next</button>
            @else
                <a href="{{ $paginator->nextPageUrl() }}" class="owwa-pagination-btn" rel="next">Next</a>
            @endif
        @else
            <span class="owwa-pagination-btn owwa-pagination-disabled">Next</span>
        @endif
    </div>
</nav>
