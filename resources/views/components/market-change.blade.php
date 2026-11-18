@props(['quote'])
@if($quote->change24h !== null)
    <span class="paper-change {{ $quote->change24h > 0 ? 'positive' : ($quote->change24h < 0 ? 'negative' : 'flat') }}" title="{{ __('market.change_24h') }}">
        <span class="sr-only">{{ __('market.change_24h') }}: {{ __($quote->change24h > 0 ? 'ui.up' : ($quote->change24h < 0 ? 'ui.down' : 'market.no_change')) }} </span><span aria-hidden="true">{{ $quote->change24h > 0 ? '+' : ($quote->change24h < 0 ? '−' : '') }}</span>{{ \Illuminate\Support\Number::format(abs($quote->change24h), 2, locale: app()->getLocale()) }}%
    </span>
@else
    <span class="paper-change flat" title="{{ __('market.change_unavailable') }}"><span class="sr-only">{{ __('market.change_unavailable') }}</span><span aria-hidden="true">—</span></span>
@endif
