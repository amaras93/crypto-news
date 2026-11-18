@props(['compact' => false])
<section {{ $attributes->class(['market-mini panel' => $compact, 'market-section' => !$compact]) }} aria-label="{{ __('ui.market_overview') }}">
    @if($compact)
        <h2 class="panel-heading">{{ __('ui.market_overview') }}</h2>
        <p class="panel-note">{{ __($marketSnapshot['mode'] === 'demo' ? 'ui.market_note' : 'market.cached') }}</p>
    @else
        <x-section-heading :title="__('ui.market_overview')"><span class="badge subtle">{{ __($marketSnapshot['mode'] === 'demo' ? 'ui.demo_prices' : 'market.cached') }}</span></x-section-heading>
    @endif
    <div class="market-grid">
        @foreach(collect($marketSnapshot['quotes'])->filter(fn ($quote) => in_array($quote->asset, config('markets.snapshot_assets'), true)) as $quote)
            @php($decoration = collect(config('markets.demo'))->firstWhere('name', $quote->asset))
            <div class="market-card" data-market-asset="{{ $quote->asset }}">
                <div class="market-card-top">
                    <div class="market-identity">
                        <span class="coin-icon {{ $decoration['color'] }}" aria-hidden="true">{{ $decoration['icon'] }}</span>
                        <div class="market-name">{{ __('market.assets.'.$quote->asset) }}<span class="market-symbol">{{ $quote->symbol }}</span></div>
                    </div>
                    <x-market-change :quote="$quote"/>
                </div>
                <div class="market-card-bottom">
                    <span class="market-price"><x-market-price :quote="$quote"/><x-market-status :quote="$quote"/></span>
                    @if($marketSnapshot['mode'] === 'demo')
                        <svg class="market-spark {{ $quote->change24h < 0 ? 'negative' : '' }}" viewBox="0 0 112 38" aria-hidden="true"><polyline points="{{ $decoration['line'] }}" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round" stroke-linecap="round"/></svg>
                    @endif
                </div>
            </div>
        @endforeach
    </div>
    <x-market-source :snapshot="$marketSnapshot"/>
</section>
