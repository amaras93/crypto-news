<?php

namespace Tests\Feature;

use App\ArticleStatus;
use App\Models\Article;
use App\Models\ArticleTranslation;
use App\Support\PublicUrl;
use App\Support\Sitemap;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use SimpleXMLElement;
use Tests\TestCase;

class SitemapTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.url' => 'https://cryptonews.test']);
    }

    private function xml(string $path): SimpleXMLElement
    {
        $response = $this->get($path)->assertOk()
            ->assertHeader('Content-Type', 'application/xml; charset=UTF-8');
        $document = simplexml_load_string($response->streamedContent(), SimpleXMLElement::class, LIBXML_NONET);
        $this->assertNotFalse($document);
        $document->registerXPathNamespace('s', 'http://www.sitemaps.org/schemas/sitemap/0.9');

        return $document;
    }

    private function locations(SimpleXMLElement $document): array
    {
        return array_map(fn ($node): string => (string) $node, $document->xpath('//s:loc'));
    }

    public function test_index_and_page_sitemap_are_valid_even_without_articles(): void
    {
        $index = $this->xml('/sitemap.xml');

        $this->assertSame('sitemapindex', $index->getName());
        $this->assertSame(['https://cryptonews.test/sitemap-pages.xml'], $this->locations($index));
        $pages = $this->xml('/sitemap-pages.xml');
        $this->assertSame([
            'https://cryptonews.test/en',
            'https://cryptonews.test/en/news',
            'https://cryptonews.test/ru',
            'https://cryptonews.test/ru/news',
            'https://cryptonews.test/fr',
            'https://cryptonews.test/fr/news',
            'https://cryptonews.test/de',
            'https://cryptonews.test/de/news',
            'https://cryptonews.test/es',
            'https://cryptonews.test/es/news',
            'https://cryptonews.test/it',
            'https://cryptonews.test/it/news',
            'https://cryptonews.test/ja',
            'https://cryptonews.test/ja/news',
            'https://cryptonews.test/zh',
            'https://cryptonews.test/zh/news',
        ], $this->locations($pages));
    }

    public function test_article_sitemap_includes_only_existing_public_supported_translations(): void
    {
        $public = Article::factory()->published()->create();
        $english = ArticleTranslation::factory()->for($public)->create(['locale' => 'en', 'slug' => 'real-public-story']);
        $armenian = ArticleTranslation::factory()->for($public)->create(['locale' => 'hy', 'slug' => 'հոդված']);
        $french = ArticleTranslation::factory()->for($public)->create(['locale' => 'fr', 'slug' => 'french-public-story']);
        ArticleTranslation::factory()->for($public)->create(['locale' => 'pt', 'slug' => 'not-supported']);

        foreach ([
            [ArticleStatus::Draft, now()->subDay()],
            [ArticleStatus::PendingReview, now()->subDay()],
            [ArticleStatus::Archived, now()->subDay()],
            [ArticleStatus::Published, now()->addDay()],
            [ArticleStatus::Published, null],
        ] as [$status, $date]) {
            $hidden = Article::factory()->create(['status' => $status, 'published_at' => $date]);
            ArticleTranslation::factory()->for($hidden)->create();
        }

        $deleted = Article::factory()->published()->has(ArticleTranslation::factory(), 'translations')->create();
        $deleted->delete();

        $locations = $this->locations($this->xml('/sitemap-articles-1.xml'));
        $urls = app(PublicUrl::class);

        $this->assertSame([$urls->article($english), $urls->article($french)], $locations);
        $this->assertDatabaseHas('article_translations', ['id' => $armenian->id, 'locale' => 'hy']);
        $this->assertSame(count($locations), count(array_unique($locations)));
        $this->assertStringNotContainsString('/ru/', implode(' ', $locations));
        $this->assertSame([
            'https://cryptonews.test/sitemap-pages.xml',
            'https://cryptonews.test/sitemap-articles-1.xml',
        ], $this->locations($this->xml('/sitemap.xml')));
    }

    public function test_sparse_id_shards_are_disjoint_bounded_and_discoverable(): void
    {
        $article = Article::factory()->published()->create();
        $first = ArticleTranslation::factory()->for($article)->create(['id' => 1, 'locale' => 'en']);
        $second = ArticleTranslation::factory()->for($article)->create(['id' => Sitemap::SHARD_SIZE + 1, 'locale' => 'ru']);

        $index = $this->locations($this->xml('/sitemap.xml'));

        $this->assertSame([
            'https://cryptonews.test/sitemap-pages.xml',
            'https://cryptonews.test/sitemap-articles-1.xml',
            'https://cryptonews.test/sitemap-articles-5001.xml',
        ], $index);
        $this->assertSame([app(PublicUrl::class)->article($first)], $this->locations($this->xml('/sitemap-articles-1.xml')));
        $this->assertSame([app(PublicUrl::class)->article($second)], $this->locations($this->xml('/sitemap-articles-5001.xml')));
    }

    public function test_sitemap_urls_are_encoded_escaped_and_derived_from_app_url(): void
    {
        $article = Article::factory()->published()->create();
        $translation = ArticleTranslation::factory()->for($article)->create([
            'locale' => 'ja', 'slug' => '記事-&-test',
            'canonical_url' => 'https://external.invalid/pretend',
        ]);

        $response = $this->withHeader('Host', 'poison.invalid')->get('/sitemap-articles-1.xml')->assertOk();
        $rawXml = $response->streamedContent();
        $xml = simplexml_load_string($rawXml, SimpleXMLElement::class, LIBXML_NONET);
        $xml->registerXPathNamespace('s', 'http://www.sitemaps.org/schemas/sitemap/0.9');
        $location = (string) $xml->xpath('//s:loc')[0];

        $this->assertSame('https://cryptonews.test/ja/news/'.rawurlencode($translation->slug), $location);
        $this->assertStringNotContainsString('poison.invalid', $rawXml);
        $this->assertStringNotContainsString('external.invalid', $rawXml);
    }

    public function test_lastmod_uses_actual_publication_or_edit_time_not_sitemap_generation_time(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-13 12:00:00 UTC'));
        $article = Article::factory()->published()->create([
            'published_at' => now()->subDays(2),
            'created_at' => now()->subDays(3),
            'updated_at' => now()->subDays(3),
        ]);
        ArticleTranslation::factory()->for($article)->create([
            'created_at' => now()->subDays(3), 'updated_at' => now()->subDay(),
        ]);

        $xml = $this->xml('/sitemap-articles-1.xml');

        $this->assertSame('2026-11-12T12:00:00+00:00', (string) $xml->xpath('//s:lastmod')[0]);
    }

    public function test_malformed_or_unroutable_slug_rows_do_not_create_invalid_sitemap_entries(): void
    {
        $article = Article::factory()->published()->create();

        foreach (['', 'broken/path', 'back\\slash', '.', '..', "control\x01slug"] as $slug) {
            ArticleTranslation::factory()->for(Article::factory()->published())->create(['slug' => $slug]);
        }

        ArticleTranslation::factory()->for($article)->create(['slug' => 'valid-story']);

        $this->assertSame(['https://cryptonews.test/en/news/valid-story'], $this->locations($this->xml('/sitemap-articles-1.xml')));
    }

    public function test_invalid_shard_boundaries_are_rejected(): void
    {
        $this->get('/sitemap-articles-0.xml')->assertNotFound();
        $this->get('/sitemap-articles-2.xml')->assertNotFound();
        $this->get('/sitemap-articles-5000.xml')->assertNotFound();
        $this->get('/sitemap-articles-999999999999999999999.xml')->assertNotFound();
    }

    public function test_robots_exposes_the_absolute_sitemap_location(): void
    {
        $this->get('/robots.txt')->assertOk()
            ->assertHeader('Content-Type', 'text/plain; charset=UTF-8')
            ->assertSee('Sitemap: https://cryptonews.test/sitemap.xml', false);
    }
}
