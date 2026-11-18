@php
    $currentLocale = app()->getLocale();
    if (! isset($seo) && ! ($privatePage ?? false)) {
        $canonicalParameters = ['locale' => $currentLocale];
        if (request()->routeIs('news.index')) {
            $canonicalParameters += array_filter(['q' => $search ?? '', 'category' => $categoryId ?? null, 'topic' => $topic ?? null, 'page' => $articles->currentPage() > 1 ? $articles->currentPage() : null], fn ($value) => $value !== '' && $value !== null);
        }
        $publicUrls = app(\App\Support\PublicUrl::class);
        $seoAlternates = [];
        foreach (array_keys(config('news.locales')) as $language) {
            $alternateParameters = array_replace($canonicalParameters, ['locale' => $language]);
            unset($alternateParameters['page']);
            $seoAlternates[$language] = $publicUrls->route(request()->route()->getName(), $alternateParameters);
        }
        $seo = [
            'title' => $pageTitle ?? __('ui.home'),
            'description' => $pageDescription ?? __('messages.description'),
            'canonical' => $publicUrls->route(request()->route()->getName(), $canonicalParameters),
            'alternates' => $seoAlternates,
            'locale' => config('news.graph_locales.'.$currentLocale),
            'type' => 'website',
            'image' => null,
            'structured' => [],
        ];
    }
@endphp
<!DOCTYPE html>
<html lang="{{ config('news.language_tags.'.$currentLocale) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#F8F6F1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    @if($privatePage ?? false)
        <title>{{ $pageTitle }} — {{ config('app.name') }}</title>
        <meta name="robots" content="noindex,nofollow,noarchive">
    @else
        <x-seo-meta :seo="$seo"/>
    @endif
    @if(request()->routeIs('news.index') && (($search ?? '') !== '' || ($categoryId ?? null) || ($topic ?? null)))
        <meta name="robots" content="noindex,follow">
    @endif
    <link rel="icon" href="{{ asset('images/brand.svg') }}" type="image/svg+xml">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @if(request()->routeIs('news.show')) @livewireStyles @endif
</head>
<body class="newspaper{{ request()->routeIs('news.show') ? ' newspaper-reader' : '' }}">
    <a class="skip-link" href="#main">{{ __('messages.skip_navigation') }}</a>
    <x-newspaper-header :categories="$categories" :article="$article ?? null" :search="$search ?? ''"/>
    <main id="main"><div class="container">@yield('content')</div></main>
    <x-newspaper-footer :categories="$categories" :article="$article ?? null"/>
    @if(request()->routeIs('news.show')) @livewireScripts @endif
</body>
</html>
