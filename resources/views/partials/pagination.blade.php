{{-- Pagination links in the app's own style (the framework default expects Tailwind). Use: $paginator->links('partials.pagination') --}}
@if ($paginator->hasPages())
    <nav class="pagination" role="navigation" aria-label="Pagination">
        @if ($paginator->onFirstPage())
            <span class="page-link disabled">&lsaquo; Prev</span>
        @else
            <a class="page-link" href="{{ $paginator->previousPageUrl() }}" rel="prev">&lsaquo; Prev</a>
        @endif

        @foreach ($elements as $element)
            @if (is_string($element))
                <span class="page-link disabled">{{ $element }}</span>
            @endif

            @if (is_array($element))
                @foreach ($element as $page => $url)
                    @if ($page == $paginator->currentPage())
                        <span class="page-link active" aria-current="page">{{ $page }}</span>
                    @else
                        <a class="page-link" href="{{ $url }}">{{ $page }}</a>
                    @endif
                @endforeach
            @endif
        @endforeach

        @if ($paginator->hasMorePages())
            <a class="page-link" href="{{ $paginator->nextPageUrl() }}" rel="next">Next &rsaquo;</a>
        @else
            <span class="page-link disabled">Next &rsaquo;</span>
        @endif
    </nav>
@endif
