@props(['categories', 'article' => null])
<footer class="newspaper-chrome paper-footer">
    <div class="container">
        <div class="paper-footer-top">
            <a class="paper-wordmark" href="{{ route('home', ['locale' => app()->getLocale()]) }}">CryptoNews</a>
            <p>{{ __('ui.footer_note') }}</p>
        </div>
        <nav class="paper-footer-nav" aria-label="{{ __('ui.navigate') }}"><x-newspaper-navigation :categories="$categories"/></nav>
        <nav class="paper-footer-languages" aria-label="{{ __('ui.languages') }}">
            @foreach(config('news.locales') as $locale => $language)
                @php($languageUrl = \App\Support\FrontendLinks::forLocale($locale, $article))
                @if($languageUrl)<a href="{{ $languageUrl }}" lang="{{ config('news.language_tags.'.$locale) }}" hreflang="{{ config('news.language_tags.'.$locale) }}" @if($locale === app()->getLocale()) aria-current="true" @endif>{{ $language }}</a>@endif
            @endforeach
        </nav>
        <div class="paper-footer-bottom"><p>© {{ now()->year }} CryptoNews. {{ __('ui.copyright') }}</p><p>{{ __('ui.footer_disclaimer') }}</p></div>
    </div>
</footer>
