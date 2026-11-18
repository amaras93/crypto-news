@props(['article', 'translation', 'content', 'seo', 'hero', 'shareLinks'])
<header class="article-heading">
    <div class="article-kicker">
        @if($categoryName = $article->category?->displayName())
            <a href="{{ route('news.index', ['locale' => app()->getLocale(), 'category' => $article->category_id]) }}">{{ $categoryName }}</a>
        @endif
        @if(! $article->isSponsored() && $article->tags->contains('slug', 'analysis'))<span>{{ __('article.analysis_tag') }}</span>@endif
        <x-sponsorship-label :article="$article"/>
        @if($article->isDemo())<span class="article-demo-label">{{ __('ui.demo_article') }}</span>@endif
    </div>
    <h1>{{ $translation->title }}</h1>
    @if($translation->excerpt)<p class="article-deck">{{ $translation->excerpt }}</p>@endif
    <div class="article-byline-row">
        <div class="article-byline">
            @php($authorName = $article->author?->name ?: __('article.author_desk'))
            <span class="paper-author-initial" aria-hidden="true">{{ mb_strtoupper(mb_substr($authorName, 0, 1)) }}</span>
            <div>
                <p class="article-author">{{ __('article.by') }} <strong>{{ $authorName }}</strong></p>
                <div class="article-dateline">
                    <span><span class="sr-only">{{ __('article.published') }} </span><time datetime="{{ $article->published_at->toIso8601String() }}">{{ $article->published_at->locale(app()->getLocale())->isoFormat('lll') }}</time></span>
                    @if($content['readingMinutes'])
                        <span class="article-reading-estimate" title="{{ __('article.reading_estimate') }}"><x-ui-icon name="clock"/>{{ trans_choice('article.reading_time', $content['readingMinutes'], ['count' => $content['readingMinutes']]) }}</span>
                    @endif
                </div>
                @if($seo['modifiedAt']->greaterThan($article->published_at))
                    <p class="article-updated">{{ __('article.updated') }} <time datetime="{{ $seo['modifiedAt']->toIso8601String() }}">{{ $seo['modifiedAt']->locale(app()->getLocale())->isoFormat('lll') }}</time></p>
                @endif
            </div>
        </div>
        <nav class="article-header-share" aria-label="{{ __('article.share') }}">
            @foreach($shareLinks as $network => $shareLink)
                @php($networkName = ['x' => 'X', 'linkedin' => 'LinkedIn', 'telegram' => 'Telegram'][$network])
                <a href="{{ $shareLink }}" target="_blank" rel="noopener noreferrer" aria-label="{{ __('article.share_on', ['network' => $networkName]) }}">{{ $networkName }}</a>
            @endforeach
            <a class="article-share-jump" href="#share-heading"><x-ui-icon name="share"/>{{ __('article.share') }}</a>
        </nav>
    </div>
    <figure class="article-hero-image">
        <img src="{{ $hero['url'] }}"
             alt="{{ $hero['fallback'] ? __('article.fallback_alt') : ($translation->image_alt ?: __('article.image_alt', ['title' => $translation->title])) }}"
             width="1200" height="740" fetchpriority="high" decoding="async"
             data-cover data-fallback="{{ $hero['fallbackUrl'] }}">
        @if(! $hero['fallback'] && $article->image_credit)<figcaption>{{ $article->image_credit }}</figcaption>@endif
    </figure>
</header>
