@extends('layouts.app')
@section('content')
<section class="community-page engagement-page">
    <h1>{{ __('engagement.saved') }}</h1><x-account-navigation/><x-community-feedback/>
    @forelse($saved as $bookmark)
        @php($savedStory = $bookmark->article)
        @php($savedTranslation = $savedStory?->translation($bookmark->locale) ?? $savedStory?->translation(app()->getLocale()) ?? $savedStory?->translation('en'))
        <article class="community-history-item">
            @if($savedTranslation)
                <x-sponsorship-label :article="$savedStory"/>
                <h2><a href="{{ app(\App\Support\PublicUrl::class)->article($savedTranslation) }}">{{ $savedTranslation->title }}</a></h2>
                <p>{{ $savedTranslation->excerpt }}</p><p class="community-note">{{ __('engagement.saved_language') }}: {{ config('news.locales.'.$savedTranslation->locale) }} · {{ $savedStory->published_at->locale(app()->getLocale())->isoFormat('ll') }}</p>
            @else
                <p>{{ __('engagement.unavailable_article') }}</p>
            @endif
            <form method="post" action="{{ route('account.saved.destroy', ['locale' => app()->getLocale(), 'saved' => $bookmark]) }}">@csrf @method('delete')<button type="submit">{{ __('community.delete') }}</button></form>
        </article>
    @empty
        <p>{{ __('engagement.empty_saved') }}</p>
    @endforelse
    <x-pagination :paginator="$saved"/>
</section>
@endsection

