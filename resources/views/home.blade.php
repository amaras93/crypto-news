@extends('layouts.app')

@section('content')
    <p class="sr-only">{{ __('messages.welcome') }}</p>
    @if($breaking)
        <aside class="paper-breaking" aria-label="{{ __('paper.breaking') }}">
            <strong>{{ __('paper.breaking') }}</strong>
            <a href="{{ route('news.show', ['locale' => app()->getLocale(), 'slug' => $breaking->translation()->slug]) }}">{{ $breaking->translation()->title }}</a>
            <x-sponsorship-label :article="$breaking"/>
            @if($breaking->isDemo())<span class="paper-demo">{{ __('ui.demo_article') }}</span>@endif
            <time datetime="{{ $breaking->published_at->toIso8601String() }}">{{ $breaking->published_at->locale(app()->getLocale())->diffForHumans() }}</time>
        </aside>
    @endif
    @if($featured)
        <section class="paper-front {{ $opinion ? 'has-opinion' : '' }}" aria-label="{{ __('paper.frontpage') }}">
            <x-newspaper-story :article="$featured" variant="lead" :priority="true" :heading-level="1"/>
            <aside class="paper-front-sidebar">
                <x-newspaper-markets/>
                <x-ad-slot placement="home_sidebar"/>
                @if($picks->isNotEmpty())
                    <section class="paper-picks" aria-label="{{ __('paper.editors_picks') }}">
                        <h2>{{ __('paper.editors_picks') }}</h2>
                        @foreach($picks as $story)<x-newspaper-story :article="$story" variant="pick" :heading-level="3"/>@endforeach
                    </section>
                @endif
            </aside>
            @if($opinion)
                <aside class="paper-opinion" aria-label="{{ __('paper.opinion') }}"><h2>{{ __('paper.opinion') }}</h2><x-newspaper-story :article="$opinion" variant="opinion" :heading-level="3"/></aside>
            @endif
        </section>
    @else
        <h1 class="sr-only">{{ __('paper.frontpage') }}</h1><x-empty-state/>
    @endif
    @if($featured && $latest->count() >= 2)<x-ad-slot placement="home_after_hero"/>@endif
    <div class="paper-latest-layout {{ $analysis ? 'has-analysis' : '' }}">
        <section class="paper-latest" aria-labelledby="latest-heading">
            <div class="paper-section-head">
                <h2 id="latest-heading">{{ __('paper.latest') }}</h2>
                <nav class="paper-section-tabs" aria-label="{{ __('ui.all_topics') }}">
                    <a href="{{ route('news.index', ['locale' => app()->getLocale()]) }}" class="active">{{ __('paper.all') }}</a>
                    @foreach(['bitcoin', 'ethereum', 'markets', 'gold'] as $topic)
                        @php($topicCategory = $categories->first(fn ($category) => $category->hasTopic($topic)))
                        @if($topicCategory)<a href="{{ route('news.index', $topic === 'gold' ? ['locale' => app()->getLocale(), 'topic' => 'metals'] : ['locale' => app()->getLocale(), 'category' => $topicCategory->id]) }}">{{ __($topic === 'gold' ? 'ui.gold_silver' : 'ui.'.$topic) }}</a>@endif
                    @endforeach
                    <a href="{{ route('news.index', ['locale' => app()->getLocale(), 'topic' => 'analysis']) }}">{{ __('paper.analysis') }}</a>
                </nav>
            </div>
            @if($latest->isNotEmpty())<div class="paper-news-grid paper-home-grid">@foreach($latest as $story)<x-newspaper-story :article="$story" :heading-level="3"/>@endforeach</div>
            @else<p class="paper-note">{{ __('ui.no_stories_note') }}</p>@endif
        </section>
        @if($analysis)
            <aside class="paper-analysis" aria-label="{{ __('paper.analysis') }}"><span class="paper-feature-label">{{ __('paper.analysis') }}</span><x-newspaper-story :article="$analysis" variant="analysis"/></aside>
        @endif
    </div>
    @if($latest->count() >= 4)<x-ad-slot placement="home_between_news"/>@endif
    <x-newsletter/>
@endsection
