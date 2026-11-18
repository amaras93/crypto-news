<?php

namespace Tests\Feature;

use App\Models\Article;
use App\Models\ArticleTranslation;
use App\Models\Category;
use App\Models\CategoryTranslation;
use App\Models\Tag;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class NewspaperDesignTest extends TestCase
{
    use RefreshDatabase;

    public function test_breaking_and_opinion_are_hidden_without_eligible_editorial_designations(): void
    {
        $this->publishedStory('Ordinary published report');
        $breaking = Tag::factory()->create(['slug' => 'breaking']);
        $opinion = Tag::factory()->create(['slug' => 'opinion']);
        $draft = Article::factory()->has(ArticleTranslation::factory()->state(['title' => 'Private breaking headline']), 'translations')->create();
        $draft->tags()->attach([$breaking->id, $opinion->id]);
        $future = $this->publishedStory('Future breaking headline');
        $future->update(['published_at' => now()->addDay()]);
        $future->tags()->attach($breaking);

        $this->get('/en')->assertOk()->assertSee('Ordinary published report')
            ->assertDontSee('class="paper-breaking"', false)
            ->assertDontSee('class="paper-opinion"', false)
            ->assertDontSee('Private breaking headline')
            ->assertDontSee('Future breaking headline')
            ->assertViewHas('breaking', null)
            ->assertViewHas('opinion', null);
    }

    public function test_breaking_strip_uses_the_designated_story_and_its_real_time(): void
    {
        $this->freezeSecond();
        $story = $this->publishedStory('Editor designated breaking report');
        $story->tags()->attach(Tag::factory()->create(['slug' => 'breaking']));

        $this->get('/en')->assertOk()
            ->assertViewHas('breaking', fn ($breaking) => $breaking->is($story))
            ->assertSee('class="paper-breaking"', false)
            ->assertSee('datetime="'.$story->published_at->toIso8601String().'"', false)
            ->assertSee(route('news.show', ['locale' => 'en', 'slug' => $story->translations->first()->slug]), false);
    }

    public function test_frontpage_does_not_repeat_articles_across_its_editorial_sections(): void
    {
        $this->freezeSecond();
        $pickTag = Tag::factory()->create(['slug' => 'editors-pick']);
        $analysisTag = Tag::factory()->create(['slug' => 'analysis']);
        $opinionTag = Tag::factory()->create(['slug' => 'opinion']);
        $lead = $this->publishedStory('Lead story');
        $lead->tags()->attach([$pickTag->id, $analysisTag->id]);
        $pick = $this->publishedStory('Selected story');
        $pick->tags()->attach($pickTag);
        $analysis = $this->publishedStory('Analysis story');
        $analysis->tags()->attach($analysisTag);
        $opinion = $this->publishedStory('Opinion story');
        $opinion->tags()->attach($opinionTag);
        foreach (range(1, 4) as $number) {
            $this->publishedStory('Latest story '.$number);
        }
        $lead->update(['published_at' => now()]);

        $response = $this->get('/en')->assertOk()
            ->assertViewHas('featured', fn ($featured) => $featured->is($lead))
            ->assertViewHas('picks', fn ($picks) => $picks->modelKeys() === [$pick->id])
            ->assertViewHas('analysis', fn ($selected) => $selected->is($analysis))
            ->assertViewHas('opinion', fn ($selected) => $selected->is($opinion));

        preg_match_all('/data-story-id="(\d+)"/', $response->getContent(), $matches);
        $this->assertCount(8, $matches[1]);
        $this->assertCount(8, array_unique($matches[1]));
    }

    public function test_featured_listing_and_pagination_never_repeat_or_lose_articles(): void
    {
        Article::factory()->count(15)->published()->has(ArticleTranslation::factory(), 'translations')->create();
        $firstPage = $this->get('/en/news')->assertOk();
        preg_match_all('/data-story-id="(\d+)"/', $firstPage->getContent(), $firstMatches);
        $expected = $firstPage->viewData('articles')->modelKeys();
        $this->assertSame(array_map('strval', $expected), $firstMatches[1]);
        $this->assertCount(12, array_unique($firstMatches[1]));
        $firstPage->assertSee('paper-listing-feature')->assertSee('paper-listing-grid');

        $secondPage = $this->get('/en/news?page=2')->assertOk()
            ->assertDontSee('class="paper-listing-feature', false);
        preg_match_all('/data-story-id="(\d+)"/', $secondPage->getContent(), $secondMatches);
        $this->assertCount(3, $secondMatches[1]);
        $this->assertSame([], array_intersect($firstMatches[1], $secondMatches[1]));
        $this->assertCount(15, array_unique(array_merge($firstMatches[1], $secondMatches[1])));
    }

    #[DataProvider('editorialTopics')]
    public function test_editorial_topic_filters_intersect_search_category_and_locale(string $topic): void
    {
        $category = Category::factory()->has(CategoryTranslation::factory(), 'translations')->create();
        $tag = Tag::factory()->create(['slug' => $topic]);
        $selected = $this->publishedStory('Custody outlook');
        $selected->update(['category_id' => $category->id]);
        $selected->tags()->attach($tag);
        $elsewhere = $this->publishedStory('Custody outside the category');
        $elsewhere->tags()->attach($tag);
        $untagged = $this->publishedStory('Custody without designation');
        $untagged->update(['category_id' => $category->id]);

        $parameters = ['locale' => 'en', 'q' => 'Custody', 'category' => $category->id, 'topic' => $topic];
        $this->get(route('news.index', $parameters))->assertOk()
            ->assertViewHas('articles', fn ($articles) => $articles->total() === 1 && $articles->first()->is($selected))
            ->assertSee('Custody outlook')
            ->assertDontSee('Custody outside the category')
            ->assertDontSee('Custody without designation')
            ->assertSee('href="'.e(route('news.index', array_replace($parameters, ['locale' => 'ru']))).'"', false);

        $this->get('/ru/news?topic='.$topic)->assertOk()
            ->assertViewHas('articles', fn ($articles) => $articles->total() === 0);
        $this->get('/en/news?topic=unimplemented')->assertUnprocessable();
    }

    public static function editorialTopics(): array
    {
        return ['analysis' => ['analysis'], 'opinion' => ['opinion']];
    }

    private function publishedStory(string $title): Article
    {
        return Article::factory()->published()->has(
            ArticleTranslation::factory()->state(['title' => $title, 'body' => 'An actual editorial paragraph.', 'excerpt' => 'Editorial context.']),
            'translations',
        )->create(['published_at' => now()->subHour()]);
    }
}
