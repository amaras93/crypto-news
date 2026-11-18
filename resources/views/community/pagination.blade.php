@if($paginator->hasPages())
    <nav class="community-pagination" aria-label="{{ __('community.pagination') }}">
        <button type="button" wire:click="previousPage('{{ $paginator->getPageName() }}')" @disabled($paginator->onFirstPage())>{{ __('community.previous') }}</button>
        <span>{{ __('community.page', ['current' => $paginator->currentPage(), 'last' => $paginator->lastPage()]) }}</span>
        <button type="button" wire:click="nextPage('{{ $paginator->getPageName() }}')" @disabled(!$paginator->hasMorePages())>{{ __('community.next') }}</button>
    </nav>
@endif

