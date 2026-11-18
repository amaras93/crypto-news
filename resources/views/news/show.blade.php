@extends('layouts.app')

@section('content')
    @php
        $sidebarRelated = $related->count() > 3 ? $related->take(1) : $related->take(0);
        $relatedCards = $related->skip($sidebarRelated->count());
        $sidebarPicks = $picks->whereNotIn('id', $related->modelKeys())->take(3);
    @endphp
    <progress class="reading-progress" data-reading-progress max="100" value="0" aria-label="{{ __('article.progress') }}" hidden></progress>
    <nav class="breadcrumbs" aria-label="{{ __('article.breadcrumbs') }}">
        <ol>
            @foreach($seo['breadcrumbs'] as $crumb)
                <li>
                    @if($loop->last)<span aria-current="page">{{ __('article.article') }}</span>
                    @else <a href="{{ $crumb['url'] }}">{{ $crumb['name'] }}</a><span aria-hidden="true">/</span> @endif
                </li>
            @endforeach
        </ol>
    </nav>
    <article class="article-page" id="article-top" tabindex="-1" data-reading-article>
        <x-article-hero :article="$article" :translation="$translation" :content="$content" :seo="$seo" :hero="$hero" :share-links="$shareLinks"/>
        <x-sponsorship-label :article="$article" :full="true"/>
        @if($article->isDemo())<div class="demo-notice article-demo-notice">{{ __('ui.demo_notice') }}</div>@endif
        @if($article->ai_generation_run_id)<p class="article-editorial-note">{{ __('article.ai_disclosure') }}</p>@endif
        @auth
            <form class="engagement-bookmark" method="post" action="{{ route('account.saved.store', ['locale' => app()->getLocale(), 'article' => $article->id]) }}">@csrf<button type="submit">{{ __('engagement.save_article') }}</button></form>
        @else
            <p class="engagement-bookmark"><a href="{{ route('login', ['locale' => app()->getLocale()]) }}">{{ __('engagement.save_article') }}</a></p>
        @endauth
        <div class="article-layout">
            <div class="article-reader">
                <x-ad-slot placement="article_before_body"/>
                <x-article-summary :translation="$translation"/>
                <div class="article-reading-surface">
                    <x-article-toc :headings="$content['toc']" class="mobile-article-toc"/>
                    @php($adSegments = app(\App\Support\AdvertisingService::class)->articleSegments($content['html']))
                    <div class="article-body" id="article-content">{{ $adSegments['before'] }}@if($adSegments['can_split'])<x-ad-slot placement="article_inline"/>{{ $adSegments['after'] }}@endif</div>
                    <x-ad-slot placement="article_after_body"/>
                    <footer class="article-footer" data-reading-end>
                        @if($articleTags->isNotEmpty())
                            <div class="article-tags" aria-label="{{ __('article.tags') }}">
                                <span class="article-tags-label">{{ __('article.tags') }}</span>
                                @foreach($articleTags as $tag)<span class="chip">#{{ $tag->slug === 'analysis' ? __('article.analysis_tag') : $tag->name }}</span>@endforeach
                            </div>
                        @endif
                        <section class="article-share" aria-labelledby="share-heading">
                            <div class="share-section-heading"><span class="share-heading-icon" aria-hidden="true"><x-ui-icon name="share"/></span><h2 id="share-heading" tabindex="-1">{{ __('article.share') }}</h2></div>
                            <div class="share-actions">
                                @foreach($shareLinks as $network => $shareLink)
                                    @php($networkName = ['x' => 'X', 'linkedin' => 'LinkedIn', 'telegram' => 'Telegram'][$network])
                                    <a class="share-button share-{{ $network }}" href="{{ $shareLink }}" target="_blank" rel="noopener noreferrer" aria-label="{{ __('article.share_on', ['network' => $networkName]) }}">{{ $networkName }} <x-ui-icon/></a>
                                @endforeach
                                <button class="share-button share-copy" type="button" data-copy-url="{{ $seo['canonical'] }}" data-copy-success="{{ __('article.copied') }}" data-copy-error="{{ __('article.copy_failed') }}" hidden><x-ui-icon name="copy"/>{{ __('article.copy_url') }}</button>
                            </div>
                            <label class="article-url-label" for="article-url">{{ __('article.article_url') }}</label>
                            <input class="article-url-input" type="url" id="article-url" value="{{ $seo['canonical'] }}" readonly spellcheck="false">
                            <p class="copy-status" data-copy-status role="status" aria-live="polite"></p>
                        </section>
                        <a class="text-link article-back-link" href="{{ route('news.index', ['locale' => app()->getLocale()]) }}"><x-ui-icon name="back"/>{{ __('ui.back_news') }}</a>
                    </footer>
                </div>
            </div>
            <aside class="article-sidebar">
                <x-ad-slot placement="article_sidebar"/>
                <x-article-toc :headings="$content['toc']"/>
                @if($sidebarPicks->isNotEmpty())
                    <section class="article-sidebar-section" aria-label="{{ __('paper.editors_picks') }}">
                        <h2>{{ __('paper.editors_picks') }}</h2>
                        @foreach($sidebarPicks as $story)<x-newspaper-story :article="$story" variant="pick" :heading-level="3"/>@endforeach
                    </section>
                @endif
                @if($sidebarRelated->isNotEmpty())
                    <section class="article-sidebar-section" aria-label="{{ __('article.related') }}">
                        <h2>{{ __('article.related') }}</h2>
                        @foreach($sidebarRelated as $story)<x-newspaper-story :article="$story" variant="pick" :heading-level="3"/>@endforeach
                    </section>
                @endif
                <x-newspaper-markets/>
                <section class="article-sidebar-section article-topics-panel">
                    <h2>{{ __('article.topics') }}</h2>
                    <div class="article-topic-links">
                        @foreach($categories->filter(fn ($category) => collect(['bitcoin', 'ethereum', 'gold', 'silver'])->contains(fn ($topic) => $category->hasTopic($topic))) as $marketCategory)
                            <a class="chip" href="{{ route('news.index', ['locale' => app()->getLocale(), 'category' => $marketCategory->id]) }}">{{ $marketCategory->displayName() }} <x-ui-icon/></a>
                        @endforeach
                    </div>
                </section>
            </aside>
        </div>
    </article>
    <livewire:article-comments :article-id="$article->id" :locale="app()->getLocale()"/>
    @if($relatedCards->isNotEmpty())
        <section class="article-related" aria-label="{{ __('article.related') }}">
            <div class="paper-section-head"><h2>{{ __('article.related') }}</h2></div>
            <p class="article-related-note">{{ __('article.related_note') }}</p>
            <div class="related-grid">@foreach($relatedCards as $story)<x-newspaper-story :article="$story" :heading-level="3"/>@endforeach</div>
        </section>
    @endif
    <a class="back-to-top" href="#article-top" data-back-top aria-label="{{ __('article.back_top') }}" hidden><x-ui-icon name="up"/></a>
@endsection
