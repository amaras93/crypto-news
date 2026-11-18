<?php

namespace Tests\Feature;

use App\Models\Article;
use App\Models\ArticleTranslation;
use App\Models\Tag;
use App\Models\User;
use Database\Seeders\DemoNewsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use Tests\TestCase;

class DemoNewsSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_demo_seed_is_repeatable_and_never_overwrites_existing_records(): void
    {
        $userArticle = Article::factory()->has(ArticleTranslation::factory()->state(['title' => 'User-owned article']), 'translations')->create();
        $this->seed(DemoNewsSeeder::class);
        $demo = ArticleTranslation::where('slug', 'demo-bitcoin-long-view-en')->sole();
        $demo->update(['title' => 'User-edited demo headline']);

        $this->seed(DemoNewsSeeder::class);

        $this->assertDatabaseCount('articles', 16);
        $this->assertDatabaseCount('article_translations', 31);
        $this->assertDatabaseCount('categories', 9);
        $this->assertDatabaseCount('tags', 3);
        $this->assertSame('User-edited demo headline', $demo->fresh()->title);
        $this->assertSame('User-owned article', $userArticle->translations->sole()->title);
        $this->assertFalse(User::where('email', 'demo-editor@cryptonews.invalid')->sole()->is_admin);
        $this->assertSame(15, Tag::where('slug', 'cryptonews-demo')->sole()->articles()->count());
    }

    public function test_demo_seed_is_refused_in_production_without_writing_data(): void
    {
        $this->app->instance('env', 'production');

        try {
            $this->app->make(DemoNewsSeeder::class)->run();
            $this->fail('Production demo seeding should be refused.');
        } catch (LogicException $exception) {
            $this->assertStringContainsString('local or testing', $exception->getMessage());
        }

        $this->assertDatabaseCount('articles', 0);
        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('categories', 0);
    }

    public function test_demo_content_is_identified_in_available_fixture_locales(): void
    {
        $this->seed(DemoNewsSeeder::class);

        foreach (['en', 'ru'] as $locale) {
            $this->get('/'.$locale)->assertOk()->assertSee(trans('ui.demo_article', [], $locale));
            $this->get('/'.$locale.'/news/demo-bitcoin-long-view-'.$locale)->assertOk()->assertSee(trans('ui.demo_notice', [], $locale));
        }
    }
}
