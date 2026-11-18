@php($errorLocale = array_key_exists(app()->getLocale(), config('news.locales')) ? app()->getLocale() : 'en')
<!DOCTYPE html>
<html lang="{{ config('news.language_tags.'.$errorLocale) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex,follow">
    <title>{{ __('messages.not_found') }} — CryptoNews</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="newspaper">
    <main class="container paper-error">
        <a class="paper-wordmark" href="{{ route('home', ['locale' => $errorLocale]) }}">CryptoNews</a>
        <h1>{{ __('messages.not_found') }}</h1>
        <p>{{ __('messages.not_found_note') }}</p>
        <a class="button" href="{{ route('news.index', ['locale' => $errorLocale]) }}">{{ __('ui.all_news') }}</a>
    </main>
</body>
</html>
