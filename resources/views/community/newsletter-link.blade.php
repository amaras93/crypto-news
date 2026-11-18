@extends('layouts.app')
@section('content')
<section class="community-page community-auth engagement-page">
    <h1>{{ $pageTitle }}</h1>
    @if(! $valid)<p role="alert">{{ __('engagement.invalid_link') }}</p>
    @elseif($done)<p role="status">{{ __($operation === 'confirm' ? 'engagement.confirmed' : 'engagement.unsubscribed') }}</p>
    @else
        <p>{{ __($operation === 'confirm' ? 'engagement.confirmation_help' : 'engagement.unsubscribe_mail') }}</p>
        <form method="post" action="{{ $formAction }}">@csrf<button type="submit">{{ __($operation === 'confirm' ? 'engagement.confirm_action' : 'engagement.unsubscribe') }}</button></form>
    @endif
    <a href="{{ route('newsletter.form', ['locale' => app()->getLocale()]) }}">{{ __('engagement.newsletter') }}</a>
</section>
@endsection

