@extends('layouts.app')
@section('content')
<section class="community-page engagement-page">
    <h1>{{ __('engagement.notifications') }}</h1><x-account-navigation/><x-community-feedback/>
    <details class="engagement-details"><summary>{{ __('engagement.notification_preferences') }}</summary>
        <form method="post" action="{{ route('account.notifications.preferences', ['locale' => app()->getLocale()]) }}">@csrf @method('put')
            @foreach(['price_alert_in_app' => 'in_app', 'price_alert_email' => 'email_alerts'] as $field => $label)
                <input type="hidden" name="{{ $field }}" value="0"><label class="community-check"><input type="checkbox" name="{{ $field }}" value="1" @checked($user->{$field})>{{ __('engagement.'.$label) }}</label>
            @endforeach
            <p class="community-note">{{ __('engagement.email_pref_help') }}</p><button type="submit">{{ __('community.save') }}</button>
        </form>
    </details>
    <p class="community-note">{{ __('engagement.all_caught_up') }}</p>
    @forelse($notifications as $notification)
        @php($data = $notification->data)
        <article class="community-history-item">
            <strong>{{ __('engagement.'.($notification->read_at ? 'read' : 'unread')) }}</strong>
            <p>{{ __('engagement.alert_message', ['asset' => __('market.assets.'.$data['asset']), 'direction' => __('engagement.'.$data['direction']), 'target' => rtrim(rtrim($data['target_price'], '0'), '.'), 'unit' => __($data['unit'] === 'troy_ounce' ? 'market.usd_troy_ounce' : ($data['unit'] === 'pound' ? 'market.usd_pound' : 'engagement.usd_coin')), 'price' => rtrim(rtrim($data['observed_price'], '0'), '.')]) }}</p>
            <time datetime="{{ gmdate('c', $data['observed_timestamp']) }}">{{ __('engagement.observed_at') }}: {{ gmdate('Y-m-d H:i', $data['observed_timestamp']) }} UTC</time>
            @unless($notification->read_at)<form method="post" action="{{ route('account.notifications.read', ['locale' => app()->getLocale(), 'notification' => $notification->id]) }}">@csrf @method('patch')<button type="submit">{{ __('engagement.mark_read') }}</button></form>@endunless
        </article>
    @empty<p>{{ __('engagement.no_notifications') }}</p>@endforelse
    <x-pagination :paginator="$notifications"/>
</section>
@endsection

