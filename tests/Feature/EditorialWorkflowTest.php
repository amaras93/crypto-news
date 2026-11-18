<?php

namespace Tests\Feature;

use App\ArticleStatus;
use App\ContentSource;
use App\Models\Article;
use App\Models\ArticleTranslation;
use App\Models\Category;
use App\Models\CategoryTranslation;
use App\Models\EditorialEvent;
use App\Models\Tag;
use App\Models\User;
use App\Support\ArticleEditor;
use App\Support\ArticlePublication;
use App\Support\EditorialTaxonomy;
use App\Support\EditorialUsers;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class EditorialWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private function draft(User $actor, string $slug = 'editorial-story'): Article
    {
        return app(ArticleEditor::class)->save($actor, $this->data($actor, $slug));
    }

    private function data(User $actor, string $slug = 'editorial-story'): array
    {
        return ['author_id' => $actor->id, 'tag_ids' => [], 'translations' => [
            'en' => ['enabled' => true, 'title' => 'Editorial headline', 'slug' => $slug, 'body' => '<h2>Context</h2><p>Original reporting with readable context.</p>'],
        ]];
    }

    public function test_editor_can_create_a_sanitized_partial_multilingual_draft_with_real_relationships(): void
    {
        $editor = User::factory()->editor()->create();
        $tag = Tag::factory()->create();
        $data = $this->data($editor);
        $data['tag_ids'] = [$tag->id];
        $data['translations']['ja'] = ['enabled' => true, 'title' => '市場の分析', 'slug' => '市場の分析', 'body' => '<p>日本語の記事です。</p>', 'summary' => ['第一', '第二', '第三'], 'summary_source' => 'manual'];
        $data['translations']['en']['body'] .= '<script>alert(1)</script><a href="javascript:alert(1)">unsafe</a><img src="x" onerror="alert(1)">';
        $article = app(ArticleEditor::class)->save($editor, $data);
        $this->assertSame(ArticleStatus::Draft, $article->status);
        $this->assertSame($editor->id, $article->author_id);
        $this->assertCount(2, $article->translations);
        $this->assertSame([$tag->id], $article->tags->modelKeys());
        $this->assertStringNotContainsString('<script', $article->translation('en')->body);
        $this->assertStringNotContainsString('javascript:', $article->translation('en')->body);
        $this->assertStringNotContainsString('onerror', $article->translation('en')->body);
        $this->assertSame(['第一', '第二', '第三'], $article->translation('ja')->summaryPoints());
        $this->get('/en/news/editorial-story')->assertNotFound();
        $this->get('/ja/news/市場の分析')->assertNotFound();
        $this->assertDatabaseHas('editorial_events', ['article_id' => $article->id, 'user_id' => $editor->id, 'action' => 'content_saved']);
    }

    public function test_review_approval_publish_and_unpublish_are_authorized_and_idempotent(): void
    {
        $this->freezeSecond();
        $editor = User::factory()->editor()->create();
        $admin = User::factory()->admin()->create();
        $article = $this->draft($editor);
        $publication = app(ArticlePublication::class);
        $article = $publication->transition($editor, $article, 'submit_review');
        $this->assertSame(ArticleStatus::PendingReview, $article->status);
        $this->assertNull($article->approved_at);
        $publication->transition($editor, $article, 'submit_review');
        $this->assertSame(1, EditorialEvent::where('action', 'submit_review')->count());
        $this->get('/en/news/editorial-story')->assertNotFound();
        $article = $publication->transition($admin, $article, 'approve');
        $this->assertSame(ArticleStatus::Draft, $article->status);
        $this->assertSame($admin->id, $article->reviewer_id);
        $this->assertNotNull($article->approved_at);
        $article = $publication->transition($admin, $article, 'publish');
        $date = $article->published_at;
        $this->assertSame(ArticleStatus::Published, $article->status);
        $this->get('/en/news/editorial-story')->assertOk();
        $this->get('/ru/news/editorial-story')->assertNotFound();
        $publication->transition($admin, $article, 'publish');
        $this->assertTrue($date->equalTo($article->fresh()->published_at));
        $this->assertSame(1, EditorialEvent::where('action', 'publish')->count());
        $article = $publication->transition($admin, $article, 'unpublish');
        $this->assertSame(ArticleStatus::Draft, $article->status);
        $this->assertNull($article->approved_at);
        $this->get('/en/news/editorial-story')->assertNotFound();
    }

    #[DataProvider('privilegedActions')]
    public function test_editors_cannot_execute_publication_actions(string $action): void
    {
        $editor = User::factory()->editor()->create();
        $article = $this->draft($editor);
        $this->expectException(AuthorizationException::class);
        app(ArticlePublication::class)->transition($editor, $article, $action, CarbonImmutable::now()->addHour());
    }

    public static function privilegedActions(): array
    {
        return [['approve'], ['schedule'], ['publish'], ['unpublish']];
    }

    public function test_editor_cannot_edit_another_authors_draft_or_change_authorship(): void
    {
        $editor = User::factory()->editor()->create();
        $other = User::factory()->editor()->create();
        $article = $this->draft($other);
        try {
            app(ArticleEditor::class)->save($editor, $this->data($editor), $article);
            $this->fail('Other authors drafts must be protected.');
        } catch (AuthorizationException) {
            $this->assertSame($other->id, $article->fresh()->author_id);
        }
        $data = $this->data($other, 'forged-author');
        $this->expectException(AuthorizationException::class);
        app(ArticleEditor::class)->save($editor, $data);
    }

    public function test_forged_workflow_fields_and_unsupported_translations_are_rejected_atomically(): void
    {
        $editor = User::factory()->editor()->create();
        foreach (['status', 'approved_at', 'reviewer_id', 'published_at'] as $field) {
            $data = $this->data($editor);
            $data[$field] = 'published';
            try {
                app(ArticleEditor::class)->save($editor, $data);
                $this->fail('Protected workflow attributes must not be writable.');
            } catch (ValidationException) {
                $this->assertDatabaseCount('articles', 0);
            }
        }
        $data = $this->data($editor);
        $data['translations']['hy'] = $data['translations']['en'];
        $this->expectException(ValidationException::class);
        app(ArticleEditor::class)->save($editor, $data);
    }

    public function test_duplicate_or_unsafe_localized_slugs_do_not_leave_partial_records(): void
    {
        $editor = User::factory()->editor()->create();
        $this->draft($editor);
        foreach (['editorial-story', '../outside', 'bad/path', 'bad%2Fpath'] as $slug) {
            try {
                $this->draft($editor, $slug);
                $this->fail('Invalid or duplicate slug must fail.');
            } catch (ValidationException) {
                $this->assertDatabaseCount('articles', 1);
                $this->assertDatabaseCount('article_translations', 1);
            }
        }
        $data = $this->data($editor, 'new-english');
        $data['translations']['fr'] = ['enabled' => true, 'title' => '', 'slug' => 'francais', 'body' => 'Valid body'];
        try {
            app(ArticleEditor::class)->save($editor, $data);
            $this->fail('The complete article save must roll back.');
        } catch (ValidationException) {
            $this->assertDatabaseCount('articles', 1);
        }
    }

    public function test_historical_armenian_content_and_published_slugs_are_preserved(): void
    {
        $admin = User::factory()->admin()->create();
        $article = $this->draft($admin);
        $archive = ArticleTranslation::factory()->for($article)->create(['locale' => 'hy', 'slug' => 'հին-հոդված', 'body' => 'Պահպանված բնօրինակ։']);
        $original = $archive->fresh()->getRawOriginal();
        $article = app(ArticlePublication::class)->transition($admin, $article, 'publish');
        $data = app(ArticleEditor::class)->formData($article);
        $this->assertArrayNotHasKey('hy', $data['translations']);
        $data['translations']['en']['body'] = '<p>Human revised reporting.</p>';
        app(ArticleEditor::class)->save($admin, $data, $article);
        $this->assertSame($original, $archive->fresh()->getRawOriginal());
        $this->get('/en/news/editorial-story')->assertOk()->assertSee('Human revised reporting.');
        $data['translations']['en']['slug'] = 'changed-url';
        $this->expectException(ValidationException::class);
        app(ArticleEditor::class)->save($admin, $data, $article);
    }

    public function test_editing_an_approved_draft_revokes_approval_and_editors_cannot_edit_publications(): void
    {
        $editor = User::factory()->editor()->create();
        $admin = User::factory()->admin()->create();
        $article = $this->draft($editor);
        $article = app(ArticlePublication::class)->transition($editor, $article, 'submit_review');
        $article = app(ArticlePublication::class)->transition($admin, $article, 'approve');
        $article = app(ArticleEditor::class)->save($editor, $this->data($editor), $article);
        $this->assertNull($article->approved_at);
        $article = app(ArticlePublication::class)->transition($admin, $article, 'publish');
        $this->expectException(AuthorizationException::class);
        app(ArticleEditor::class)->save($editor, $this->data($editor), $article);
    }

    public function test_scheduled_publication_is_private_until_due_and_publishes_once(): void
    {
        $this->freezeSecond();
        $admin = User::factory()->admin()->create();
        $article = $this->draft($admin);
        $article = app(ArticlePublication::class)->transition($admin, $article, 'schedule', CarbonImmutable::now()->addMinutes(5));
        $this->assertSame(ArticleStatus::Scheduled, $article->status);
        $this->get('/en/news/editorial-story')->assertNotFound();
        Artisan::call('articles:publish-scheduled');
        $this->assertSame(ArticleStatus::Scheduled, $article->fresh()->status);
        $this->travel(5)->minutes();
        $this->get('/en/news/editorial-story')->assertNotFound();
        Artisan::call('articles:publish-scheduled');
        $this->assertSame(ArticleStatus::Published, $article->fresh()->status);
        $this->get('/en/news/editorial-story')->assertOk();
        Artisan::call('articles:publish-scheduled');
        $this->assertSame(1, EditorialEvent::where('action', 'scheduled_published')->count());
    }

    public function test_scheduling_requires_future_time_and_current_approver_permission(): void
    {
        $admin = User::factory()->admin()->create();
        $article = $this->draft($admin);
        try {
            app(ArticlePublication::class)->transition($admin, $article, 'schedule', CarbonImmutable::now()->subHour());
            $this->fail('Scheduling in the past must fail.');
        } catch (ValidationException) {
            $this->assertSame(ArticleStatus::Draft, $article->fresh()->status);
        }
        $article = app(ArticlePublication::class)->transition($admin, $article, 'schedule', CarbonImmutable::now()->addMinute());
        $admin->forceFill(['is_admin' => false])->save();
        $this->travel(2)->minutes();
        Artisan::call('articles:publish-scheduled');
        $this->assertSame(ArticleStatus::Scheduled, $article->fresh()->status);
        $this->get('/en/news/editorial-story')->assertNotFound();
    }

    public function test_provenance_is_explicit_but_never_grants_publication_permission(): void
    {
        $editor = User::factory()->editor()->create();
        $article = app(ArticleEditor::class)->save($editor, $this->data($editor), source: ContentSource::Ai);
        $this->assertSame(ContentSource::Ai, $article->content_source);
        $this->assertSame(ArticleStatus::Draft, $article->status);
        $again = app(ArticleEditor::class)->save($editor, $this->data($editor), $article);
        $this->assertSame(ContentSource::Ai, $again->content_source);
        $this->get('/en/news/editorial-story')->assertNotFound();
        $this->expectException(AuthorizationException::class);
        app(ArticlePublication::class)->transition($editor, $article, 'publish');
    }

    public function test_cms_saves_preserve_unedited_canonical_and_social_fields(): void
    {
        $admin = User::factory()->admin()->create();
        $article = $this->draft($admin);
        $article->translation('en')->update(['canonical_url' => 'https://cryptonews.test/en/news/editorial-story', 'og_image' => 'editorial/bitcoin.svg']);
        app(ArticleEditor::class)->save($admin, app(ArticleEditor::class)->formData($article->fresh()), $article);
        $translation = $article->fresh()->translation('en');
        $this->assertSame('https://cryptonews.test/en/news/editorial-story', $translation->canonical_url);
        $this->assertSame('editorial/bitcoin.svg', $translation->og_image);
    }

    public function test_publication_rejects_empty_content_and_a_revoked_admin_session(): void
    {
        $admin = User::factory()->admin()->create();
        $article = Article::factory()->for($admin, 'author')->create();
        try {
            app(ArticlePublication::class)->transition($admin, $article, 'publish');
            $this->fail('Empty drafts cannot be published.');
        } catch (ValidationException) {
            $this->assertSame(ArticleStatus::Draft, $article->fresh()->status);
        }
        $admin->forceFill(['is_admin' => false])->save();
        $this->expectException(AuthorizationException::class);
        app(ArticlePublication::class)->transition($admin, $article, 'publish');
    }

    public function test_trash_is_reversible_keeps_translations_and_restores_only_as_a_draft(): void
    {
        $admin = User::factory()->admin()->create();
        $article = $this->draft($admin);
        $article = app(ArticlePublication::class)->transition($admin, $article, 'publish');
        app(ArticlePublication::class)->trash($admin, $article);
        $this->assertSoftDeleted($article);
        $this->assertDatabaseCount('article_translations', 1);
        $this->get('/en/news/editorial-story')->assertNotFound();
        app(ArticlePublication::class)->restore($admin, Article::withTrashed()->findOrFail($article->id));
        $this->assertFalse($article->fresh()->trashed());
        $this->assertSame(ArticleStatus::Draft, $article->fresh()->status);
        $this->get('/en/news/editorial-story')->assertNotFound();
    }

    public function test_editors_cannot_delete_even_their_own_drafts(): void
    {
        $editor = User::factory()->editor()->create();
        $this->expectException(AuthorizationException::class);
        app(ArticlePublication::class)->trash($editor, $this->draft($editor));
    }

    public function test_taxonomy_preserves_referenced_and_archived_categories_and_unique_tags(): void
    {
        $admin = User::factory()->admin()->create();
        $taxonomy = app(EditorialTaxonomy::class);
        $category = $taxonomy->category($admin, ['sort_order' => 2, 'translations' => ['en' => ['enabled' => true, 'name' => 'Bitcoin', 'slug' => 'bitcoin']]]);
        CategoryTranslation::factory()->for($category)->create(['locale' => 'hy', 'slug' => 'բիթքոյն']);
        $archive = $category->translations()->where('locale', 'hy')->first()->getRawOriginal();
        $taxonomy->category($admin, ['sort_order' => 3, 'translations' => ['fr' => ['enabled' => true, 'name' => 'Bitcoin', 'slug' => 'bitcoin-fr']]], $category);
        $this->assertSame($archive, $category->translations()->where('locale', 'hy')->first()->getRawOriginal());
        $tag = $taxonomy->tag($admin, ['name' => 'Analysis', 'slug' => 'analysis']);
        $article = $this->draft($admin);
        $article->tags()->attach($tag);
        foreach ([$category, $tag] as $protected) {
            try {
                $taxonomy->delete($admin, $protected);
                $this->fail('Referenced / archived taxonomy must not be deleted.');
            } catch (AuthorizationException) {
                $this->assertModelExists($protected);
            }
        }
        $this->expectException(ValidationException::class);
        $taxonomy->tag($admin, ['name' => 'Duplicate', 'slug' => 'analysis']);
    }

    public function test_unused_taxonomy_can_be_deleted_and_editors_cannot_manage_it(): void
    {
        $admin = User::factory()->admin()->create();
        $category = Category::factory()->create();
        app(EditorialTaxonomy::class)->delete($admin, $category);
        $this->assertModelMissing($category);
        $editor = User::factory()->editor()->create();
        $this->expectException(AuthorizationException::class);
        app(EditorialTaxonomy::class)->tag($editor, ['name' => 'Forbidden', 'slug' => 'forbidden']);
    }

    public function test_user_management_requires_admin_and_cannot_remove_own_access_or_mass_assign_roles(): void
    {
        $admin = User::factory()->admin()->create();
        $editor = app(EditorialUsers::class)->save($admin, ['name' => 'Human Editor', 'email' => 'EDITOR@example.test', 'password' => 'VeryStrong!2026', 'role' => 'editor']);
        $this->assertTrue($editor->is_editor);
        $this->assertFalse($editor->is_admin);
        $this->assertSame('editor@example.test', $editor->email);
        $this->assertTrue(Hash::check('VeryStrong!2026', $editor->password));
        $editor->fill(['is_admin' => true, 'is_editor' => false])->save();
        $this->assertFalse($editor->fresh()->is_admin);
        $this->assertTrue($editor->fresh()->is_editor);
        try {
            app(EditorialUsers::class)->save($admin, ['name' => $admin->name, 'email' => $admin->email, 'role' => 'reader'], $admin);
            $this->fail('Own admin access must remain.');
        } catch (ValidationException) {
            $this->assertTrue($admin->fresh()->is_admin);
        }
        $this->expectException(AuthorizationException::class);
        app(EditorialUsers::class)->save($editor, ['name' => 'Escalated', 'email' => $editor->email, 'role' => 'administrator'], $editor);
    }
}
