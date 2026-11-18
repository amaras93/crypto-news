@props(['articles', 'compact' => false, 'heading' => null, 'note' => null])
<section class="panel editor-picks-panel {{ $compact ? 'editor-picks-compact' : '' }}" aria-label="{{ $heading ?? __('ui.editors_picks') }}">
    <h2 class="panel-heading">{{ $heading ?? __('ui.editors_picks') }} <span class="editor-picks-arrow" aria-hidden="true">↗</span></h2>
    <p class="panel-note">{{ $note ?? __('ui.curated') }}</p>
    @forelse($articles as $pick)
        <article class="radar-item">
            @if($compact)
                <a class="sidebar-story-image" href="{{ route('news.show', ['locale' => app()->getLocale(), 'slug' => $pick->translation()->slug]) }}" tabindex="-1" aria-hidden="true"><img src="{{ $pick->imageUrl() }}" alt="" width="160" height="90" loading="lazy" decoding="async" data-cover data-fallback="{{ asset('images/editorial/markets.svg') }}"></a>
            @else
                <span class="radar-number" aria-hidden="true">{{ str_pad($loop->iteration, 2, '0', STR_PAD_LEFT) }}</span>
            @endif
            <div class="sidebar-story-copy">
                @if($compact && ($pickCategory = $pick->category?->displayName()))<span class="sidebar-story-category">{{ $pickCategory }}</span>@endif
                <h3><a href="{{ route('news.show', ['locale' => app()->getLocale(), 'slug' => $pick->translation()->slug]) }}">{{ $pick->translation()->title }}</a></h3>
                <div class="sidebar-story-meta">
                    @if($pick->isDemo()) <span class="badge demo">{{ __('ui.demo') }}</span> @endif
                    <time datetime="{{ $pick->published_at->toIso8601String() }}">{{ $pick->published_at->locale(app()->getLocale())->isoFormat('D MMM') }}</time>
                </div>
            </div>
        </article>
    @empty
        <p class="panel-note">{{ __('ui.no_picks') }}</p>
    @endforelse
</section>
