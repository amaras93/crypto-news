<?php

namespace App\Http\Controllers;

use App\Support\ArticleContent;
use App\Support\ArticleMedia;
use App\Support\ArticleSeo;
use App\Support\NewsFeed;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;

class ArticleController extends Controller
{
    public function __invoke(string $locale, string $slug, NewsFeed $feed, ArticleContent $renderer, ArticleSeo $metadata, ArticleMedia $media): View
    {
        $article = $feed->articles($locale)
            ->whereHas('translations', fn (Builder $query) => $query->where('locale', $locale)->where('slug', $slug))
            ->firstOrFail();
        $article->load('translations');
        $translation = $article->translation($locale);
        $categories = $feed->categories($locale);
        $categorySlug = $categories->firstWhere('id', $article->category_id)?->translation('en')?->slug;
        $content = $renderer->render($translation->body, $article->isSponsored());
        $seo = $metadata->forArticle($article, $translation, $content['text']);
        $shareQuery = ['url' => $seo['canonical'], 'text' => $translation->title];

        return view('news.show', [
            'article' => $article,
            'translation' => $translation,
            'content' => $content,
            'seo' => $seo,
            'hero' => $media->hero($article, $categorySlug),
            'categories' => $categories,
            'picks' => $feed->selected($locale, 'editors-pick', 4, $article->id),
            'related' => $feed->related($article, $locale),
            'articleTags' => $article->tags->whereNotIn('slug', $article->isSponsored() ? ['cryptonews-demo', 'editors-pick', 'analysis', 'opinion'] : ['cryptonews-demo', 'editors-pick']),
            'shareLinks' => [
                'x' => 'https://twitter.com/intent/tweet?'.http_build_query($shareQuery, '', '&', PHP_QUERY_RFC3986),
                'linkedin' => 'https://www.linkedin.com/sharing/share-offsite/?'.http_build_query(['url' => $seo['canonical']], '', '&', PHP_QUERY_RFC3986),
                'telegram' => 'https://t.me/share/url?'.http_build_query($shareQuery, '', '&', PHP_QUERY_RFC3986),
            ],
            'pageTitle' => $seo['title'],
            'pageDescription' => $seo['description'],
        ]);
    }
}
