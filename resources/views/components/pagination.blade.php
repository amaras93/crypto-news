@props(['paginator'])
@if($paginator->hasPages())
    <nav class="pagination" aria-label="{{ __('ui.pagination') }}">
        <p class="pagination-label">{{ __('ui.page_of', ['page' => $paginator->currentPage(), 'total' => $paginator->lastPage()]) }}</p>
        <div class="pagination-links">
            @if($paginator->onFirstPage()) <span class="page-link disabled" aria-disabled="true">{{ __('ui.previous') }}</span>
            @else <a class="page-link" href="{{ $paginator->previousPageUrl() }}" rel="prev">{{ __('ui.previous') }}</a> @endif
            @foreach($paginator->getUrlRange(max(1, $paginator->currentPage() - 2), min($paginator->lastPage(), $paginator->currentPage() + 2)) as $page => $url)
                @if($page === $paginator->currentPage())
                    <span class="page-link" aria-current="page">{{ $page }}</span>
                @else <a class="page-link" href="{{ $url }}" aria-label="{{ __('ui.page_of', ['page' => $page, 'total' => $paginator->lastPage()]) }}">{{ $page }}</a> @endif
            @endforeach
            @if($paginator->hasMorePages()) <a class="page-link" href="{{ $paginator->nextPageUrl() }}" rel="next">{{ __('ui.next') }}</a>
            @else <span class="page-link disabled" aria-disabled="true">{{ __('ui.next') }}</span> @endif
        </div>
    </nav>
@endif
