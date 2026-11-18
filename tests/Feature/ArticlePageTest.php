<?php

namespace Tests\Feature;

use App\ArticleStatus;
use App\Models\Article;
use App\Models\ArticleTranslation;
use App\Models\Category;
use App\Models\CategoryTranslation;
use App\Models\Tag;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ArticlePageTest extends TestCase
{
    use RefreshDatabase;

    public function test_reader_displays_real_metadata_formatting_and_heading_navigation(): void
    {
        $category = Category::factory()->has(CategoryTranslation::factory()->state(['name' => 'Bitcoin', 'slug' => 'bitcoin']), 'translations')->create();
        $article = Article::factory()->published()->for($category)->create();
        ArticleTranslation::factory()->for($article)->create([
            'slug' => 'market-context',
            'title' => 'Market context, carefully explained',
            'excerpt' => 'A short editorial introduction.',
            'body' => '<h1 id="context">Context</h1><p>A <strong>useful</strong> paragraph.</p><h4>Details</h4><blockquote><p>A direct quotation.</p><cite>Actual attribution.</cite></blockquote><ul><li>First point</li></ul><ol><li>Next step</li></ol><p><a href="#context">Return to context</a> <a href="https://example.org/research">Research</a></p><table><caption>Comparison</caption><tr><th>Topic</th><td>Value</td></tr></table><figure><img src="/images/editorial/bitcoin.svg" alt="A Bitcoin illustration"><figcaption>Original illustration</figcaption></figure>',
        ]);

        $this->get('/en/news/market-context')->assertOk()
            ->assertSee('Market context, carefully explained')
            ->assertSee('A short editorial introduction.')
            ->assertSee($article->author->name)
            ->assertSee('Breadcrumbs')
            ->assertSee('<h2 id="article-section-1">Context</h2>', false)
            ->assertSee('<h3 id="article-section-2">Details</h3>', false)
            ->assertSee('href="#article-section-1"', false)
            ->assertSee('<strong>useful</strong>', false)
            ->assertSee('<blockquote>', false)->assertSee('<ul>', false)->assertSee('<ol>', false)
            ->assertSee('<cite>Actual attribution.</cite>', false)
            ->assertSee('class="article-table"', false)
            ->assertSee('loading="lazy"', false)
            ->assertSee('Original illustration')
            ->assertViewHas('content', fn ($content) => count($content['toc']) === 2 && $content['readingMinutes'] === 1)
            ->assertViewHas('seo', fn ($seo) => count($seo['breadcrumbs']) === 4);
    }

    public function test_plain_text_unicode_paragraphs_and_estimated_reading_time_are_preserved(): void
    {
        $article = Article::factory()->published()->create();
        ArticleTranslation::factory()->for($article)->create([
            'locale' => 'ja', 'slug' => '記事',
            'body' => str_repeat('日本語 ', 401)."\n\n第二段落。",
        ]);

        $this->get('/ja/news/記事')->assertOk()
            ->assertSee('<p>第二段落。</p>', false)
            ->assertViewHas('content', fn ($content) => $content['readingMinutes'] === 4 && $content['toc'] === []);
    }

    public function test_cjk_reading_time_counts_characters_without_requiring_spaces(): void
    {
        foreach (['ja', 'zh'] as $locale) {
            $article = Article::factory()->published()->create();
            ArticleTranslation::factory()->for($article)->create([
                'locale' => $locale, 'slug' => 'cjk-reading', 'body' => str_repeat('市場', 400),
            ]);
            $this->get('/'.$locale.'/news/cjk-reading')->assertOk()
                ->assertViewHas('content', fn ($content) => $content['readingMinutes'] === 2);
        }
    }

    public function test_long_content_is_not_lost_to_the_sanitizer_default_length_limit(): void
    {
        $article = Article::factory()->published()->create();
        ArticleTranslation::factory()->for($article)->create([
            'slug' => 'long-read',
            'body' => '<p>'.str_repeat('Editorial context ', 1800).'</p><h2>The final section</h2><p>End of the report.</p>',
        ]);

        $this->get('/en/news/long-read')->assertOk()->assertSee('End of the report.')
            ->assertSee('The final section');
    }

    public function test_unsafe_html_attributes_embeds_and_url_schemes_are_removed(): void
    {
        $article = Article::factory()->published()->create();
        ArticleTranslation::factory()->for($article)->create([
            'slug' => 'safe-rendering', 'excerpt' => 'Safe context',
            'body' => '<p style="position:fixed" onclick="steal()">Visible text</p><script>dangerousCode()</script><iframe src="https://attacker.invalid"></iframe><form><input autofocus onfocus="steal()"></form><svg onload="steal()"><script>bad()</script></svg><math><mtext>unsafe math</mtext></math><a href="jav&#x61;script:steal()" onmouseover="steal()">Unsafe link</a><a href="https://example.org" target="_blank" style="color:red">Safe link</a><img src="data:image/svg+xml,evil" onerror="steal()"><img src="//attacker.invalid/tracking.png"><h2 id="main" data-action="bad">A safe heading</h2>',
        ]);

        $response = $this->get('/en/news/safe-rendering')->assertOk();
        $html = (string) $response->viewData('content')['html'];

        $this->assertStringContainsString('<p>Visible text</p>', $html);
        $this->assertStringContainsString('href="https://example.org"', $html);
        $this->assertStringContainsString('rel="noopener noreferrer"', $html);
        foreach (['<script', '<iframe', '<form', '<input', '<svg', '<math', 'onclick', 'onerror', 'onmouseover', 'style=', 'javascript:', 'data:image', 'attacker.invalid', 'id="main"', 'data-action', 'dangerousCode'] as $unsafe) {
            $this->assertStringNotContainsString($unsafe, $html);
        }
    }

    #[DataProvider('invalidSummaries')]
    public function test_summary_is_hidden_when_real_valid_points_are_absent(mixed $summary): void
    {
        $article = Article::factory()->published()->create();
        ArticleTranslation::factory()->for($article)->create(['slug' => 'no-summary', 'summary' => $summary]);

        $this->get('/en/news/no-summary')->assertOk()
            ->assertDontSee('class="article-summary"', false);
    }

    public static function invalidSummaries(): array
    {
        return [
            'missing' => [null],
            'too short' => [['One point', 'Two points']],
            'too long' => [array_fill(0, 6, 'Point')],
            'wrong type' => [['A point', 4, 'Another point']],
            'blank point' => [['A point', ' ', 'Another point']],
            'object' => [['first' => 'A', 'second' => 'B', 'third' => 'C']],
        ];
    }

    public function test_localized_ai_summary_uses_only_stored_points_and_escapes_them(): void
    {
        $article = Article::factory()->published()->create();
        ArticleTranslation::factory()->for($article)->create([
            'locale' => 'ja', 'slug' => '要約', 'summary_source' => 'ai',
            'summary' => ['最初の要点', '次の要点', '<script>unsafe()</script>'],
        ]);
        ArticleTranslation::factory()->for($article)->create(['locale' => 'en', 'slug' => 'no-translated-summary']);

        $this->get('/ja/news/要約')->assertOk()
            ->assertSee('AI要約')
            ->assertSee('最初の要点')
            ->assertSee('&lt;script&gt;unsafe()&lt;/script&gt;', false)
            ->assertDontSee('<script>unsafe()</script>', false);
        $this->get('/en/news/no-translated-summary')->assertOk()
            ->assertDontSee('class="article-summary"', false);
    }

    public function test_editorial_summary_is_not_claimed_to_be_ai_generated(): void
    {
        $article = Article::factory()->published()->create();
        ArticleTranslation::factory()->for($article)->create([
            'slug' => 'takeaways', 'summary_source' => 'editorial',
            'summary' => ['Read the context.', 'Compare the sources.', 'Consider the assumptions.', 'Review the complete article.', 'Check the publication date.'],
        ]);

        $this->get('/en/news/takeaways')->assertOk()->assertSee('Key takeaways')
            ->assertDontSee('AI-generated summary.');
    }

    public function test_missing_hero_image_uses_a_local_fallback_with_alt_text(): void
    {
        $article = Article::factory()->published()->create(['featured_image' => 'articles/missing-photo.jpg']);
        ArticleTranslation::factory()->for($article)->create(['slug' => 'missing-photo']);

        $this->get('/en/news/missing-photo')->assertOk()
            ->assertSee('/images/editorial/markets.svg', false)
            ->assertSee('alt="CryptoNews editorial illustration"', false)
            ->assertViewHas('hero', fn ($hero) => $hero['fallback'] === true);
    }

    #[DataProvider('categoryFallbacks')]
    public function test_missing_hero_uses_category_artwork_in_the_selected_language(string $locale, string $topic): void
    {
        $category = Category::factory()->has(CategoryTranslation::factory()->state(['locale' => 'en', 'slug' => $topic]), 'translations')->create();

        if ($locale !== 'en') {
            CategoryTranslation::factory()->for($category)->create(['locale' => $locale, 'slug' => $locale.'-'.$topic]);
        }

        $article = Article::factory()->published()->for($category)->create(['featured_image' => 'articles/missing-photo.jpg']);
        ArticleTranslation::factory()->for($article)->create(['locale' => $locale, 'slug' => 'category-fallback']);

        $this->get('/'.$locale.'/news/category-fallback')->assertOk()
            ->assertSee('/images/editorial/'.$topic.'.svg', false)
            ->assertViewHas('hero', fn ($hero) => $hero['fallback'] && str_ends_with($hero['url'], '/editorial/'.$topic.'.svg') && $hero['fallbackUrl'] === $hero['url']);
    }

    public static function categoryFallbacks(): array
    {
        return [
            'English Bitcoin' => ['en', 'bitcoin'],
            'Russian Ethereum' => ['ru', 'ethereum'],
            'Japanese gold' => ['ja', 'gold'],
        ];
    }

    public function test_actual_article_image_takes_precedence_over_category_artwork(): void
    {
        $category = Category::factory()->has(CategoryTranslation::factory()->state(['locale' => 'en', 'slug' => 'gold']), 'translations')->create();
        $article = Article::factory()->published()->for($category)->create(['featured_image' => 'editorial/network.svg']);
        ArticleTranslation::factory()->for($article)->create(['slug' => 'actual-image']);

        $this->get('/en/news/actual-image')->assertOk()
            ->assertSee('/images/editorial/network.svg', false)
            ->assertViewHas('hero', fn ($hero) => ! $hero['fallback'] && str_ends_with($hero['url'], '/editorial/network.svg') && str_ends_with($hero['fallbackUrl'], '/editorial/gold.svg'));
    }

    #[DataProvider('nonPublicStates')]
    public function test_non_public_articles_cannot_expose_reading_or_seo_data(string $status, bool $future): void
    {
        $article = Article::factory()->create(['status' => $status, 'published_at' => $future ? now()->addDay() : now()->subDay()]);
        ArticleTranslation::factory()->for($article)->create(['slug' => 'private-story']);

        $this->get('/en/news/private-story')->assertNotFound();
    }

    public static function nonPublicStates(): array
    {
        return [
            'draft' => [ArticleStatus::Draft->value, false],
            'review' => [ArticleStatus::PendingReview->value, false],
            'archived' => [ArticleStatus::Archived->value, false],
            'scheduled' => [ArticleStatus::Published->value, true],
        ];
    }

    public function test_related_articles_rank_category_then_shared_tags_then_recency(): void
    {
        $category = Category::factory()->create();
        $otherCategory = Category::factory()->create();
        $tag = Tag::factory()->create();
        $current = Article::factory()->published()->for($category)->has(ArticleTranslation::factory()->state(['slug' => 'current']), 'translations')->create();
        $current->tags()->attach($tag);
        $categoryOnly = Article::factory()->published()->for($category)->has(ArticleTranslation::factory(), 'translations')->create(['published_at' => now()->subDays(4)]);
        $categoryAndTag = Article::factory()->published()->for($category)->has(ArticleTranslation::factory(), 'translations')->create(['published_at' => now()->subDays(5)]);
        $tagRecent = Article::factory()->published()->for($otherCategory)->has(ArticleTranslation::factory(), 'translations')->create(['published_at' => now()->subHour()]);
        $tagOlder = Article::factory()->published()->for($otherCategory)->has(ArticleTranslation::factory(), 'translations')->create(['published_at' => now()->subHours(2)]);
        $categoryAndTag->tags()->attach($tag);
        $tagRecent->tags()->attach($tag);
        $tagOlder->tags()->attach($tag);
        Article::factory()->published()->for($otherCategory)->has(ArticleTranslation::factory(), 'translations')->create();
        Article::factory()->for($category)->has(ArticleTranslation::factory(), 'translations')->create();
        Article::factory()->published()->for($category)->has(ArticleTranslation::factory(), 'translations')->create(['published_at' => now()->addHour()]);
        Article::factory()->published()->for($category)->has(ArticleTranslation::factory()->state(['locale' => 'ru']), 'translations')->create();
        $deleted = Article::factory()->published()->for($category)->has(ArticleTranslation::factory(), 'translations')->create();
        $deleted->delete();

        $this->get('/en/news/current')->assertOk()
            ->assertViewHas('related', fn ($related) => $related->modelKeys() === [$categoryAndTag->id, $categoryOnly->id, $tagRecent->id, $tagOlder->id]);
    }

    public function test_reader_renders_gracefully_without_related_articles(): void
    {
        $article = Article::factory()->published()->has(ArticleTranslation::factory()->state(['slug' => 'only-story']), 'translations')->create();

        $this->get('/en/news/only-story')->assertOk()
            ->assertViewHas('related', fn ($related) => $related->isEmpty())
            ->assertDontSee('class="article-related"', false);
    }

    public function test_editorial_sidebar_and_related_cards_do_not_repeat_articles(): void
    {
        $category = Category::factory()->has(CategoryTranslation::factory(), 'translations')->create();
        $current = Article::factory()->published()->for($category)
            ->has(ArticleTranslation::factory()->state(['slug' => 'current']), 'translations')->create();
        $pickTag = Tag::factory()->create(['slug' => 'editors-pick']);
        $stories = Article::factory()->count(4)->published()->for($category)
            ->has(ArticleTranslation::factory(), 'translations')->create();
        foreach ($stories as $story) {
            $story->tags()->attach($pickTag);
        }

        $response = $this->get('/en/news/current')->assertOk()
            ->assertViewHas('related', fn ($related) => $related->count() === 4);
        preg_match_all('/data-story-id="(\d+)"/', $response->getContent(), $matches);

        $this->assertCount(4, $matches[1]);
        $this->assertCount(4, array_unique($matches[1]));
        $this->assertNotContains((string) $current->id, $matches[1]);
        $this->assertEqualsCanonicalizing(array_map('strval', $stories->modelKeys()), $matches[1]);
    }

    public function test_reader_eager_loads_related_article_relationships_without_query_growth(): void
    {
        $category = Category::factory()->has(CategoryTranslation::factory(), 'translations')->create();
        Article::factory()->published()->for($category)->has(ArticleTranslation::factory()->state(['slug' => 'current']), 'translations')->create();
        Article::factory()->published()->for($category)->has(ArticleTranslation::factory(), 'translations')->create();
        Model::preventLazyLoading();
        DB::enableQueryLog();
        DB::flushQueryLog();

        try {
            $this->get('/en/news/current')->assertOk();
            $initialCount = count(DB::getQueryLog());
            Article::factory()->count(12)->published()->for($category)->has(ArticleTranslation::factory(), 'translations')->create();
            DB::flushQueryLog();

            $this->get('/en/news/current')->assertOk();

            $this->assertSame($initialCount, count(DB::getQueryLog()));
        } finally {
            DB::disableQueryLog();
            Model::preventLazyLoading(false);
        }
    }
}
