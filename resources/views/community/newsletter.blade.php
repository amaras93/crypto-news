@extends('layouts.app')
@section('content')
<section class="community-page community-auth engagement-page">
    <h1>{{ __('engagement.newsletter_public') }}</h1>
    @auth<x-account-navigation/>@endauth
    <x-community-feedback/>
    <p>{{ __('engagement.digest_intro') }}</p><p>{{ __('engagement.consent_help') }}</p>
    @if($subscriber?->confirmed_at && ! $subscriber->unsubscribed_at)
        <p class="community-notice">{{ __('engagement.subscription_active') }}</p>
        <form method="post" action="{{ route('account.newsletter.preferences', ['locale' => app()->getLocale()]) }}">@csrf @method('patch')
            <label for="locale">{{ __('community.language') }}</label><select name="locale" id="locale">@foreach(config('news.locales') as $language => $name)<option value="{{ $language }}" @selected($subscriber->locale === $language)>{{ $name }}</option>@endforeach</select>
            <label for="frequency">{{ __('engagement.frequency') }}</label><select name="frequency" id="frequency">@foreach(['daily','weekly'] as $frequency)<option value="{{ $frequency }}" @selected($subscriber->frequency === $frequency)>{{ __('engagement.'.$frequency) }}</option>@endforeach</select>
            <button type="submit">{{ __('community.save') }}</button>
        </form>
        <form method="post" action="{{ route('account.newsletter.stop', ['locale' => app()->getLocale()]) }}">@csrf @method('delete')<button type="submit">{{ __('engagement.unsubscribe') }}</button></form>
    @else
        <form method="post" action="{{ route('newsletter.subscribe', ['locale' => app()->getLocale()]) }}">@csrf
            <label for="email">{{ __('community.email') }}</label><input id="email" type="email" name="email" maxlength="255" autocomplete="email" value="{{ old('email', auth()->user()?->email) }}" required>
            <label for="locale">{{ __('community.language') }}</label><select id="locale" name="locale">@foreach(config('news.locales') as $language => $name)<option value="{{ $language }}" @selected(old('locale', app()->getLocale()) === $language)>{{ $name }}</option>@endforeach</select>
            <label for="frequency">{{ __('engagement.frequency') }}</label><select id="frequency" name="frequency">@foreach(['daily','weekly'] as $frequency)<option value="{{ $frequency }}" @selected(old('frequency') === $frequency)>{{ __('engagement.'.$frequency) }}</option>@endforeach</select>
            <label class="community-check"><input type="checkbox" name="consent" value="1" required>{{ __('engagement.consent_label') }}</label>
            <label class="community-honeypot" aria-hidden="true">{{ __('community.website') }}<input name="website" tabindex="-1" autocomplete="off"></label>
            <button type="submit">{{ __('engagement.subscribe') }}</button>
        </form>
        <p class="community-note">{{ __('engagement.confirmation_help') }}</p>
    @endif
</section>
@endsection

