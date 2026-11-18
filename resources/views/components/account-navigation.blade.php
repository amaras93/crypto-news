<nav class="engagement-nav" aria-label="{{ __('community.account') }}">
    @foreach(['account' => 'community.profile', 'account.watchlist' => 'engagement.watchlist', 'account.saved' => 'engagement.saved', 'account.alerts' => 'engagement.alerts', 'account.notifications' => 'engagement.notifications', 'account.newsletter' => 'engagement.newsletter'] as $name => $label)
        <a href="{{ route($name, ['locale' => app()->getLocale()]) }}" @if(request()->routeIs($name)) aria-current="page" @endif>{{ __($label) }}</a>
    @endforeach
</nav>

