<?php

namespace Tests\Feature;

use App\ArticleStatus;
use App\Models\Article;
use App\Models\ArticleTranslation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class NewsVisibilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_listing_shows_only_published_due_translated_articles(): void
    {
        $this->freezeTime();
        $visible = Article::factory()->published()->has(ArticleTranslation::factory()->state(['title' => 'Visible market report']), 'translations')->create();
        Article::factory()->has(ArticleTranslation::factory()->state(['title' => 'Private draft']), 'translations')->create();
        Article::factory()->published()->state(['published_at' => now()->addDay()])->has(ArticleTranslation::factory()->state(['title' => 'Future report']), 'translations')->create();
        Article::factory()->published()->state(['published_at' => null])->has(ArticleTranslation::factory()->state(['title' => 'Undated report']), 'translations')->create();
        Article::factory()->published()->state(['status' => ArticleStatus::PendingReview])->has(ArticleTranslation::factory()->state(['title' => 'Unapproved draft']), 'translations')->create();
        Article::factory()->published()->state(['status' => ArticleStatus::Archived])->has(ArticleTranslation::factory()->state(['title' => 'Archived report']), 'translations')->create();
        Article::factory()->published()->has(ArticleTranslation::factory()->state(['locale' => 'ru', 'title' => 'Russian only']), 'translations')->create();
        $deleted = Article::factory()->published()->has(ArticleTranslation::factory()->state(['title' => 'Deleted report']), 'translations')->create();
        $deleted->delete();

        $this->get('/en/news')->assertOk()
            ->assertSee('Visible market report')
            ->assertDontSee('Private draft')
            ->assertDontSee('Future report')
            ->assertDontSee('Undated report')
            ->assertDontSee('Unapproved draft')
            ->assertDontSee('Archived report')
            ->assertDontSee('Russian only')
            ->assertDontSee('Deleted report')
            ->assertViewHas('articles', fn ($articles) => $articles->total() === 1 && $articles->first()->is($visible));
    }

    #[DataProvider('translatedHeadlines')]
    public function test_listing_renders_only_the_requested_translation(string $locale, string $headline): void
    {
        $article = Article::factory()->published()->create();

        foreach (self::translatedHeadlines() as [$language, $title]) {
            ArticleTranslation::factory()->for($article)->create(['locale' => $language, 'title' => $title]);
        }

        $response = $this->get('/'.$locale.'/news')->assertOk()->assertSee($headline);

        foreach (self::translatedHeadlines() as [$language, $title]) {
            if ($language !== $locale) {
                $response->assertDontSee($title);
            }
        }
    }

    public static function translatedHeadlines(): array
    {
        return [
            ['en', 'Bitcoin market report'],
            ['ru', 'Обзор рынка биткоина'],
            ['fr', 'Analyse du marché du bitcoin'],
            ['de', 'Bericht zum Bitcoin-Markt'],
            ['es', 'Informe del mercado de bitcoin'],
            ['it', 'Rapporto sul mercato bitcoin'],
            ['ja', 'ビットコイン市場の分析'],
            ['zh', '比特币市场分析'],
        ];
    }

    public function test_listing_paginates_in_publication_order_and_keeps_locale_links(): void
    {
        $this->freezeTime();
        Article::factory()->count(13)->published()
            ->has(ArticleTranslation::factory()->state(['locale' => 'ja']), 'translations')->create();
        $latest = Article::factory()->published()->state(['published_at' => now()])
            ->has(ArticleTranslation::factory()->state(['locale' => 'ja', 'title' => 'Newest article']), 'translations')->create();

        $this->get('/ja/news')->assertOk()->assertSee('Newest article')
            ->assertViewHas('articles', fn ($articles) => $articles->count() === 12 && $articles->total() === 14 && $articles->first()->is($latest))
            ->assertSee('/ja/news?page=2', false)
            ->assertSee(route('news.index', ['locale' => 'ru']), false);
        $this->get('/ja/news?page=2')->assertOk()
            ->assertViewHas('articles', fn ($articles) => $articles->count() === 2);
    }

    public function test_untrusted_headlines_and_excerpts_are_escaped(): void
    {
        Article::factory()->published()->has(ArticleTranslation::factory()->state([
            'title' => '<script>alert(1)</script>',
            'excerpt' => '<img src=x onerror=alert(2)>',
        ]), 'translations')->create();

        $this->get('/en/news')->assertOk()
            ->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false)
            ->assertDontSee('<script>alert(1)</script>', false)
            ->assertDontSee('<img src=x onerror=alert(2)>', false);
    }
}
