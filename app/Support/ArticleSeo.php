<?php

namespace App\Support;

use App\Models\Article;
use App\Models\ArticleTranslation;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

class ArticleSeo
{
    public function __construct(private PublicUrl $urls, private ArticleMedia $media) {}

    public static function modifiedAt(Article $article, ArticleTranslation $translation): CarbonImmutable
    {
        $modified = CarbonImmutable::instance($article->published_at);

        foreach ([$article, $translation] as $record) {
            if ($record->updated_at && $record->created_at && $record->updated_at->greaterThan($record->created_at)
                && $record->updated_at->greaterThan($modified) && $record->updated_at->lessThanOrEqualTo(now())) {
                $modified = CarbonImmutable::instance($record->updated_at);
            }
        }

        return $modified;
    }

    public function forArticle(Article $article, ArticleTranslation $translation, string $plainText): array
    {
        $canonical = $this->urls->article($translation);
        $alternates = [];

        foreach ($article->translations as $available) {
            if ($this->urls->isPublicTranslation($available)) {
                $alternates[$available->locale] = $this->urls->article($available);
            }
        }

        $title = $translation->meta_title ?: $translation->title;
        $description = Str::limit(Str::squish(strip_tags($translation->meta_description ?: $translation->excerpt ?: $plainText)), 160);
        $modifiedAt = self::modifiedAt($article, $translation);
        $image = $this->media->resolve($translation->og_image) ?? $this->media->resolve($article->featured_image);
        $breadcrumbs = [
            ['name' => __('ui.home'), 'url' => $this->urls->route('home', ['locale' => $translation->locale])],
            ['name' => __('ui.news'), 'url' => $this->urls->route('news.index', ['locale' => $translation->locale])],
        ];

        if ($categoryName = $article->category?->displayName($translation->locale)) {
            $breadcrumbs[] = [
                'name' => $categoryName,
                'url' => $this->urls->route('news.index', ['locale' => $translation->locale, 'category' => $article->category_id]),
            ];
        }

        $breadcrumbs[] = ['name' => $translation->title, 'url' => $canonical];
        $schema = [
            '@context' => 'https://schema.org',
            '@type' => $article->isDemo() || $article->isSponsored() ? 'Article' : 'NewsArticle',
            '@id' => $canonical.'#article',
            'mainEntityOfPage' => ['@type' => 'WebPage', '@id' => $canonical],
            'url' => $canonical,
            'headline' => $translation->title,
            'description' => $description,
            'inLanguage' => config('news.language_tags.'.$translation->locale),
            'datePublished' => $article->published_at->toIso8601String(),
            'dateModified' => $modifiedAt->toIso8601String(),
            'isAccessibleForFree' => true,
            'publisher' => [
                '@type' => 'Organization',
                'name' => config('app.name'),
                'url' => $this->urls->route('home', ['locale' => $translation->locale]),
            ],
        ];

        if ($article->author) {
            $schema['author'] = ['@type' => $article->isDemo() ? 'Organization' : 'Person', 'name' => $article->author->name];
        }

        if ($image) {
            $schema['image'] = [$image];
        }

        if ($categoryName) {
            $schema['articleSection'] = $categoryName;
        }

        if ($article->isSponsored()) {
            $schema['genre'] = __('advertising.sponsored');
            $schema['sponsor'] = ['@type' => 'Organization', 'name' => $article->sponsor?->public_brand_name];
        }

        if ($article->isDemo()) {
            $schema['genre'] = 'Fictional demonstration';
        }

        $breadcrumbSchema = [
            '@context' => 'https://schema.org',
            '@type' => 'BreadcrumbList',
            'itemListElement' => array_map(
                fn (array $item, int $index): array => ['@type' => 'ListItem', 'position' => $index + 1, 'name' => $item['name'], 'item' => $item['url']],
                $breadcrumbs,
                array_keys($breadcrumbs),
            ),
        ];

        return [
            'title' => $title,
            'description' => $description,
            'canonical' => $canonical,
            'alternates' => $alternates,
            'locale' => config('news.graph_locales.'.$translation->locale),
            'image' => $image,
            'imageAlt' => __('article.image_alt', ['title' => $translation->title]),
            'publishedAt' => $article->published_at,
            'modifiedAt' => $modifiedAt,
            'breadcrumbs' => $breadcrumbs,
            'structured' => [$schema, $breadcrumbSchema],
        ];
    }
}
