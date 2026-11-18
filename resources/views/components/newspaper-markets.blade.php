<section class="paper-markets" aria-label="{{ __('paper.snapshot') }}">
    <h2>{{ __('paper.snapshot') }} <small>{{ __($marketSnapshot['mode'] === 'demo' ? 'ui.demo' : 'market.cached') }}</small></h2>
    <div class="paper-market-rows">
        @foreach(collect($marketSnapshot['quotes'])->filter(fn ($quote) => in_array($quote->asset, config('markets.snapshot_assets'), true)) as $quote)
            @php($decoration = collect(config('markets.demo'))->firstWhere('name', $quote->asset))
            <div class="paper-market-row" data-market-asset="{{ $quote->asset }}">
                <span class="paper-market-name"><span class="paper-coin coin-{{ $quote->asset }}" aria-hidden="true">{{ $decoration['icon'] }}</span><span title="{{ __('market.assets.'.$quote->asset) }}">{{ $quote->unit === 'troy_ounce' ? __('market.assets.'.$quote->asset) : $quote->symbol }}</span></span>
                <span class="paper-market-price"><x-market-price :quote="$quote"/><x-market-status :quote="$quote"/></span>
                <x-market-change :quote="$quote"/>
            </div>
        @endforeach
    </div>
    <x-market-source :snapshot="$marketSnapshot"/>
</section>
