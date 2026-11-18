<!DOCTYPE html>
<html lang="{{ config('news.language_tags.'.app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex,nofollow,noarchive">
    <title>Editorial preview · {{ $translation->title }} · CryptoNews</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="newspaper newspaper-reader">
    <main class="container">
        <nav aria-label="Editorial preview">
            <a href="{{ \App\Filament\Resources\Articles\ArticleResource::getUrl('index') }}">← CMS articles</a>
            @foreach($article->translations->whereIn('locale', array_keys(config('news.locales'))) as $version)
                <a href="{{ route('editorial.preview', ['article' => $article, 'locale' => $version->locale]) }}" lang="{{ config('news.language_tags.'.$version->locale) }}">{{ config('news.locales.'.$version->locale) }}</a>
            @endforeach
        </nav>
        <p><strong>Private editorial preview · {{ $article->status->value }} · {{ strtoupper(app()->getLocale()) }}</strong></p>
        <article class="article-layout">
            <x-sponsorship-label :article="$article" :full="true"/>
            <div class="article-reading-column">
                <header class="article-heading">
                    <h1>{{ $translation->title }}</h1>
                    @if($translation->excerpt)<p class="article-deck">{{ $translation->excerpt }}</p>@endif
                    <p class="article-author">{{ $article->author->name }}</p>
                    @if($article->published_at)<p>Publication time (UTC): {{ $article->published_at->format('Y-m-d H:i') }}</p>@endif
                    <figure class="article-hero-image">
                        <img src="{{ $hero['url'] }}" width="1200" height="740" alt="{{ $translation->image_alt ?: $translation->title }}">
                        @if($article->image_credit)<figcaption>{{ $article->image_credit }}</figcaption>@endif
                    </figure>
                </header>
                <x-article-summary :translation="$translation"/>
                <x-article-toc :headings="$content['toc']"/>
                <div class="article-body" id="article-content">{{ $content['html'] }}</div>
            </div>
        </article>
    </main>
</body>
</html>
