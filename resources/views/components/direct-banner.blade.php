@props(['banner'])
<a class="paper-ad-link" href="{{ $banner['url'] }}" target="_blank" rel="sponsored nofollow noopener noreferrer" referrerpolicy="no-referrer">
    <img src="{{ $banner['image'] }}" alt="{{ $banner['alt'] }}" width="{{ $banner['width'] }}" height="{{ $banner['height'] }}" loading="lazy" decoding="async">
</a>
