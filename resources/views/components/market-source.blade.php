@props(['snapshot', 'compact' => false])
@if($snapshot['mode'] === 'demo')
    @unless($compact)<p class="market-source-note">{{ __('ui.demo_prices_note') }}</p>@endunless
@else
    <div class="market-source-note">
        @foreach(['coingecko' => ['name' => 'CoinGecko', 'url' => 'https://www.coingecko.com/en/api'], 'goldapi' => ['name' => 'goldApi.dev', 'url' => 'https://goldapi.dev']] as $provider => $source)
            @if(collect($snapshot['quotes'])->contains(fn ($quote) => $quote->provider === $provider && $quote->price !== null))
                <a href="{{ $source['url'] }}" rel="noopener noreferrer" @if($provider === 'coingecko') lang="en" aria-label="{{ __('market.provided_by', ['provider' => $source['name']]) }}" @endif>{{ $provider === 'coingecko' ? __('market.coingecko_credit') : __('market.provided_by', ['provider' => $source['name']]) }}</a>
            @endif
        @endforeach
    </div>
@endif
@unless($compact)<p class="market-source-note">{{ __('market.currency_note') }}</p>@endunless
