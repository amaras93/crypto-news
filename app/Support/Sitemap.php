<?php

namespace App\Support;

use App\Models\ArticleTranslation;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;
use XMLWriter;

class Sitemap
{
    public const SHARD_SIZE = 5000;

    public function __construct(private PublicUrl $urls) {}

    /** @return Builder<ArticleTranslation> */
    private function translations(): Builder
    {
        return ArticleTranslation::query()->publicReady()
            ->whereIn('locale', array_keys(config('news.locales')))
            ->where('slug', '<>', '')
            ->whereHas('article', fn (Builder $query) => $query->published());
    }

    /** @return list<int> */
    public function shards(): array
    {
        $longestLocation = $this->urls->route('sitemap.articles', ['start' => PHP_INT_MAX]);
        $entryBytes = strlen(htmlspecialchars($longestLocation, ENT_QUOTES | ENT_XML1, 'UTF-8')) + 40;
        $maximumShards = min(49999, max(0, intdiv(52428800 - 4096 - strlen($this->urls->base()), $entryBytes)));
        $expression = 'id - ((id - 1) % '.self::SHARD_SIZE.')';
        $starts = $this->translations()->selectRaw($expression.' AS first_id')
            ->groupByRaw($expression)->orderBy('first_id')->limit($maximumShards + 1)
            ->pluck('first_id')->map(fn ($id): int => (int) $id)->all();

        abort_if(count($starts) > $maximumShards, 503, 'This collection requires additional top-level sitemap indexes.');

        return $starts;
    }

    private function writer(string $root): XMLWriter
    {
        $writer = new XMLWriter;
        $writer->openMemory();
        $writer->startDocument('1.0', 'UTF-8');
        $writer->startElement($root);
        $writer->writeAttribute('xmlns', 'http://www.sitemaps.org/schemas/sitemap/0.9');

        return $writer;
    }

    public function index(array $shards): void
    {
        $writer = $this->writer('sitemapindex');
        $writer->startElement('sitemap');
        $writer->writeElement('loc', $this->urls->route('sitemap.pages'));
        $writer->endElement();

        foreach ($shards as $start) {
            $writer->startElement('sitemap');
            $writer->writeElement('loc', $this->urls->route('sitemap.articles', ['start' => $start]));
            $writer->endElement();
            echo $writer->outputMemory(true);
        }

        $writer->endElement();
        $writer->endDocument();
        echo $writer->outputMemory(true);
    }

    public function pages(): void
    {
        $writer = $this->writer('urlset');

        foreach (array_keys(config('news.locales')) as $locale) {
            foreach (['home', 'news.index'] as $name) {
                $writer->startElement('url');
                $writer->writeElement('loc', $this->urls->route($name, ['locale' => $locale]));
                $writer->endElement();
            }
        }

        $writer->endElement();
        $writer->endDocument();
        echo $writer->outputMemory(true);
    }

    public function articles(int $start): void
    {
        $writer = $this->writer('urlset');
        echo $writer->outputMemory(true);

        $translations = $this->translations()
            ->whereBetween('id', [$start, $start + self::SHARD_SIZE - 1])
            ->select(['id', 'article_id', 'locale', 'slug', 'ai_review_status', 'created_at', 'updated_at'])
            ->with(['article' => fn (Builder|Relation $query) => $query->published()->select(['id', 'published_at', 'created_at', 'updated_at'])])
            ->lazyById(250);

        foreach ($translations as $translation) {
            if (! $translation->article || ! $this->urls->isPublicTranslation($translation)) {
                continue;
            }

            $writer->startElement('url');
            $writer->writeElement('loc', $this->urls->article($translation));
            $writer->writeElement('lastmod', ArticleSeo::modifiedAt($translation->article, $translation)->toIso8601String());
            $writer->endElement();
            echo $writer->outputMemory(true);
        }

        $writer->endElement();
        $writer->endDocument();
        echo $writer->outputMemory(true);
    }
}
