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

class FrontendExperienceTest extends TestCase
{
    use RefreshDatabase;

    public function test_homepage_renders_published_content_and_honest_demo_sections(): void
    {
        $featured = Article::factory()->published()->has(ArticleTranslation::factory()->state(['title' => 'A clearer financial perspective']), 'translations')->create();
        $pick = Tag::factory()->create(['slug' => 'editors-pick']);
        $featured->tags()->attach($pick);

        $this->get('/en')->assertOk()
            ->assertSee('A clearer financial perspective')
            ->assertSee(route('news.show', ['locale' => 'en', 'slug' => $featured->translations->first()->slug]), false)
            ->assertSee('Fixed illustrative prices and charts. No live data feed.')
            ->assertSee(__('engagement.confirmation_help'))
            ->assertSee(route('newsletter.form', ['locale' => 'en']), false)
            ->assertSee('fetchpriority="high"', false);
    }

    #[DataProvider('hiddenStates')]
    public function test_homepage_and_reader_never_expose_unpublished_or_scheduled_articles(string $status, ?string $date): void
    {
        $this->freezeSecond();
        $article = Article::factory()->create(['status' => $status, 'published_at' => $date === 'future' ? now()->addDay() : ($date === 'past' ? now()->subDay() : null)]);
        ArticleTranslation::factory()->for($article)->create(['title' => 'Private editorial material', 'slug' => 'private-material']);
        $article->tags()->attach(Tag::factory()->create(['slug' => 'editors-pick']));

        $this->get('/en')->assertOk()->assertDontSee('Private editorial material');
        $this->get('/en/news/private-material')->assertNotFound();
    }

    public static function hiddenStates(): array
    {
        return [
            'draft' => [ArticleStatus::Draft->value, 'past'],
            'review' => [ArticleStatus::PendingReview->value, 'past'],
            'archived' => [ArticleStatus::Archived->value, 'past'],
            'future' => [ArticleStatus::Published->value, 'future'],
            'undated' => [ArticleStatus::Published->value, null],
        ];
    }

    public function test_search_and_category_filters_intersect_and_remain_in_pagination_links(): void
    {
        $category = Category::factory()->has(CategoryTranslation::factory(), 'translations')->create();
        Article::factory()->count(14)->published()->for($category)
            ->has(ArticleTranslation::factory()->state(['title' => 'Custody research note']), 'translations')->create();
        Article::factory()->published()->has(ArticleTranslation::factory()->state(['title' => 'Custody elsewhere']), 'translations')->create();
        Article::factory()->published()->for($category)->has(ArticleTranslation::factory()->state(['title' => 'Unrelated topic', 'excerpt' => 'Different field', 'body' => 'Different material']), 'translations')->create();

        $this->get('/en/news?q=Custody&category='.$category->id)->assertOk()
            ->assertViewHas('articles', fn ($articles) => $articles->total() === 14 && $articles->count() === 12)
            ->assertSee('Custody research note')
            ->assertDontSee('Custody elsewhere')
            ->assertDontSee('Unrelated topic')
            ->assertSee('q=Custody&amp;category='.$category->id.'&amp;page=2', false);
        $this->get('/en/news?q=Custody&category='.$category->id.'&page=2')->assertOk()
            ->assertViewHas('articles', fn ($articles) => $articles->count() === 2);
    }

    public function test_search_matches_only_the_current_locale(): void
    {
        $article = Article::factory()->published()->create();
        ArticleTranslation::factory()->for($article)->create(['locale' => 'en', 'title' => 'Unique custody keyword']);
        ArticleTranslation::factory()->for($article)->create(['locale' => 'ja', 'title' => '日本語の市場分析', 'body' => '日本語の本文', 'excerpt' => '日本語の概要']);

        $this->get('/ja/news?q=custody')->assertOk()->assertViewHas('articles', fn ($articles) => $articles->total() === 0);
        $this->get('/ja/news?q='.urlencode('市場'))->assertOk()->assertSee('日本語の市場分析');
    }

    public function test_search_is_bound_and_treats_sql_wildcards_as_literal_characters(): void
    {
        Article::factory()->published()->has(ArticleTranslation::factory()->state(['title' => '100% perspective', 'body' => 'Editorial copy', 'excerpt' => 'Context']), 'translations')->create();
        Article::factory()->published()->has(ArticleTranslation::factory()->state(['title' => 'A different view', 'body' => 'Editorial copy', 'excerpt' => 'Context']), 'translations')->create();

        $this->get('/en/news?q='.urlencode('%'))->assertOk()
            ->assertViewHas('articles', fn ($articles) => $articles->total() === 1)
            ->assertSee('100% perspective')->assertDontSee('A different view');
        $this->get('/en/news?q='.urlencode("' OR 1=1 --"))->assertOk()
            ->assertViewHas('articles', fn ($articles) => $articles->total() === 0);
    }

    public function test_empty_results_offer_a_working_reset_link(): void
    {
        $this->get('/ru/news?q=no-match')->assertOk()->assertSee('Пока ничего не найдено.')
            ->assertSee('href="'.route('news.index', ['locale' => 'ru']).'"', false);
    }

    #[DataProvider('invalidFilters')]
    public function test_malformed_filters_are_rejected(string $query): void
    {
        $this->get('/en/news?'.$query)->assertUnprocessable();
    }

    public static function invalidFilters(): array
    {
        return [
            'array search' => ['q[]=bitcoin'],
            'long search' => ['q='.str_repeat('x', 121)],
            'invalid category' => ['category=abc'],
            'array category' => ['category[]=1'],
            'invalid page' => ['page=0'],
            'array topic' => ['topic[]=metals'],
        ];
    }

    public function test_language_links_preserve_search_and_category_but_reset_pagination(): void
    {
        $category = Category::factory()->has(CategoryTranslation::factory(), 'translations')->create();
        $expected = route('news.index', ['locale' => 'ja', 'q' => 'market', 'category' => $category->id]);

        $this->get('/en/news?q=market&category='.$category->id.'&page=2')->assertOk()
            ->assertSee('href="'.e($expected).'"', false);
    }

    public function test_reader_switches_to_actual_translated_slugs_and_escapes_content(): void
    {
        $article = Article::factory()->published()->create();
        ArticleTranslation::factory()->for($article)->create(['locale' => 'en', 'slug' => 'english-story', 'body' => '<script>alert(1)</script>', 'meta_title' => '<script>unsafe</script>']);
        ArticleTranslation::factory()->for($article)->create(['locale' => 'ja', 'slug' => '日本語の記事']);

        $this->get('/en/news/english-story')->assertOk()
            ->assertSee('/ja/news/'.rawurlencode('日本語の記事'), false)
            ->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false)
            ->assertDontSee('<script>alert(1)</script>', false)
            ->assertDontSee('<script>unsafe</script>', false)
            ->assertDontSee('rel="alternate" hreflang="ru"', false);
        $this->get('/ru/news/english-story')->assertNotFound();
    }

    public function test_gold_and_silver_navigation_filters_both_metals(): void
    {
        foreach (['gold', 'silver', 'bitcoin'] as $slug) {
            $category = Category::factory()->has(CategoryTranslation::factory()->state(['slug' => $slug]), 'translations')->create();
            Article::factory()->published()->for($category)->has(ArticleTranslation::factory()->state(['title' => ucfirst($slug).' report']), 'translations')->create();
        }

        $this->get('/en/news?topic=metals')->assertOk()
            ->assertViewHas('articles', fn ($articles) => $articles->total() === 2)
            ->assertSee('Gold report')->assertSee('Silver report')->assertDontSee('Bitcoin report');
    }

    public function test_cards_use_eager_loaded_relationships_with_constant_query_count(): void
    {
        Model::preventLazyLoading();
        $category = Category::factory()->has(CategoryTranslation::factory(), 'translations')->create();
        $tag = Tag::factory()->create(['slug' => 'editors-pick']);
        $article = Article::factory()->published()->for($category)->has(ArticleTranslation::factory(), 'translations')->create();
        $article->tags()->attach($tag);
        DB::enableQueryLog();
        DB::flushQueryLog();

        try {
            $this->get('/en/news')->assertOk();
            $firstCount = count(DB::getQueryLog());
            Article::factory()->count(11)->published()->for($category)->has(ArticleTranslation::factory(), 'translations')->create();
            DB::flushQueryLog();

            $this->get('/en/news')->assertOk();

            $this->assertSame($firstCount, count(DB::getQueryLog()));
        } finally {
            DB::disableQueryLog();
            Model::preventLazyLoading(false);
        }
    }
}
