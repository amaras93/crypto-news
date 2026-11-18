@props(['alert' => null])
@php($suffix = $alert?->id ?? 'new')
<form class="engagement-alert-form" method="post" action="{{ $alert ? route('account.alerts.update', ['locale' => app()->getLocale(), 'alert' => $alert]) : route('account.alerts.store', ['locale' => app()->getLocale()]) }}">
    @csrf @if($alert) @method('patch') @endif
    <label for="asset-{{ $suffix }}">{{ __('engagement.asset') }}</label>
    <select id="asset-{{ $suffix }}" name="asset" required>@foreach(config('markets.assets') as $key => $definition)<option value="{{ $key }}" @selected(($alert?->asset ?? old('asset', 'bitcoin')) === $key)>{{ __('market.assets.'.$key) }} · {{ $definition['symbol'] }} · {{ __($definition['unit'] === 'troy_ounce' ? 'market.usd_troy_ounce' : ($definition['unit'] === 'pound' ? 'market.usd_pound' : 'engagement.usd_coin')) }}</option>@endforeach</select>
    <label for="target-{{ $suffix }}">{{ __('engagement.target_price') }}</label><input id="target-{{ $suffix }}" name="target_price" inputmode="decimal" value="{{ $alert ? rtrim(rtrim($alert->target_price, '0'), '.') : old('target_price') }}" maxlength="27" required>
    <p class="community-note">{{ __('engagement.invalid_threshold') }}</p>
    <label for="direction-{{ $suffix }}">{{ __('engagement.direction') }}</label><select id="direction-{{ $suffix }}" name="direction">@foreach(['above','below'] as $direction)<option value="{{ $direction }}" @selected(($alert?->direction ?? old('direction')) === $direction)>{{ __('engagement.'.$direction) }}</option>@endforeach</select>
    @foreach(['enabled' => 'enabled', 'in_app' => 'in_app', 'email' => 'email_alerts'] as $field => $label)
        <input type="hidden" name="{{ $field }}" value="0"><label class="community-check"><input name="{{ $field }}" type="checkbox" value="1" @checked($alert ? $alert->{$field} : $field !== 'email')>{{ __('engagement.'.$label) }}</label>
    @endforeach
    <button type="submit">{{ $alert ? __('community.save') : __('engagement.create_alert') }}</button>
</form>

