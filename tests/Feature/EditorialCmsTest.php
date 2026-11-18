<?php

namespace Tests\Feature;

use App\ArticleStatus;
use App\Filament\Pages\EditorialSettings;
use App\Filament\Resources\Articles\ArticleResource;
use App\Filament\Resources\Articles\Pages\CreateArticle;
use App\Filament\Resources\Articles\Pages\EditArticle;
use App\Filament\Resources\Articles\Pages\ListArticles;
use App\Filament\Resources\Categories\Pages\CreateCategory;
use App\Filament\Resources\Tags\Pages\ManageTags;
use App\Filament\Resources\Users\Pages\CreateUser;
use App\Filament\Widgets\EditorialStats;
use App\Filament\Widgets\LatestPublishedArticles;
use App\Filament\Widgets\RecentEditorialActivity;
use App\Models\Article;
use App\Models\ArticleTranslation;
use App\Models\Category;
use App\Models\EditorialEvent;
use App\Models\EditorialSetting;
use App\Models\Tag;
use App\Models\User;
use App\Support\ArticleEditor;
use App\Support\ArticlePublication;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class EditorialCmsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    private function data(User $author, string $slug = 'cms-story'): array
    {
        return ['author_id' => $author->id, 'tag_ids' => [], 'translations' => ['en' => [
            'enabled' => true, 'title' => 'CMS headline', 'slug' => $slug, 'body' => '<p>A complete saved draft.</p>',
        ]]];
    }

    public function test_administrators_can_open_every_implemented_cms_page(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin);
        foreach (['/admin', '/admin/articles', '/admin/articles/create', '/admin/categories', '/admin/categories/create', '/admin/tags', '/admin/users', '/admin/users/create', '/admin/editorial-settings'] as $path) {
            $this->get($path)->assertOk();
        }
        $article = app(ArticleEditor::class)->save($admin, $this->data($admin));
        $this->get(ArticleResource::getUrl('edit', ['record' => $article]))->assertOk();
    }

    public function test_editor_panel_access_is_scoped_and_protected_resources_are_forbidden(): void
    {
        $editor = User::factory()->editor()->create();
        $other = User::factory()->editor()->create();
        $own = app(ArticleEditor::class)->save($editor, $this->data($editor, 'own-draft'));
        $private = app(ArticleEditor::class)->save($other, $this->data($other, 'other-draft'));
        $this->actingAs($editor);
        $this->get('/admin')->assertOk();
        $this->get('/admin/articles/create')->assertOk();
        Livewire::test(ListArticles::class)->assertCanSeeTableRecords([$own])->assertCanNotSeeTableRecords([$private]);
        $this->get(ArticleResource::getUrl('edit', ['record' => $private]))->assertNotFound();
        foreach (['/admin/categories', '/admin/tags', '/admin/users', '/admin/editorial-settings'] as $path) {
            $this->get($path)->assertForbidden();
        }
    }

    public function test_native_article_form_creates_edits_and_submits_a_partial_translation_draft(): void
    {
        $editor = User::factory()->editor()->create();
        $this->actingAs($editor);
        Livewire::test(CreateArticle::class)->fillForm($this->data($editor))->call('create')->assertHasNoFormErrors();
        $article = Article::sole();
        $this->assertSame(ArticleStatus::Draft, $article->status);
        $this->assertDatabaseCount('article_translations', 1);
        Livewire::test(EditArticle::class, ['record' => $article->id])
            ->fillForm(['translations.en.title' => 'Revised CMS headline'])
            ->call('save')->assertHasNoFormErrors();
        $this->assertSame('Revised CMS headline', $article->translations()->where('locale', 'en')->sole()->title);
        Livewire::test(EditArticle::class, ['record' => $article->id])->callAction('submit_review')->assertHasNoFormErrors();
        $this->assertSame(ArticleStatus::PendingReview, $article->fresh()->status);
    }

    public function test_admin_edit_page_can_approve_and_publish_after_saving_current_form(): void
    {
        $admin = User::factory()->admin()->create();
        $article = app(ArticleEditor::class)->save($admin, $this->data($admin));
        $article->update(['status' => ArticleStatus::PendingReview]);
        $this->actingAs($admin);
        Livewire::test(EditArticle::class, ['record' => $article->id])->callAction('approve')->assertHasNoFormErrors();
        $this->assertNotNull($article->fresh()->approved_at);
        Livewire::test(EditArticle::class, ['record' => $article->id])->callAction('publish')->assertHasNoFormErrors();
        $this->assertSame(ArticleStatus::Published, $article->fresh()->status);
        $this->get('/en/news/cms-story')->assertOk();
    }

    public function test_legacy_publication_without_a_date_remains_private_and_can_be_repaired_by_admin(): void
    {
        $admin = User::factory()->admin()->create();
        $article = app(ArticleEditor::class)->save($admin, $this->data($admin));
        $article->update(['status' => ArticleStatus::Published, 'published_at' => null]);
        $this->actingAs($admin)->get('/admin/articles')->assertOk();
        $this->get('/en/news/cms-story')->assertNotFound();
        Livewire::test(EditArticle::class, ['record' => $article->id])->callAction('publish')->assertHasNoFormErrors();
        $this->assertNotNull($article->fresh()->published_at);
        $this->get('/en/news/cms-story')->assertOk();
    }

    public function test_native_forms_show_server_side_unique_translation_errors(): void
    {
        $admin = User::factory()->admin()->create();
        app(ArticleEditor::class)->save($admin, $this->data($admin));
        $this->actingAs($admin);
        Livewire::test(CreateArticle::class)->fillForm($this->data($admin))->call('create')
            ->assertHasFormErrors(['translations.en.slug']);
        $this->assertDatabaseCount('articles', 1);
    }

    public function test_editor_cannot_use_publish_or_bulk_trash_controls(): void
    {
        $editor = User::factory()->editor()->create();
        $article = app(ArticleEditor::class)->save($editor, $this->data($editor));
        $this->actingAs($editor);
        Livewire::test(EditArticle::class, ['record' => $article->id])->assertActionHidden('publish')->assertActionHidden('approve')->assertActionHidden('schedule');
        Livewire::test(ListArticles::class)->assertTableBulkActionHidden('trash_selected');
        $this->assertSame(ArticleStatus::Draft, $article->fresh()->status);
    }

    public function test_admin_bulk_trash_preserves_translation_rows(): void
    {
        $admin = User::factory()->admin()->create();
        $article = app(ArticleEditor::class)->save($admin, $this->data($admin));
        $this->actingAs($admin);
        Livewire::test(ListArticles::class)->callTableBulkAction('trash_selected', [$article])->assertHasNoErrors();
        $this->assertSoftDeleted($article);
        $this->assertDatabaseCount('article_translations', 1);
    }

    public function test_native_restore_action_returns_a_trashed_article_as_private_draft(): void
    {
        $admin = User::factory()->admin()->create();
        $article = app(ArticleEditor::class)->save($admin, $this->data($admin));
        app(ArticlePublication::class)->trash($admin, $article);
        $this->actingAs($admin);
        Livewire::test(ListArticles::class)->filterTable('trashed', true)
            ->callTableAction('restore', $article)->assertHasNoErrors();
        $this->assertFalse($article->fresh()->trashed());
        $this->assertSame(ArticleStatus::Draft, $article->fresh()->status);
        $this->get('/en/news/cms-story')->assertNotFound();
    }

    public function test_tag_permissions_use_count_subqueries_instead_of_one_query_per_row(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin);
        Tag::factory()->create();
        DB::enableQueryLog();
        Livewire::test(ManageTags::class);
        $first = count(DB::getQueryLog());
        Tag::factory()->count(10)->create();
        DB::flushQueryLog();
        Livewire::test(ManageTags::class);
        $second = count(DB::getQueryLog());
        DB::disableQueryLog();
        $this->assertLessThanOrEqual($first + 3, $second);
    }

    public function test_native_category_tag_and_user_forms_use_authorized_services(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin);
        Livewire::test(CreateCategory::class)->fillForm(['sort_order' => 1, 'translations' => [
            'en' => ['enabled' => true, 'name' => 'New human category', 'slug' => 'new-human-category'],
        ]])->call('create')->assertHasNoFormErrors();
        $this->assertDatabaseHas('category_translations', ['slug' => 'new-human-category']);
        Livewire::test(ManageTags::class)->callAction('create', data: ['name' => 'News desk', 'slug' => 'news-desk'])->assertHasNoFormErrors();
        $this->assertDatabaseHas('tags', ['slug' => 'news-desk']);
        Livewire::test(CreateUser::class)->fillForm(['name' => 'New editor', 'email' => 'human@example.test', 'role' => 'editor', 'password' => 'SecureHuman!2026'])
            ->call('create')->assertHasNoFormErrors();
        $this->assertTrue(User::where('email', 'human@example.test')->sole()->is_editor);
    }

    public function test_settings_are_admin_only_and_defaults_do_not_override_editor_authorship(): void
    {
        $admin = User::factory()->admin()->create();
        $category = Category::factory()->create();
        $this->actingAs($admin);
        Livewire::test(EditorialSettings::class)->fillForm(['default_author_id' => $admin->id, 'default_category_id' => $category->id])
            ->call('save')->assertHasNoFormErrors();
        $this->assertSame($admin->id, EditorialSetting::findOrFail(1)->default_author_id);
        $editor = User::factory()->editor()->create();
        $this->actingAs($editor);
        Livewire::test(CreateArticle::class)->assertSchemaStateSet(['author_id' => $editor->id]);
        Livewire::test(EditorialSettings::class)->assertForbidden();
    }

    public function test_editorial_defaults_always_use_the_fixed_singleton_key(): void
    {
        $admin = User::factory()->admin()->create();
        EditorialSetting::factory()->create(['id' => 4]);
        $this->actingAs($admin);
        Livewire::test(EditorialSettings::class)->fillForm(['default_author_id' => $admin->id])->call('save')->assertHasNoFormErrors();
        Livewire::test(EditorialSettings::class)->fillForm(['default_author_id' => $admin->id])->call('save')->assertHasNoFormErrors();
        $this->assertSame($admin->id, EditorialSetting::findOrFail(1)->default_author_id);
        $this->assertDatabaseCount('editorial_settings', 2);
    }

    public function test_private_previews_require_authentication_authorization_and_a_real_public_locale(): void
    {
        $editor = User::factory()->editor()->create();
        $article = app(ArticleEditor::class)->save($editor, $this->data($editor));
        $url = route('editorial.preview', ['article' => $article, 'locale' => 'en']);
        $this->get($url)->assertRedirect('/admin/login');
        $this->actingAs(User::factory()->create())->get($url)->assertForbidden();
        $this->actingAs(User::factory()->editor()->create())->get($url)->assertForbidden();
        $response = $this->actingAs($editor)->get($url)->assertOk()->assertSee('CMS headline')
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow, noarchive')->assertDontSee('rel="canonical"', false);
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->get(route('editorial.preview', ['article' => $article, 'locale' => 'ru']))->assertNotFound();
        $this->get('/editorial/preview/'.$article->id.'/hy')->assertNotFound();
        $this->get('/sitemap-articles-1.xml')->assertOk()->assertDontSee('cms-story');
        $this->get('/en/news/cms-story')->assertNotFound();
    }

    public function test_native_featured_image_upload_is_validated_and_saved_through_the_media_service(): void
    {
        Storage::fake('public');
        $editor = User::factory()->editor()->create();
        $this->actingAs($editor);
        $data = $this->data($editor);
        $data['featured_image_upload'] = UploadedFile::fake()->image('human-photo.jpg', 900, 600);
        Livewire::test(CreateArticle::class)->fillForm($data)->call('create')->assertHasNoFormErrors();
        $article = Article::sole();
        $this->assertMatchesRegularExpression('#^articles/featured/[a-f0-9-]{36}\.jpg$#', $article->featured_image);
        Storage::disk('public')->assertExists($article->featured_image);
    }

    public function test_native_editor_save_keeps_archived_armenian_and_legacy_media_and_tags(): void
    {
        $editor = User::factory()->editor()->create();
        $article = app(ArticleEditor::class)->save($editor, $this->data($editor));
        $article->update(['featured_image' => 'editorial/bitcoin.svg']);
        $archive = ArticleTranslation::factory()->for($article)->create(['locale' => 'hy', 'slug' => 'արխիվ', 'body' => 'Պահպանված բնօրինակ։']);
        $original = $archive->fresh()->getRawOriginal();
        $tag = Tag::factory()->create();
        $article->tags()->attach($tag);
        $this->actingAs($editor);
        Livewire::test(EditArticle::class, ['record' => $article->id])->fillForm(['translations.en.title' => 'Human edited headline'])->call('save')->assertHasNoFormErrors();
        $this->assertSame($original, $archive->fresh()->getRawOriginal());
        $this->assertSame('editorial/bitcoin.svg', $article->fresh()->featured_image);
        $this->assertSame([$tag->id], $article->fresh()->tags->modelKeys());
        $this->assertDatabaseCount('article_translations', 2);
    }

    #[DataProvider('publicLocales')]
    public function test_private_previews_support_all_eight_real_translations(string $locale): void
    {
        $editor = User::factory()->editor()->create();
        $data = ['author_id' => $editor->id, 'translations' => [$locale => [
            'enabled' => true, 'title' => 'Actual '.$locale.' headline', 'slug' => 'preview-'.$locale, 'body' => '<p>Actual stored '.$locale.' body.</p>',
        ]]];
        $article = app(ArticleEditor::class)->save($editor, $data);
        $this->actingAs($editor)->get(route('editorial.preview', ['article' => $article, 'locale' => $locale]))
            ->assertOk()->assertSee('Actual '.$locale.' headline')
            ->assertSee('<html lang="'.config('news.language_tags.'.$locale).'">', false)
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow, noarchive');
    }

    public static function publicLocales(): array
    {
        return [['en'], ['ru'], ['fr'], ['de'], ['es'], ['it'], ['ja'], ['zh']];
    }

    public function test_widgets_show_real_counts_activity_and_published_content_without_n_plus_one_queries(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin);
        $article = app(ArticleEditor::class)->save($admin, $this->data($admin));
        $other = Article::factory()->published()->has(ArticleTranslation::factory(), 'translations')->create();
        Livewire::test(EditorialStats::class)->assertSee('Total articles')->assertSee('Awaiting review')->assertSee('Scheduled articles');
        Livewire::test(RecentEditorialActivity::class)->assertCanSeeTableRecords(EditorialEvent::all());
        Livewire::test(LatestPublishedArticles::class)->assertCanSeeTableRecords([$other])->assertCanNotSeeTableRecords([$article]);
        DB::enableQueryLog();
        Livewire::test(ListArticles::class)->assertCanSeeTableRecords([$article, $other]);
        $first = count(DB::getQueryLog());
        Article::factory()->count(10)->has(ArticleTranslation::factory()->count(2)->sequence(['locale' => 'en'], ['locale' => 'ru']), 'translations')->create();
        DB::flushQueryLog();
        Livewire::test(ListArticles::class);
        $second = count(DB::getQueryLog());
        DB::disableQueryLog();
        $this->assertLessThanOrEqual($first + 5, $second);
    }
}
