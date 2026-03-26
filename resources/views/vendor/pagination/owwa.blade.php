@if ($paginator->hasPages())
    <nav role="navigation" class="owwa-pagination-nav">
        <span class="owwa-pagination-info">
            {{ $paginator->firstItem() }}–{{ $paginator->lastItem() }} of {{ $paginator->total() }}
        </span>
        <ul class="owwa-pagination-list">
            @if ($paginator->onFirstPage())
                <span class="owwa-pagination-item owwa-pagination-disabled">&laquo; Prev</span>
            @else
                <a href="{{ $paginator->previousPageUrl() }}" class="owwa-pagination-item" rel="prev">&laquo; Prev</a>
            @endif
            <span class="owwa-pagination-item owwa-pagination-active">Page {{ $paginator->currentPage() }} of {{ $paginator->lastPage() }}</span>
            @if ($paginator->hasMorePages())
                <a href="{{ $paginator->nextPageUrl() }}" class="owwa-pagination-item" rel="next">Next &raquo;</a>
            @else
                <span class="owwa-pagination-item owwa-pagination-disabled">Next &raquo;</span>
            @endif
        </ul>
    </nav>
@endif
