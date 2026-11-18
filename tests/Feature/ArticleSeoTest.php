<?php

namespace Tests\Feature;

use App\Models\Article;
use App\Models\ArticleTranslation;
use App\Models\Tag;
use Carbon\CarbonImmutable;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ArticleSeoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.url' => 'https://cryptonews.test']);
    }

    private function metadata(string $html): DOMXPath
    {
        $document = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $document->loadHTML($html);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return new DOMXPath($document);
    }

    public function test_article_has_one_title_and_consistent_canonical_social_and_structured_metadata(): void
    {
        $article = Article::factory()->published()->create(['featured_image' => 'editorial/bitcoin.svg']);
        ArticleTranslation::factory()->for($article)->create([
            'slug' => 'seo-story', 'title' => 'The actual headline',
            'meta_title' => 'A specific search title', 'meta_description' => 'A precise description.',
            'og_image' => 'editorial/network.svg',
        ]);

        $response = $this->get('/en/news/seo-story')->assertOk();
        $xpath = $this->metadata($response->getContent());
        $canonical = 'https://cryptonews.test/en/news/seo-story';

        $this->assertSame(1, $xpath->query('//title')->length);
        $this->assertSame('A specific search title — CryptoNews', $xpath->evaluate('string(//title)'));
        $this->assertSame($canonical, $xpath->evaluate('string(//link[@rel="canonical"]/@href)'));
        $this->assertSame($canonical, $xpath->evaluate('string(//meta[@property="og:url"]/@content)'));
        $this->assertSame('article', $xpath->evaluate('string(//meta[@property="og:type"]/@content)'));
        $this->assertSame('A precise description.', $xpath->evaluate('string(//meta[@name="description"]/@content)'));
        $this->assertSame('summary_large_image', $xpath->evaluate('string(//meta[@name="twitter:card"]/@content)'));
        $this->assertSame('https://cryptonews.test/images/editorial/network.svg', $xpath->evaluate('string(//meta[@property="og:image"]/@content)'));
        $schemas = $xpath->query('//script[@type="application/ld+json"]');
        $this->assertSame(2, $schemas->length);
        $news = json_decode($schemas->item(0)->textContent, true, 512, JSON_THROW_ON_ERROR);
        $breadcrumbs = json_decode($schemas->item(1)->textContent, true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('NewsArticle', $news['@type']);
        $this->assertSame('The actual headline', $news['headline']);
        $this->assertSame($canonical, $news['mainEntityOfPage']['@id']);
        $this->assertSame($article->author->name, $news['author']['name']);
        $this->assertSame('en', $news['inLanguage']);
        $this->assertSame('BreadcrumbList', $breadcrumbs['@type']);
        $this->assertSame($canonical, $breadcrumbs['itemListElement'][2]['item']);
    }

    public function test_hreflang_lists_only_supported_existing_translations_and_english_default(): void
    {
        $article = Article::factory()->published()->create();
        ArticleTranslation::factory()->for($article)->create(['locale' => 'en', 'slug' => 'english']);
        ArticleTranslation::factory()->for($article)->create(['locale' => 'zh', 'slug' => '中文']);
        ArticleTranslation::factory()->for($article)->create(['locale' => 'hy', 'slug' => 'archived']);

        $response = $this->get('/zh/news/中文')->assertOk();
        $xpath = $this->metadata($response->getContent());

        $this->assertSame(3, $xpath->query('//head/link[@hreflang]')->length);
        $this->assertSame('https://cryptonews.test/en/news/english', $xpath->evaluate('string(//head/link[@hreflang="en"]/@href)'));
        $this->assertSame('https://cryptonews.test/zh/news/'.rawurlencode('中文'), $xpath->evaluate('string(//head/link[@hreflang="zh-Hans"]/@href)'));
        $this->assertSame('https://cryptonews.test/en/news/english', $xpath->evaluate('string(//head/link[@hreflang="x-default"]/@href)'));
        $this->assertSame(0, $xpath->query('//head/link[@hreflang="ru" or @hreflang="hy"]')->length);
        $this->get('/ru/news/english')->assertNotFound();
    }

    public function test_x_default_is_omitted_when_no_english_translation_exists(): void
    {
        $article = Article::factory()->published()->create();
        ArticleTranslation::factory()->for($article)->create(['locale' => 'ru', 'slug' => 'russian-only']);

        $response = $this->get('/ru/news/russian-only')->assertOk();
        $xpath = $this->metadata($response->getContent());

        $this->assertSame(0, $xpath->query('//head/link[@hreflang="x-default"]')->length);
        $this->assertSame('ru_RU', $xpath->evaluate('string(//meta[@property="og:locale"]/@content)'));
    }

    public function test_metadata_and_json_ld_cannot_break_out_of_html_or_script_contexts(): void
    {
        $headline = 'A headline </script><script>injected()</script> " & Հայերեն';
        $article = Article::factory()->published()->create();
        ArticleTranslation::factory()->for($article)->create([
            'slug' => 'escaped', 'title' => $headline,
            'meta_title' => '"><script>titleAttack()</script>',
            'meta_description' => '"><img src=x onerror=bad()> Context.',
        ]);

        $response = $this->get('/en/news/escaped')->assertOk()
            ->assertDontSee('<script>injected()</script>', false)
            ->assertDontSee('<script>titleAttack()</script>', false)
            ->assertDontSee('<img src=x onerror=bad()>', false);
        $xpath = $this->metadata($response->getContent());
        $scripts = $xpath->query('//script');

        $schemas = $xpath->query('//script[@type="application/ld+json"]');
        $this->assertSame(2, $schemas->length);
        $this->assertSame(2, $xpath->query('//script[not(@src)]')->length);
        $this->assertSame(1, $xpath->query('//script[@src and @data-update-uri]')->length);
        $this->assertSame(3, $scripts->length);
        $schema = json_decode($schemas->item(0)->textContent, true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame($headline, $schema['headline']);
    }

    public function test_canonical_uses_app_url_and_stored_slug_without_host_poisoning_or_external_overrides(): void
    {
        $article = Article::factory()->published()->create();
        $translation = ArticleTranslation::factory()->for($article)->create([
            'slug' => 'stable-story', 'canonical_url' => 'https://unverified.invalid/other-story',
        ]);

        $response = $this->withHeader('Host', 'attacker.invalid')->get('/en/news/stable-story')->assertOk();
        $xpath = $this->metadata($response->getContent());

        $this->assertSame('https://cryptonews.test/en/news/stable-story', $xpath->evaluate('string(//link[@rel="canonical"]/@href)'));
        $this->assertSame('https://unverified.invalid/other-story', $translation->fresh()->canonical_url);
    }

    public function test_missing_images_are_not_advertised_as_actual_article_images_in_seo(): void
    {
        $article = Article::factory()->published()->create(['featured_image' => 'missing.png']);
        ArticleTranslation::factory()->for($article)->create(['slug' => 'missing-image', 'og_image' => 'javascript:bad()']);

        $response = $this->get('/en/news/missing-image')->assertOk();
        $xpath = $this->metadata($response->getContent());

        $this->assertSame(0, $xpath->query('//meta[@property="og:image"]')->length);
        $this->assertSame('summary', $xpath->evaluate('string(//meta[@name="twitter:card"]/@content)'));
    }

    public function test_only_real_post_publication_edits_are_marked_as_updates(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-13 12:00:00 UTC'));
        $article = Article::factory()->published()->create([
            'published_at' => now()->subDays(2), 'created_at' => now()->subDays(3), 'updated_at' => now()->subDays(3),
        ]);
        ArticleTranslation::factory()->for($article)->create([
            'slug' => 'updated-story', 'created_at' => now()->subDays(3), 'updated_at' => now()->subDay(),
        ]);

        $this->get('/en/news/updated-story')->assertOk()
            ->assertViewHas('seo', fn ($seo) => $seo['modifiedAt']->toIso8601String() === '2026-11-12T12:00:00+00:00')
            ->assertSee('Updated');
    }

    public function test_demo_stories_do_not_claim_to_be_real_news_in_structured_data(): void
    {
        $article = Article::factory()->published()->create();
        $article->tags()->attach(Tag::factory()->create(['slug' => 'cryptonews-demo']));
        ArticleTranslation::factory()->for($article)->create(['slug' => 'demo-story']);

        $response = $this->get('/en/news/demo-story')->assertOk();
        $xpath = $this->metadata($response->getContent());
        $schema = json_decode($xpath->query('//script[@type="application/ld+json"]')->item(0)->textContent, true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame('Article', $schema['@type']);
        $this->assertSame('Fictional demonstration', $schema['genre']);
        $this->assertSame('Organization', $schema['author']['@type']);
    }
}
