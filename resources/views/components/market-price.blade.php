@props(['quote', 'compact' => false])
@if($quote->price !== null)
    <span class="market-price-number"><span class="sr-only">{{ $quote->currency }} </span>&#36;{{ \Illuminate\Support\Number::format($quote->price, $quote->unit === 'coin' && $quote->price < 1 ? 6 : 2, locale: app()->getLocale()) }}</span>
    @if($quote->unit === 'troy_ounce')<small class="{{ $compact ? 'sr-only' : 'market-unit' }}">{{ __('market.usd_troy_ounce') }}</small>@endif
    @if($quote->unit === 'pound')<small class="{{ $compact ? 'market-ticker-unit' : 'market-unit' }}">{{ __('market.usd_pound') }}</small>@endif
@else
    <span class="market-unavailable-price" aria-label="{{ __('market.unavailable') }}">—</span>
@endif
