@props(['article' => null])
<details class="language-menu" data-dismissable>
    <summary aria-label="{{ __('messages.language') }}">{{ strtoupper(app()->getLocale()) }} <x-ui-icon name="chevron"/></summary>
    <ul>
        @foreach(config('news.locales') as $locale => $language)
            @php($languageUrl = \App\Support\FrontendLinks::forLocale($locale, $article))
            @php($hasArticleTranslation = !request()->routeIs('news.show') || ($article?->translation($locale) && app(\App\Support\PublicUrl::class)->isPublicTranslation($article->translation($locale))))
            <li>
                @if($languageUrl)
                    <a href="{{ $languageUrl }}" lang="{{ config('news.language_tags.'.$locale) }}" hreflang="{{ config('news.language_tags.'.$locale) }}" @if(app()->getLocale() === $locale) aria-current="true" @endif @unless($hasArticleTranslation) title="{{ __('ui.missing_translation') }}" @endunless>{{ $language }}@unless($hasArticleTranslation)<span class="sr-only" lang="{{ config('news.language_tags.'.app()->getLocale()) }}"> — {{ __('ui.missing_translation') }}</span>@endunless</a>
                @else
                    <span class="unavailable" lang="{{ $locale }}" title="{{ __('ui.missing_translation') }}">{{ $language }} —</span>
                @endif
            </li>
        @endforeach
    </ul>
</details>
