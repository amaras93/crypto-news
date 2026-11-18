@props(['placement'])
@php($slot = app(\App\Support\AdvertisingService::class)->slot($placement))
@if($slot)
<aside {{ $attributes->class(['paper-ad', 'paper-ad-desktop' => $slot['definition']['desktop'], 'paper-ad-network' => $slot['provider'] === 'adsense']) }} aria-label="{{ __('advertising.advertisement') }}" data-ad-placement="{{ $placement }}">
    <span class="paper-ad-label">{{ __('advertising.advertisement') }}</span>
    <div class="paper-ad-space" style="--ad-width:{{ $slot['definition']['width'] }};--ad-height:{{ $slot['definition']['height'] }}">
        @if($slot['provider'] === 'direct')
            <x-direct-banner :banner="$slot['banner']"/>
        @else
            @once
                <script async src="https://pagead2.googlesyndication.com/pagead/js/adsbygoogle.js?client={{ $slot['publisher'] }}" crossorigin="anonymous"></script>
            @endonce
            <ins class="adsbygoogle" style="display:block" data-ad-client="{{ $slot['publisher'] }}" data-ad-slot="{{ $slot['slot'] }}" data-ad-format="auto" data-full-width-responsive="true"></ins>
            <script>(window.adsbygoogle = window.adsbygoogle || []).push({});</script>
        @endif
    </div>
</aside>
@endif
