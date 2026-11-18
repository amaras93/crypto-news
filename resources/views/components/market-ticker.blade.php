@props(['quotes', 'group', 'mode'])
@php
    $label = __('market.'.$group.'_ticker');
    $usableQuotes = collect($quotes)->filter(fn ($quote) => \App\Support\MarketQuote::positivePrice($quote->price));
    $updatedAt = $usableQuotes->min('providerTimestamp');
    $age = $usableQuotes->contains('status', 'stale') ? 'stale' : ($usableQuotes->contains('status', 'delayed') ? 'delayed' : null);
@endphp
<section class="market-ticker" data-market-ticker data-ticker-group="{{ $group }}" aria-label="{{ $label }}">
    <div class="market-ticker-label">
        <strong>{{ $label }}</strong>
        <small>{{ __($mode === 'demo' ? 'market.demo_label' : 'market.cached') }}@if($mode !== 'demo' && $updatedAt) · <time datetime="{{ gmdate('c', $updatedAt) }}" title="{{ __('market.last_updated') }}: {{ gmdate('Y-m-d H:i', $updatedAt) }} UTC"><span class="sr-only">{{ __('market.last_updated') }} </span>{{ gmdate('H:i', $updatedAt) }} UTC</time>@endif@if($mode !== 'demo' && $age) · {{ __('market.'.$age) }}@endif</small>
    </div>
    @if($usableQuotes->isNotEmpty())
        <div class="market-ticker-viewport" tabindex="0" role="region" aria-label="{{ $label }}">
            <div class="market-ticker-track">
                @foreach(['original', 'clone'] as $copy)
                    <div class="market-ticker-items" data-ticker-copy="{{ $copy }}" @if($copy === 'clone') aria-hidden="true" inert @endif>
                        @foreach($usableQuotes as $quote)
                            <div class="market-ticker-item" data-ticker-asset="{{ $quote->asset }}" role="group" aria-label="{{ __('market.assets.'.$quote->asset) }}" title="{{ __('market.assets.'.$quote->asset) }}">
                                <x-market-asset-icon :asset="$quote->asset"/>
                                <strong class="market-ticker-symbol">{{ $quote->symbol }}</strong>
                                <span class="market-ticker-price"><x-market-price :quote="$quote" :compact="true"/></span>
                                <x-market-change :quote="$quote"/>
                                <span class="sr-only"><x-market-status :quote="$quote"/></span>
                            </div>
                        @endforeach
                    </div>
                @endforeach
            </div>
        </div>
    @else
        <p class="market-ticker-empty" role="status">{{ __('market.unavailable') }}</p>
    @endif
</section>
