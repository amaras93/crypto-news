@extends('layouts.app')
@section('content')
<section class="community-page engagement-page">
    <h1>{{ __('engagement.alerts') }}</h1><x-account-navigation/><x-community-feedback/>
    @unless($alertsActive)<p class="community-notice">{{ __('engagement.alerts_inactive') }}</p>@endunless
    <p>{{ __('engagement.crossing_help') }}</p><p class="community-note">{{ __('engagement.price_alert_note') }}</p>
    <details class="engagement-details" @if($alerts->isEmpty()) open @endif><summary>{{ __('engagement.create_alert') }}</summary><x-price-alert-form/></details>
    @forelse($alerts as $alert)
        <article class="community-history-item">
            <h2>{{ __('market.assets.'.$alert->asset) }} · {{ __('engagement.'.$alert->direction) }} {{ rtrim(rtrim($alert->target_price, '0'), '.') }} {{ __($alert->unit === 'troy_ounce' ? 'market.usd_troy_ounce' : ($alert->unit === 'pound' ? 'market.usd_pound' : 'engagement.usd_coin')) }}</h2>
            <p class="community-note">{{ __('engagement.'.($alert->enabled ? 'enabled' : 'paused')) }}@if($alert->last_triggered_at) · {{ __('engagement.last_triggered') }}: {{ $alert->last_triggered_at->locale(app()->getLocale())->isoFormat('lll') }}@endif</p>
            <details><summary>{{ __('engagement.edit_alert') }}</summary><x-price-alert-form :alert="$alert"/></details>
            <div class="engagement-actions">
                @if($alert->enabled)<form method="post" action="{{ route('account.alerts.pause', ['locale' => app()->getLocale(), 'alert' => $alert]) }}">@csrf @method('patch')<button type="submit">{{ __('engagement.pause') }}</button></form>@endif
                <form method="post" action="{{ route('account.alerts.destroy', ['locale' => app()->getLocale(), 'alert' => $alert]) }}">@csrf @method('delete')<button type="submit">{{ __('community.delete') }}</button></form>
            </div>
        </article>
    @empty
        <p>{{ __('engagement.no_alerts') }}</p>
    @endforelse
    <x-pagination :paginator="$alerts"/>
    <section class="community-history"><h2>{{ __('engagement.triggered_alerts') }}</h2>
        @forelse($events as $event)
            <article class="community-history-item"><p>{{ __('engagement.alert_message', ['asset' => __('market.assets.'.$event->asset), 'direction' => __('engagement.'.$event->direction), 'target' => rtrim(rtrim($event->target_price, '0'), '.'), 'unit' => __($event->unit === 'troy_ounce' ? 'market.usd_troy_ounce' : ($event->unit === 'pound' ? 'market.usd_pound' : 'engagement.usd_coin')), 'price' => rtrim(rtrim($event->observed_price, '0'), '.')]) }}</p>
            <time datetime="{{ gmdate('c', $event->observed_timestamp) }}">{{ __('engagement.observed_at') }}: {{ gmdate('Y-m-d H:i', $event->observed_timestamp) }} UTC</time></article>
        @empty<p>{{ __('engagement.no_events') }}</p>@endforelse
        <x-pagination :paginator="$events"/>
    </section>
</section>
@endsection

