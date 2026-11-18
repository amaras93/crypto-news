@props(['asset'])
@php
    $fallback = 'images/markets/neutral.svg';
    $icon = config('markets.icons.'.$asset, $fallback);
    if (! is_string($icon) || ! preg_match('/\Aimages\/markets\/[a-z0-9-]+\.(?:png|svg)\z/D', $icon) || ! is_file(public_path($icon))) {
        $icon = $fallback;
    }
@endphp
<img class="market-asset-icon" src="{{ asset($icon) }}" width="20" height="20" alt="" aria-hidden="true" decoding="async" data-market-logo data-fallback="{{ asset($fallback) }}">
