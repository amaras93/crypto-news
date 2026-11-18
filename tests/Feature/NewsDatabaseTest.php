<?php

namespace Tests\Feature;

use App\ArticleStatus;
use App\ContentSource;
use App\Models\Article;
use App\Models\ArticleTranslation;
use App\Models\Category;
use App\Models\CategoryTranslation;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class NewsDatabaseTest extends TestCase
{
    use RefreshDatabase;

    public function test_article_relationships_and_editorial_fields_round_trip(): void
    {
        $this->freezeSecond();
        $category = Category::factory()->has(CategoryTranslation::factory(), 'translations')->create();
        $author = User::factory()->create();
        $reviewer = User::factory()->admin()->create();
        $tag = Tag::factory()->create();
        $article = Article::factory()->for($author, 'author')->for($reviewer, 'reviewer')->for($category)
            ->has(ArticleTranslation::factory(), 'translations')->create([
                'status' => ArticleStatus::PendingReview,
                'content_source' => ContentSource::Ai,
                'approved_at' => now(),
                'featured_image' => 'articles/feature.jpg',
            ]);

        $article->tags()->attach($tag);

        $stored = $article->fresh();
        $this->assertTrue($stored->author->is($author));
        $this->assertTrue($stored->reviewer->is($reviewer));
        $this->assertTrue($stored->category->is($category));
        $this->assertTrue($tag->articles->sole()->is($article));
        $this->assertTrue($author->articles->sole()->is($article));
        $this->assertTrue($reviewer->reviewedArticles->sole()->is($article));
        $this->assertTrue($category->articles->sole()->is($article));
        $this->assertSame(ArticleStatus::PendingReview, $stored->status);
        $this->assertSame(ContentSource::Ai, $stored->content_source);
        $this->assertTrue($stored->approved_at->equalTo(now()));
        $this->assertSame('articles/feature.jpg', $stored->featured_image);
    }

    public function test_armenian_text_slugs_and_localized_seo_are_preserved(): void
    {
        $translation = ArticleTranslation::factory()->create([
            'locale' => 'hy',
            'title' => 'Հայաստանի ֆինանսական նորություններ',
            'slug' => 'ֆինանսական-նորություններ',
            'body' => 'Ոսկու և արծաթի շուկաներ։',
            'meta_title' => 'Հայերեն վերնագիր',
            'meta_description' => 'Շուկայի վերլուծություն',
        ]);

        $stored = $translation->fresh();

        $this->assertSame('Հայաստանի ֆինանսական նորություններ', $stored->title);
        $this->assertSame('ֆինանսական-նորություններ', $stored->slug);
        $this->assertSame('Ոսկու և արծաթի շուկաներ։', $stored->body);
        $this->assertSame('Հայերեն վերնագիր', $stored->meta_title);
        $this->assertSame('Շուկայի վերլուծություն', $stored->meta_description);
    }

    #[DataProvider('translationModels')]
    public function test_only_one_translation_per_entity_and_locale_is_allowed(string $model): void
    {
        $translation = $model::factory()->create();
        $foreignKey = $model === ArticleTranslation::class ? 'article_id' : 'category_id';

        $this->expectException(QueryException::class);

        $model::factory()->create([$foreignKey => $translation->{$foreignKey}, 'locale' => $translation->locale]);
    }

    #[DataProvider('translationModels')]
    public function test_slugs_are_unique_within_each_language(string $model): void
    {
        $model::factory()->create(['locale' => 'en', 'slug' => 'bitcoin']);
        $model::factory()->create(['locale' => 'hy', 'slug' => 'bitcoin']);

        $this->expectException(QueryException::class);

        $model::factory()->create(['locale' => 'en', 'slug' => 'bitcoin']);
    }

    public static function translationModels(): array
    {
        return [
            'articles' => [ArticleTranslation::class],
            'categories' => [CategoryTranslation::class],
        ];
    }

    public function test_an_article_cannot_be_tagged_twice_with_the_same_tag(): void
    {
        $article = Article::factory()->create();
        $tag = Tag::factory()->create();
        $article->tags()->attach($tag);

        $this->expectException(QueryException::class);

        $article->tags()->attach($tag);
    }

    public function test_hard_deleting_an_article_cascades_its_translations_and_tag_links(): void
    {
        $article = Article::factory()->has(ArticleTranslation::factory(), 'translations')->create();
        $tag = Tag::factory()->create();
        $article->tags()->attach($tag);

        $article->forceDelete();

        $this->assertDatabaseMissing('article_translations', ['article_id' => $article->id]);
        $this->assertDatabaseMissing('article_tag', ['article_id' => $article->id]);
        $this->assertModelExists($tag);
    }

    public function test_deleting_a_category_preserves_its_articles(): void
    {
        $category = Category::factory()->has(CategoryTranslation::factory(), 'translations')->create();
        $article = Article::factory()->for($category)->create();

        $category->delete();

        $this->assertModelExists($article);
        $this->assertNull($article->fresh()->category_id);
        $this->assertDatabaseMissing('category_translations', ['category_id' => $category->id]);
    }

    public function test_deleting_an_author_with_articles_is_restricted(): void
    {
        $article = Article::factory()->create();

        $this->expectException(QueryException::class);

        $article->author->delete();
    }
}
