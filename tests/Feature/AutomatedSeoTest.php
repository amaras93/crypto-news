<?php

namespace Tests\Feature;

use App\Models\Article;
use App\Models\ArticleTranslation;
use App\Models\Category;
use App\Support\ArticleSeo;
use App\Support\NewsFeed;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AutomatedSeoTest extends TestCase
{
    use RefreshDatabase;

    private function translation(array $attributes = []): ArticleTranslation
    {
        return ArticleTranslation::factory()->make(array_replace([
            'article_id' => null, 'locale' => 'en', 'slug' => 'a-specific-policy-announcement',
            'meta_title' => 'A specific policy announcement',
            'meta_description' => 'The board describes its policy announcement and explains the publication schedule.',
            'body' => '<h2>The announcement</h2><p>The board has issued a new policy announcement describing the current publication process and the information available to its committee.</p><h2>Publication context</h2><p>The statement explains when official documentation becomes available and identifies the period covered by the published source material.</p>',
        ], $attributes));
    }

    public function test_complete_localized_seo_passes_without_fabricated_images_or_links(): void
    {
        $translation = $this->translation(['locale' => 'zh', 'slug' => '政策公告',
            'meta_title' => '政策公告与文件发布时间', 'meta_description' => '本报道介绍公告内容及其文件发布时间，并保留原始来源的归属信息。']);
        $this->assertSame([], app(ArticleSeo::class)->automaticIssues($translation));
    }

    #[DataProvider('incompleteSeo')]
    public function test_missing_repetitive_and_unsafe_seo_is_rejected(array $attributes, string $expected): void
    {
        $this->assertContains($expected, app(ArticleSeo::class)->automaticIssues($this->translation($attributes)));
    }

    /** @return array<string, array{array<string, mixed>, string}> */
    public static function incompleteSeo(): array
    {
        $paragraph = '<p>This is a sufficiently detailed paragraph explaining a specific announcement and the associated primary source publication process.</p>';

        return [
            'missing title' => [['meta_title' => null], 'seo_incomplete_metadata'],
            'missing description' => [['meta_description' => ' '], 'seo_incomplete_metadata'],
            'markup in metadata' => [['meta_title' => '<b>Announcement</b>'], 'seo_incomplete_metadata'],
            'unsafe slug' => [['slug' => '../not-a-story'], 'seo_invalid_slug'],
            'unreadable slug' => [['slug' => 'Story With Spaces'], 'seo_invalid_slug'],
            'keyword stuffing' => [['meta_title' => 'policy announcement update policy announcement update policy announcement update'], 'seo_repeated_keywords'],
            'empty article' => [['body' => '<h2>Context</h2>'], 'seo_incomplete_body'],
            'one paragraph' => [['body' => $paragraph], 'seo_incomplete_body'],
            'repeated paragraphs' => [['body' => $paragraph.$paragraph], 'seo_repeated_body'],
            'citation padding' => [['body' => '<p>Short.<a href="https://publisher.example">A lengthy source label does not make a substantive original article paragraph</a></p>'.$paragraph], 'seo_incomplete_body'],
        ];
    }

    public function test_duplicate_metadata_and_narrative_on_a_published_page_block_a_new_article(): void
    {
        $original = $this->translation()->getAttributes();
        unset($original['article_id']);
        ArticleTranslation::factory()->for(Article::factory()->published())->create($original);
        $issues = app(ArticleSeo::class)->automaticIssues($this->translation());
        $this->assertContains('seo_duplicate_title', $issues);
        $this->assertContains('seo_duplicate_description', $issues);
        $this->assertContains('seo_duplicate_body', $issues);
    }

    public function test_private_drafts_do_not_consume_public_seo_titles(): void
    {
        $attributes = $this->translation()->getAttributes();
        unset($attributes['article_id']);
        ArticleTranslation::factory()->for(Article::factory())->create($attributes);
        $this->assertSame([], app(ArticleSeo::class)->automaticIssues($this->translation()));
    }

    #[DataProvider('unpublishableStates')]
    public function test_failed_or_incomplete_automation_is_absent_from_routes_and_sitemap(string $state): void
    {
        $article = Article::factory()->published()->create(['automation_status' => $state]);
        ArticleTranslation::factory()->for($article)->create(['slug' => 'private-ai-story', 'ai_review_status' => 'automated']);
        $this->get('/en/news/private-ai-story')->assertNotFound()->assertSee('noindex,follow', false)->assertDontSee('rel="canonical"', false);
        $this->get('/sitemap-articles-1.xml')->assertOk()->assertDontSee('private-ai-story');
    }

    /** @return list<array{string}> */
    public static function unpublishableStates(): array
    {
        return [['held'], ['processing'], ['published']];
    }

    public function test_automatic_related_links_require_published_localized_matching_topics(): void
    {
        $category = Category::factory()->create();
        $current = Article::factory()->published()->for($category)->create();
        $current->ai_generation_run_id = 123;
        $related = Article::factory()->published()->for($category)->has(ArticleTranslation::factory(), 'translations')->create();
        Article::factory()->published()->has(ArticleTranslation::factory(), 'translations')->create();
        Article::factory()->for($category)->has(ArticleTranslation::factory(), 'translations')->create();
        Article::factory()->published()->for($category)->has(ArticleTranslation::factory()->state(['locale' => 'ru']), 'translations')->create();
        $this->assertSame([$related->id], app(NewsFeed::class)->related($current, 'en')->modelKeys());
    }
}
