@props(['article', 'variant' => 'card', 'priority' => false, 'headingLevel' => 2])
@php
    $translation = $article->translation();
    $categoryName = $article->category?->displayName();
    $url = route('news.show', ['locale' => app()->getLocale(), 'slug' => $translation->slug]);
    $description = $translation->excerpt ?: \Illuminate\Support\Str::limit(\Illuminate\Support\Str::squish(strip_tags($translation->body ?? '')), 180);
    $readingMinutes = in_array($variant, ['lead', 'card']) ? app(\App\Support\ArticleContent::class)->render($translation->body)['readingMinutes'] : null;
    $authorName = $article->author?->name ?: __('article.author_desk');
@endphp
<article {{ $attributes->class(['paper-story', 'paper-story-'.$variant]) }} data-story-id="{{ $article->id }}">
    <div class="paper-story-image">
        <img src="{{ $article->imageUrl() }}" alt="" width="1200" height="740" loading="{{ $priority ? 'eager' : 'lazy' }}" decoding="async" @if($priority) fetchpriority="high" @endif data-cover data-fallback="{{ asset('images/editorial/markets.svg') }}">
    </div>
    <div class="paper-story-copy">
        <div class="paper-story-kicker">
            <x-sponsorship-label :article="$article"/>
            @if($categoryName)<a href="{{ route('news.index', ['locale' => app()->getLocale(), 'category' => $article->category_id]) }}">{{ $categoryName }}</a>
            @else<span>{{ __('ui.uncategorized') }}</span>@endif
            @if($article->isDemo())<span class="paper-demo">{{ __('ui.demo_article') }}</span>@endif
        </div>
        <h{{ in_array($headingLevel, [1, 2, 3]) ? $headingLevel : 2 }} class="paper-story-title"><a class="paper-story-link" href="{{ $url }}">{{ $translation->title }}</a></h{{ in_array($headingLevel, [1, 2, 3]) ? $headingLevel : 2 }}>
        @if($variant !== 'pick' && $description)<p class="paper-story-excerpt">{{ $description }}</p>@endif
        @if(in_array($variant, ['lead', 'opinion']))
            <div class="paper-story-author">
                @if($variant === 'lead')<span class="paper-author-initial" aria-hidden="true">{{ mb_strtoupper(mb_substr($authorName, 0, 1)) }}</span>@endif
                <div><strong>{{ __('ui.by') }} {{ $authorName }}</strong><x-article-meta :article="$article" :reading-minutes="$readingMinutes" :newspaper="true"/></div>
            </div>
        @else
            <x-article-meta :article="$article" :reading-minutes="$readingMinutes" :newspaper="true"/>
        @endif
        @if(in_array($variant, ['analysis', 'opinion']))<span class="paper-read-more" aria-hidden="true">{{ __($variant === 'analysis' ? 'paper.read_analysis' : 'paper.read_opinion') }} <x-ui-icon/></span>@endif
    </div>
</article>
