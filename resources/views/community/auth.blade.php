@extends('layouts.app')

@section('content')
<section class="community-page community-auth" aria-labelledby="community-heading">
    <h1 id="community-heading">{{ $pageTitle }}</h1>
    <x-community-feedback/>
    @if(request()->routeIs('verification.notice'))
        @if(auth()->user()->hasVerifiedEmail())
            <p>{{ __('community.verified') }}</p>
        @else
            <p>{{ __('community.verify_notice') }}</p>
            <form method="post" action="{{ route('verification.send', ['locale' => app()->getLocale()]) }}">@csrf
                <button type="submit">{{ __('community.resend') }}</button>
            </form>
        @endif
        <a href="{{ route('account', ['locale' => app()->getLocale()]) }}">{{ __('community.account') }}</a>
    @else
        @php($formAction = match(request()->route()->getName()) {
            'register' => 'reader.register', 'password.request' => 'password.email',
            'password.reset' => 'password.update', default => 'reader.login',
        })
        <form method="post" action="{{ route($formAction, ['locale' => app()->getLocale()]) }}">
            @csrf
            @if(request()->routeIs('register'))
                <label for="name">{{ __('community.name') }}</label>
                <input id="name" name="name" value="{{ old('name') }}" maxlength="80" autocomplete="name" required>
                <div class="community-honeypot" aria-hidden="true"><label for="website">{{ __('community.website') }}</label><input id="website" name="website" tabindex="-1" autocomplete="off"></div>
            @endif
            @if(request()->routeIs('password.reset'))<input type="hidden" name="token" value="{{ $token }}">@endif
            <label for="email">{{ __('community.email') }}</label>
            <input id="email" name="email" type="email" value="{{ old('email', request()->routeIs('password.reset') ? request()->query('email') : '') }}" maxlength="255" autocomplete="email" required>
            @unless(request()->routeIs('password.request'))
                <label for="password">{{ __('community.password') }}</label>
                <input id="password" name="password" type="password" maxlength="64" autocomplete="{{ request()->routeIs('login') ? 'current-password' : 'new-password' }}" required @unless(request()->routeIs('login')) minlength="12" aria-describedby="password-help" @endunless>
                @unless(request()->routeIs('login'))
                    <p id="password-help" class="community-note">{{ __('community.password_help') }}</p>
                    <label for="password_confirmation">{{ __('community.confirm_password') }}</label>
                    <input id="password_confirmation" name="password_confirmation" type="password" autocomplete="new-password" required>
                @else
                    <label class="community-check"><input name="remember" type="checkbox" value="1"> {{ __('community.remember') }}</label>
                @endunless
            @endunless
            <button type="submit">{{ $pageTitle }}</button>
        </form>
        <nav class="community-links" aria-label="{{ __('community.account') }}">
            @unless(request()->routeIs('login'))<a href="{{ route('login', ['locale' => app()->getLocale()]) }}">{{ __('community.login') }}</a>@endunless
            @unless(request()->routeIs('register'))<a href="{{ route('register', ['locale' => app()->getLocale()]) }}">{{ __('community.register') }}</a>@endunless
            @if(request()->routeIs('login'))<a href="{{ route('password.request', ['locale' => app()->getLocale()]) }}">{{ __('community.forgot_password') }}</a>@endif
        </nav>
    @endif
</section>
@endsection

