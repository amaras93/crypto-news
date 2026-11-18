@props(['article', 'variant' => 'standard', 'priority' => false, 'headingLevel' => 2])
@php
    $translation = $article->translation();
    $categoryName = $article->category?->displayName();
    $url = route('news.show', ['locale' => app()->getLocale(), 'slug' => $translation->slug]);
    $description = $translation->excerpt ?: \Illuminate\Support\Str::limit(
        \Illuminate\Support\Str::squish(strip_tags($translation->body ?? '')),
        180,
    );
@endphp
<article {{ $attributes->class(['article-card', 'card-hero' => $variant === 'hero', 'card-compact' => $variant === 'compact']) }}>
    <div class="cover">
        <img src="{{ $article->imageUrl() }}" alt="" width="1200" height="740"
             loading="{{ $priority ? 'eager' : 'lazy' }}" decoding="async"
             @if($priority) fetchpriority="high" @endif
             data-cover data-fallback="{{ asset('images/editorial/markets.svg') }}">
    </div>
    <div class="card-body">
        @if($variant === 'hero')
            <span class="hero-topline"><span class="status-dot" aria-hidden="true"></span>{{ __('ui.featured') }}</span>
        @endif
        <div class="badge-row">
            <x-sponsorship-label :article="$article"/>
            @if($categoryName)
                <a class="badge" href="{{ route('news.index', ['locale' => app()->getLocale(), 'category' => $article->category_id]) }}">{{ $categoryName }}</a>
            @else
                <span class="badge">{{ __('ui.uncategorized') }}</span>
            @endif
            @if($article->isDemo()) <span class="badge demo">{{ __('ui.demo_article') }}</span> @endif
        </div>
        <h{{ $headingLevel === 3 ? 3 : 2 }} class="card-title"><a class="card-link" href="{{ $url }}">{{ $translation->title }}</a></h{{ $headingLevel === 3 ? 3 : 2 }}>
        @if($description) <p class="card-excerpt">{{ $description }}</p> @endif
        <div class="card-footer">
            <x-article-meta :article="$article"/>
            @if($variant === 'hero')
                <span class="circle-arrow" aria-hidden="true"><x-ui-icon/></span>
            @endif
        </div>
    </div>
</article>
