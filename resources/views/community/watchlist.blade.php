@extends('layouts.app')
@section('content')
<section class="community-page engagement-page">
    <h1>{{ __('engagement.watchlist') }}</h1>
    <x-account-navigation/><x-community-feedback/>
    <form class="engagement-inline-form" method="post" action="{{ route('account.watchlist.store', ['locale' => app()->getLocale()]) }}">
        @csrf
        <label for="asset">{{ __('engagement.asset') }}</label>
        <select id="asset" name="asset" required>
            @foreach(config('markets.assets') as $key => $definition)<option value="{{ $key }}">{{ __('market.assets.'.$key) }} · {{ $definition['symbol'] }}</option>@endforeach
        </select>
        <button type="submit">{{ __('engagement.add') }}</button>
    </form>
    <p class="community-note">{{ __('engagement.watchlist_limit') }}</p>
    @if($snapshot['mode'] === 'demo')<p class="community-notice">{{ __('market.demo_label') }}</p>@endif
    <div class="engagement-watchlist">
        @forelse($items as $item)
            @php($quote = $quotes->get($item->asset))
            <article class="engagement-watch-item">
                <div class="engagement-asset"><x-market-asset-icon :asset="$item->asset"/><strong>{{ __('market.assets.'.$item->asset) }}</strong><span>{{ config('markets.assets.'.$item->asset.'.symbol') }}</span></div>
                <div>@if($quote)<x-market-price :quote="$quote"/><x-market-change :quote="$quote"/><x-market-status :quote="$quote"/>@else{{ __('market.unavailable') }}@endif</div>
                <div class="engagement-actions">
                    @foreach(['up', 'down'] as $direction)
                        <form method="post" action="{{ route('account.watchlist.move', ['locale' => app()->getLocale(), 'item' => $item]) }}">
                            @csrf @method('patch')<input type="hidden" name="direction" value="{{ $direction }}"><button type="submit" aria-label="{{ __('engagement.'.$direction) }}: {{ __('market.assets.'.$item->asset) }}">{{ __('engagement.'.$direction) }}</button>
                        </form>
                    @endforeach
                    <form method="post" action="{{ route('account.watchlist.destroy', ['locale' => app()->getLocale(), 'item' => $item]) }}">@csrf @method('delete')<button type="submit">{{ __('community.delete') }}</button></form>
                </div>
            </article>
        @empty
            <p>{{ __('engagement.empty_watchlist') }}</p>
        @endforelse
    </div>
    <x-market-source :snapshot="$snapshot"/>
    @if($related->isNotEmpty())<section class="community-history"><h2>{{ __('engagement.related_news') }}</h2><div class="engagement-related">@foreach($related as $story)<x-newspaper-story :article="$story" :heading-level="3"/>@endforeach</div></section>@endif
</section>
@endsection

