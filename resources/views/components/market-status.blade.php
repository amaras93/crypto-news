@props(['quote'])
@if($quote->status !== 'demo')
    <span class="market-quote-status status-{{ $quote->status }}">
        {{ __($quote->status === 'current' ? 'market.last_updated' : 'market.'.$quote->status) }}
        @if($quote->providerTimestamp !== null)
            @php
                $updatedAt = \Carbon\CarbonImmutable::createFromTimestampUTC($quote->providerTimestamp)->locale(app()->getLocale());
                $fetchedAt = \Carbon\CarbonImmutable::createFromTimestampUTC($quote->fetchedAt)->locale(app()->getLocale());
            @endphp
            <time datetime="{{ $updatedAt->toIso8601String() }}" title="{{ __('market.last_updated') }}: {{ $updatedAt->isoFormat('lll') }} UTC · {{ __('market.fetched') }}: {{ $fetchedAt->isoFormat('lll') }} UTC">{{ $updatedAt->format('H:i') }} UTC</time>
        @endif
    </span>
@endif
