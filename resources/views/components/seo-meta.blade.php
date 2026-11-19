@props(['seo'])
<title>{{ $seo['title'] }} — {{ config('app.name') }}</title>
<meta name="description" content="{{ $seo['description'] }}">
<link rel="canonical" href="{{ $seo['canonical'] }}">
@foreach($seo['alternates'] as $language => $alternate)
    <link rel="alternate" hreflang="{{ config('news.language_tags.'.$language) }}" href="{{ $alternate }}">
@endforeach
@if(isset($seo['alternates']['en']))
    <link rel="alternate" hreflang="x-default" href="{{ $seo['alternates']['en'] }}">
@endif
<meta property="og:type" content="{{ $seo['type'] ?? 'article' }}">
<meta property="og:site_name" content="{{ config('app.name') }}">
<meta property="og:title" content="{{ $seo['title'] }}">
<meta property="og:description" content="{{ $seo['description'] }}">
<meta property="og:url" content="{{ $seo['canonical'] }}">
<meta property="og:locale" content="{{ $seo['locale'] }}">
@foreach(array_keys($seo['alternates']) as $language)
    @php($graphLocale = config('news.graph_locales.'.$language))
    @if($graphLocale && $graphLocale !== $seo['locale'])<meta property="og:locale:alternate" content="{{ $graphLocale }}">@endif
@endforeach
<meta name="twitter:card" content="{{ $seo['image'] ? 'summary_large_image' : 'summary' }}">
<meta name="twitter:title" content="{{ $seo['title'] }}">
<meta name="twitter:description" content="{{ $seo['description'] }}">
@if($seo['image'])
    <meta property="og:image" content="{{ $seo['image'] }}">
    <meta property="og:image:alt" content="{{ $seo['imageAlt'] }}">
    @if($seo['imageMetadata'] ?? null)
        <meta property="og:image:width" content="{{ $seo['imageMetadata']['width'] }}">
        <meta property="og:image:height" content="{{ $seo['imageMetadata']['height'] }}">
        <meta property="og:image:type" content="{{ $seo['imageMetadata']['type'] }}">
    @endif
    <meta name="twitter:image" content="{{ $seo['image'] }}">
    <meta name="twitter:image:alt" content="{{ $seo['imageAlt'] }}">
@endif
@if(isset($seo['publishedAt']))
    <meta property="article:published_time" content="{{ $seo['publishedAt']->toIso8601String() }}">
    <meta property="article:modified_time" content="{{ $seo['modifiedAt']->toIso8601String() }}">
@endif
@foreach($seo['structured'] ?? [] as $schema)
    <script type="application/ld+json">{!! json_encode($schema, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) !!}</script>
@endforeach
