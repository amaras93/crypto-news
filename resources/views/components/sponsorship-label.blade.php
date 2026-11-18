@props(['article', 'full' => false])
@if($article->isSponsored())
    @if($full)
        <aside class="paper-sponsorship" aria-label="{{ __('advertising.disclosure') }}">
            <strong>{{ __($article->sponsor_classification === 'advertisement' ? 'advertising.advertisement' : 'advertising.sponsored') }}</strong>
            <p>{{ __('advertising.sponsored_by', ['sponsor' => $article->sponsor?->public_brand_name]) }}. {{ __('advertising.paid_note') }}</p>
            <p>{{ $article->sponsorship_disclosures[app()->getLocale()] ?? '' }}</p>
        </aside>
    @else
        <span class="paper-sponsored-label">{{ __($article->sponsor_classification === 'advertisement' ? 'advertising.advertisement' : 'advertising.sponsored') }}</span>
    @endif
@endif
