<?php

namespace App\Support;

use App\Models\Article;
use App\Models\ArticleTranslation;
use Carbon\CarbonImmutable;
use DOMDocument;
use DOMXPath;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

class ArticleSeo
{
    public function __construct(private PublicUrl $urls, private ArticleMedia $media) {}

    /** @return list<string> */
    public function automaticIssues(ArticleTranslation $translation): array
    {
        $issues = [];
        $title = Str::squish($translation->meta_title ?? '');
        $description = Str::squish($translation->meta_description ?? '');
        if ($title === '' || $description === '' || strip_tags($title) !== $title || strip_tags($description) !== $description) {
            $issues[] = 'seo_incomplete_metadata';
        }
        if (! preg_match('/\A[\p{L}\p{N}]+(?:[-_][\p{L}\p{N}]+)*\z/u', $translation->slug)
            || mb_strtolower($translation->slug) !== $translation->slug) {
            $issues[] = 'seo_invalid_slug';
        }
        foreach ([$title, $description] as $value) {
            $words = preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($value), flags: PREG_SPLIT_NO_EMPTY);
            $phrases = [];
            for ($index = 0; $index + 3 <= count($words); $index++) {
                $phrase = implode(' ', array_slice($words, $index, 3));
                $phrases[$phrase] = ($phrases[$phrase] ?? 0) + 1;
                if ($phrases[$phrase] >= 3) {
                    $issues[] = 'seo_repeated_keywords';
                    break;
                }
            }
        }
        $paragraphs = $this->narrative($translation->body);
        if (count($paragraphs) < 2 || collect($paragraphs)->contains(fn (string $paragraph): bool => mb_strlen($paragraph) < 50)) {
            $issues[] = 'seo_incomplete_body';
        }
        if (count(array_unique($paragraphs)) !== count($paragraphs)) {
            $issues[] = 'seo_repeated_body';
        }
        $others = ArticleTranslation::query()->where('locale', $translation->locale)
            ->when($translation->article_id, fn (Builder $query): Builder => $query->where('article_id', '<>', $translation->article_id))
            ->whereHas('article', fn (Builder $query): Builder => $query->published())
            ->select(['id', 'title', 'meta_title', 'meta_description', 'excerpt', 'body'])->lazyById(250);
        foreach ($others as $other) {
            if ($title !== '' && mb_strtolower($title) === mb_strtolower(Str::squish($other->meta_title ?: $other->title))) {
                $issues[] = 'seo_duplicate_title';
            }
            $otherDescription = Str::limit(Str::squish(strip_tags($other->meta_description ?: $other->excerpt ?: $other->body)), 160);
            if ($description !== '' && mb_strtolower(Str::limit($description, 160)) === mb_strtolower($otherDescription)) {
                $issues[] = 'seo_duplicate_description';
            }
            if ($paragraphs !== [] && $paragraphs === $this->narrative($other->body)) {
                $issues[] = 'seo_duplicate_body';
            }
        }

        return array_values(array_unique($issues));
    }

    public function assertAutomaticPublication(Article $article): void
    {
        foreach (array_keys(config('news.locales')) as $locale) {
            $translation = $article->translation($locale);
            if (! $translation) {
                throw new AiFailure('seo_missing_translation_'.$locale);
            }
            if ($issues = $this->automaticIssues($translation)) {
                throw new AiFailure($issues[0].'_'.$locale);
            }
        }
    }

    /** @return list<string> */
    private function narrative(string $body): array
    {
        $document = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        try {
            $safe = app(ArticleContent::class)->sanitize($body);
            $document->loadHTML('<?xml encoding="UTF-8"><html><body>'.$safe.'</body></html>', LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        foreach (iterator_to_array($document->getElementsByTagName('a')) as $citation) {
            $citation->parentNode->removeChild($citation);
        }
        $xpath = new DOMXPath($document);
        $paragraphs = [];
        foreach ($xpath->query('//body//p') as $paragraph) {
            $text = mb_strtolower(Str::squish($paragraph->textContent));
            if ($text !== '') {
                $paragraphs[] = $text;
            }
        }

        return $paragraphs;
    }

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
        $imageMetadata = $image ? $this->media->metadata($this->media->resolve($translation->og_image) ? $translation->og_image : $article->featured_image) : null;
        $imageAlt = $translation->image_alt ?: __('article.image_alt', ['title' => $translation->title]);
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
            $schema['image'] = $imageMetadata ? [['@type' => 'ImageObject', 'url' => $image,
                'width' => $imageMetadata['width'], 'height' => $imageMetadata['height'], 'caption' => $imageAlt]] : [$image];
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
            'imageAlt' => $imageAlt,
            'imageMetadata' => $imageMetadata,
            'publishedAt' => $article->published_at,
            'modifiedAt' => $modifiedAt,
            'breadcrumbs' => $breadcrumbs,
            'structured' => [$schema, $breadcrumbSchema],
        ];
    }
}
