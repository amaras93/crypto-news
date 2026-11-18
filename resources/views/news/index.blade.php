@extends('layouts.app')

@section('content')
    <header class="paper-listing-header">
        <div><p class="paper-feature-label">{{ __('paper.tagline_second') }}</p><h1>{{ __('ui.all_news') }}</h1><p>{{ __('ui.search_hint') }}</p></div>
        <span>{{ __('ui.newest') }}</span>
    </header>
    <form class="filters-form" method="get" action="{{ route('news.index', ['locale' => app()->getLocale()]) }}" aria-label="{{ __('ui.filters') }}" role="search">
        <div class="search-input-wrap"><x-ui-icon name="search"/><label class="sr-only" for="news-search">{{ __('ui.search_label') }}</label><input id="news-search" type="search" name="q" maxlength="120" value="{{ $search }}" placeholder="{{ __('ui.search_placeholder') }}"></div>
        @if($topic)<input type="hidden" name="topic" value="{{ $topic }}">@endif
        <label><span class="sr-only">{{ __('ui.category') }}</span><select name="category"><option value="">{{ __('ui.all_categories') }}</option>@foreach($categories as $category)<option value="{{ $category->id }}" @selected($categoryId === $category->id)>{{ $category->displayName() }}</option>@endforeach</select></label>
        <button class="button" type="submit">{{ __('ui.apply_filters') }} <x-ui-icon/></button>
    </form>
    <nav class="chip-bar" aria-label="{{ __('ui.all_topics') }}">
        <a class="chip {{ !$categoryId && !$topic ? 'active' : '' }}" href="{{ route('news.index', array_filter(['locale' => app()->getLocale(), 'q' => $search])) }}" @if(!$categoryId && !$topic) aria-current="true" @endif>{{ __('ui.all_topics') }}</a>
        @if($topic)<span class="chip active">{{ __($topic === 'metals' ? 'ui.precious_metals' : ($topic === 'crypto' ? 'ui.crypto' : 'paper.'.$topic)) }}</span>@endif
        @foreach($categories as $category)
            <a class="chip {{ $categoryId === $category->id ? 'active' : '' }}" href="{{ route('news.index', array_filter(['locale' => app()->getLocale(), 'category' => $category->id, 'q' => $search, 'topic' => in_array($topic, ['crypto', 'analysis', 'opinion']) ? $topic : null])) }}" @if($categoryId === $category->id) aria-current="true" @endif>{{ $category->displayName() }}</a>
        @endforeach
    </nav>
    <div class="result-summary">
        <span>@if($search !== '')<strong>{{ __('ui.results_for', ['query' => $search]) }}</strong> · @endif{{ trans_choice('ui.results', $articles->total(), ['count' => $articles->total()]) }}</span>
        @if($search !== '' || $categoryId || $topic)<a class="text-link" href="{{ route('news.index', ['locale' => app()->getLocale()]) }}">{{ __('ui.clear_filters') }} <x-ui-icon name="close"/></a>@endif
    </div>
    <section class="paper-listing" aria-label="{{ __('ui.latest') }}">
        @if($articles->isNotEmpty())
            @php($featuredStories = $articles->onFirstPage() ? $articles->getCollection()->take(3) : collect())
            @if($featuredStories->isNotEmpty())
                <div class="paper-listing-feature {{ $featuredStories->count() === 1 ? 'single-feature' : '' }}">
                    <x-newspaper-story :article="$featuredStories->first()" variant="lead" :priority="true"/>
                    @if($featuredStories->count() > 1)<div class="paper-listing-support">@foreach($featuredStories->skip(1) as $story)<x-newspaper-story :article="$story" :heading-level="3"/>@endforeach<x-ad-slot placement="news_sidebar"/></div>@endif
                </div>
            @endif
            @if($articles->count() > $featuredStories->count())
                @php($remainingStories = $articles->getCollection()->skip($featuredStories->count())->values())
                <div class="paper-news-grid paper-listing-grid">@foreach($remainingStories as $story)<x-newspaper-story :article="$story"/>@if($loop->iteration === 4 && $remainingStories->count() > 4)<x-ad-slot placement="news_inline" class="paper-ad-in-grid"/>@endif @endforeach</div>
            @endif
            <x-pagination :paginator="$articles"/>
        @else
            <x-empty-state :filtered="$search !== '' || (bool) $categoryId || (bool) $topic"/>
        @endif
    </section>
    <x-newsletter/>
@endsection
