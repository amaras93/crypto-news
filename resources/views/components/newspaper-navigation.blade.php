@props(['categories'])
@php
    $locale = app()->getLocale();
    $categoryFor = fn (string $topic) => $categories->first(fn ($category) => $category->hasTopic($topic));
    $selectedCategory = $categories->firstWhere('id', (int) request('category'));
    $isGroupActive = fn (string $group) => request()->routeIs('news.index') && (request('topic') === $group
        || ($selectedCategory && in_array($selectedCategory->translation('en')?->slug, config($group === 'crypto' ? 'news.crypto_topics' : 'news.metal_topics'), true)));
@endphp
<a href="{{ route('home', ['locale' => $locale]) }}" @if(request()->routeIs('home')) aria-current="page" @endif>{{ __('ui.home') }}</a>
<a href="{{ route('news.index', ['locale' => $locale]) }}" @if(request()->routeIs('news.index') && !request('category') && !request('topic')) aria-current="page" @endif>{{ __('ui.news') }}</a>
@foreach(['crypto' => ['bitcoin', 'ethereum', 'solana', 'altcoins', 'defi', 'regulation'], 'metals' => ['gold', 'silver', 'platinum', 'palladium']] as $group => $topics)
    <details class="paper-dropdown {{ $isGroupActive($group) ? 'is-active' : '' }}" data-dismissable>
        <summary>{{ __($group === 'crypto' ? 'ui.crypto' : 'ui.precious_metals') }} <x-ui-icon name="chevron"/></summary>
        <div class="paper-subnav">
            <a href="{{ route('news.index', ['locale' => $locale, 'topic' => $group]) }}" @if(request('topic') === $group && !request('category')) aria-current="page" @endif>{{ __($group === 'crypto' ? 'ui.all_crypto_news' : 'ui.all_metals_news') }}</a>
            @foreach($topics as $topic)
                @php($category = $categoryFor($topic))
                @if($category)<a href="{{ route('news.index', ['locale' => $locale, 'category' => $category->id]) }}" @if(request()->routeIs('news.index') && (int)request('category') === $category->id) aria-current="page" @endif>{{ __('ui.'.$topic) }}</a>
                @else<span class="paper-unavailable" aria-disabled="true" title="{{ __('ui.category_unavailable') }}">{{ __('ui.'.$topic) }}</span>@endif
            @endforeach
        </div>
    </details>
@endforeach
@php($marketsCategory = $categoryFor('markets'))
@if($marketsCategory)<a href="{{ route('news.index', ['locale' => $locale, 'category' => $marketsCategory->id]) }}" @if(request()->routeIs('news.index') && (int)request('category') === $marketsCategory->id) aria-current="page" @endif>{{ __('ui.markets') }}</a>
@else<span class="paper-unavailable" aria-disabled="true" title="{{ __('ui.category_unavailable') }}">{{ __('ui.markets') }}</span>@endif
@foreach(['analysis', 'opinion'] as $topic)
    <a href="{{ route('news.index', ['locale' => $locale, 'topic' => $topic]) }}" @if(request()->routeIs('news.index') && request('topic') === $topic) aria-current="page" @endif>{{ __('paper.'.$topic) }}</a>
@endforeach
