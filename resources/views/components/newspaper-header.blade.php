@props(['categories', 'article' => null, 'search' => ''])
<header class="newspaper-chrome paper-header" data-site-header>
    <div class="paper-market-header">
        <div class="paper-tickers">
            @foreach(['metals', 'crypto'] as $group)
                <x-market-ticker :quotes="collect($marketSnapshot['quotes'])->filter(fn ($quote) => config('markets.assets.'.$quote->asset.'.group') === $group)" :group="$group" :mode="$marketSnapshot['mode']"/>
            @endforeach
        </div>
        <div class="paper-language-control"><x-language-switcher :article="$article"/></div>
    </div>
    <div class="container">
        @if($marketSnapshot['mode'] !== 'demo')<div class="paper-ticker-sources"><x-market-source :snapshot="$marketSnapshot" :compact="true"/></div>@endif
        <div class="paper-masthead">
            <p class="paper-tagline"><span>{{ __('paper.tagline_first') }}</span><span>{{ __('paper.tagline_second') }}</span></p>
            <a class="paper-wordmark" href="{{ route('home', ['locale' => app()->getLocale()]) }}" aria-label="CryptoNews">CryptoNews</a>
            <div class="paper-edition"><time datetime="{{ now()->toDateString() }}">{{ now()->locale(app()->getLocale())->isoFormat('ddd, ll') }}</time><span>{{ __('paper.edition', ['language' => strtoupper(app()->getLocale())]) }}</span></div>
        </div>
        <div class="paper-nav-row">
            <nav class="paper-desktop-nav" aria-label="{{ __('messages.navigation') }}"><x-newspaper-navigation :categories="$categories"/></nav>
            <details class="paper-mobile-menu" data-dismissable>
                <summary aria-label="{{ __('ui.open_menu') }}"><x-ui-icon name="menu"/><span>{{ __('ui.navigate') }}</span></summary>
                <nav aria-label="{{ __('messages.navigation') }}"><x-newspaper-navigation :categories="$categories"/></nav>
            </details>
            <button class="paper-search-toggle" type="button" aria-label="{{ __('ui.search') }}" aria-controls="header-search" aria-expanded="false" data-search-toggle><x-ui-icon name="search"/></button>
            <a class="community-account-link" href="{{ route(auth()->check() ? 'account' : 'login', ['locale' => app()->getLocale()]) }}">{{ auth()->check() ? __('community.account') : __('community.login') }}</a>
        </div>
        <div class="paper-header-search" id="header-search" data-header-search hidden>
            <form method="get" action="{{ route('news.index', ['locale' => app()->getLocale()]) }}" role="search">
                <div class="search-input-wrap"><x-ui-icon name="search"/><label class="sr-only" for="global-search">{{ __('ui.search_label') }}</label><input type="search" id="global-search" name="q" maxlength="120" placeholder="{{ __('ui.search_placeholder') }}" value="{{ $search }}"></div>
                <button class="button" type="submit">{{ __('ui.search_submit') }} <x-ui-icon/></button>
            </form>
        </div>
    </div>
</header>
