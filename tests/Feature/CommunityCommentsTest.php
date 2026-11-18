<?php

namespace Tests\Feature;

use App\ArticleStatus;
use App\CommentStatus;
use App\Livewire\ArticleComments;
use App\Models\Article;
use App\Models\ArticleTranslation;
use App\Models\Comment;
use App\Models\User;
use App\Support\CommunityComments;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class CommunityCommentsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
    }

    private function article(string $locale = 'en'): Article
    {
        return Article::factory()->published()->has(ArticleTranslation::factory()->state(['locale' => $locale, 'slug' => 'community-story']), 'translations')->create();
    }

    public static function locales(): array
    {
        return array_map(fn (string $locale): array => [$locale], ['en', 'ru', 'fr', 'de', 'es', 'it', 'ja', 'zh']);
    }

    #[DataProvider('locales')]
    public function test_discussion_and_submission_are_localized_and_new_comments_are_private(string $locale): void
    {
        $article = $this->article($locale);
        $reader = User::factory()->create();
        $this->app->setLocale($locale);
        $this->get("/{$locale}/news/community-story")->assertOk()->assertSee(__('community.discussion'))->assertDontSee($reader->email);
        Livewire::actingAs($reader)->test(ArticleComments::class, ['articleId' => $article->id, 'locale' => $locale])
            ->set('body', 'A considered reader response.')->call('submit')->assertHasNoErrors()->assertSet('body', '')
            ->assertDontSee('A considered reader response.');
        $comment = Comment::sole();
        $this->assertSame($locale, $comment->locale);
        $this->assertSame(CommentStatus::Pending, $comment->status);
        $this->assertSame($reader->id, $comment->user_id);
        Http::assertNothingSent();
    }

    public function test_guests_and_unverified_users_cannot_submit_but_guests_can_read_approved_comments(): void
    {
        $comment = Comment::factory()->approved()->create(['body' => 'Approved public response']);
        Livewire::test(ArticleComments::class, ['articleId' => $comment->article_id, 'locale' => 'en'])
            ->assertSee($comment->body)->set('body', 'Guest submission')->call('submit')->assertForbidden();
        Livewire::actingAs(User::factory()->unverified()->create())->test(ArticleComments::class, ['articleId' => $comment->article_id, 'locale' => 'en'])
            ->set('body', 'Unverified submission')->call('submit')->assertForbidden();
        $this->assertDatabaseCount('comments', 1);
    }

    public static function privateStatuses(): array
    {
        return [[ArticleStatus::Draft], [ArticleStatus::PendingReview], [ArticleStatus::Scheduled], [ArticleStatus::Archived]];
    }

    #[DataProvider('privateStatuses')]
    public function test_private_article_statuses_have_no_comment_surface(ArticleStatus $status): void
    {
        $article = $this->article();
        $article->update(['status' => $status]);
        $this->get('/en/news/community-story')->assertNotFound();
        Livewire::test(ArticleComments::class, ['articleId' => $article->id, 'locale' => 'en'])->assertNotFound();
    }

    public function test_missing_future_deleted_and_archived_locale_discussions_are_inaccessible(): void
    {
        $article = $this->article();
        Livewire::test(ArticleComments::class, ['articleId' => $article->id, 'locale' => 'fr'])->assertNotFound();
        Livewire::test(ArticleComments::class, ['articleId' => $article->id, 'locale' => 'hy'])->assertNotFound();
        $article->update(['published_at' => now()->addDay()]);
        Livewire::test(ArticleComments::class, ['articleId' => $article->id, 'locale' => 'en'])->assertNotFound();
        $article->delete();
        Livewire::test(ArticleComments::class, ['articleId' => $article->id, 'locale' => 'en'])->assertNotFound();
    }

    public function test_replies_require_an_approved_same_article_same_locale_top_level_parent(): void
    {
        $reader = User::factory()->create();
        $parent = Comment::factory()->approved()->create();
        $service = app(CommunityComments::class);
        $reply = $service->create($reader, $parent->article_id, 'en', ['body' => 'A direct reply'], $parent->id);
        $this->assertSame($parent->id, $reply->parent_id);
        $this->assertSame(CommentStatus::Pending, $reply->status);
        $reply->status = CommentStatus::Approved;
        $reply->save();
        Livewire::actingAs($reader)->test(ArticleComments::class, ['articleId' => $parent->article_id, 'locale' => 'en'])
            ->set('replyTo', $reply->id)->set('body', 'Too deeply nested')->call('submit')->assertNotFound();
        $this->assertDatabaseCount('comments', 2);
    }

    public function test_foreign_article_parent_is_rejected(): void
    {
        $parent = Comment::factory()->approved()->create();
        $article = $this->article();
        Livewire::actingAs(User::factory()->create())->test(ArticleComments::class, ['articleId' => $article->id, 'locale' => 'en'])
            ->set('replyTo', $parent->id)->set('body', 'Cross-article reply')->call('submit')->assertNotFound();
        $this->assertDatabaseCount('comments', 1);
    }

    public function test_comments_do_not_cross_language_boundaries(): void
    {
        $article = $this->article();
        ArticleTranslation::factory()->for($article)->create(['locale' => 'fr']);
        $english = Comment::factory()->approved()->for($article)->create(['body' => 'English only']);
        Comment::factory()->approved()->for($article)->create(['locale' => 'fr', 'body' => 'Français uniquement']);
        Livewire::test(ArticleComments::class, ['articleId' => $article->id, 'locale' => 'fr'])->assertSee('Français uniquement')->assertDontSee('English only');
        Livewire::actingAs(User::factory()->create())->test(ArticleComments::class, ['articleId' => $article->id, 'locale' => 'fr'])
            ->set('replyTo', $english->id)->set('body', 'Wrong language')->call('submit')->assertNotFound();
    }

    public function test_owner_edits_return_to_moderation_and_others_cannot_edit_or_remove(): void
    {
        $reader = User::factory()->create();
        $comment = Comment::factory()->approved()->for($reader)->create();
        Livewire::actingAs(User::factory()->create())->test(ArticleComments::class, ['articleId' => $comment->article_id, 'locale' => 'en'])
            ->call('edit', $comment->id)->assertForbidden();
        Livewire::actingAs($reader)->test(ArticleComments::class, ['articleId' => $comment->article_id, 'locale' => 'en'])
            ->call('edit', $comment->id)->set('body', 'Edited safely')->call('submit')->assertHasNoErrors();
        $this->assertSame(CommentStatus::Pending, $comment->fresh()->status);
        $this->assertNotNull($comment->fresh()->edited_at);
        Livewire::actingAs(User::factory()->create())->test(ArticleComments::class, ['articleId' => $comment->article_id, 'locale' => 'en'])
            ->call('remove', $comment->id)->assertForbidden();
    }

    public function test_expired_edits_are_denied_and_deleted_parents_keep_approved_replies(): void
    {
        $reader = User::factory()->create();
        $parent = Comment::factory()->approved()->for($reader)->create(['body' => 'Removed secret parent', 'created_at' => now()->subHour()]);
        Comment::factory()->approved()->for($parent->article)->create(['parent_id' => $parent->id, 'body' => 'Surviving reply']);
        Livewire::actingAs($reader)->test(ArticleComments::class, ['articleId' => $parent->article_id, 'locale' => 'en'])->call('edit', $parent->id)->assertForbidden();
        Livewire::actingAs($reader)->test(ArticleComments::class, ['articleId' => $parent->article_id, 'locale' => 'en'])
            ->call('remove', $parent->id)->assertHasNoErrors()->assertSee('Surviving reply')->assertDontSee('Removed secret parent')->assertSee(__('community.removed_parent'));
        $this->assertSoftDeleted($parent);
        $this->assertDatabaseCount('comments', 2);
    }

    public function test_markup_honeypot_and_oversized_comments_are_rejected(): void
    {
        $article = $this->article();
        $reader = User::factory()->create();
        $component = Livewire::actingAs($reader)->test(ArticleComments::class, ['articleId' => $article->id, 'locale' => 'en']);
        $component->set('body', '<script>alert(1)</script>')->call('submit')->assertHasErrors('body');
        $component->set('body', str_repeat('x', 2001))->call('submit')->assertHasErrors('body');
        $component->set('body', 'Ordinary text')->set('website', 'bot.test')->call('submit')->assertHasErrors('website');
        $this->assertDatabaseCount('comments', 0);
    }

    public function test_duplicate_comments_and_flooding_are_rejected(): void
    {
        $article = $this->article();
        $reader = User::factory()->create();
        $component = Livewire::actingAs($reader)->test(ArticleComments::class, ['articleId' => $article->id, 'locale' => 'en']);
        $component->set('body', 'Same text')->call('submit')->assertHasNoErrors();
        $component->set('body', ' Same  text ')->call('submit')->assertHasErrors('body');
        $component->set('body', 'Different text')->call('submit')->assertHasNoErrors();
        $component->set('body', 'Flooded text')->call('submit')->assertHasErrors('community');
        $this->assertDatabaseCount('comments', 2);
    }

    public function test_likes_toggle_without_duplicates_and_pending_comments_cannot_be_liked(): void
    {
        $comment = Comment::factory()->approved()->create();
        $reader = User::factory()->unverified()->create();
        $component = Livewire::actingAs($reader)->test(ArticleComments::class, ['articleId' => $comment->article_id, 'locale' => 'en']);
        $component->call('like', $comment->id)->assertHasNoErrors();
        $this->assertDatabaseCount('comment_likes', 1);
        $component->call('like', $comment->id)->assertHasNoErrors();
        $this->assertDatabaseCount('comment_likes', 0);
        $component->call('like', $comment->id)->assertHasNoErrors();
        $this->assertSame(1, $comment->likes()->count());
        $comment->status = CommentStatus::Pending;
        $comment->save();
        $component->call('like', $comment->id)->assertNotFound();
    }

    public function test_guests_cannot_like_or_report_and_suspension_blocks_community_mutations(): void
    {
        $comment = Comment::factory()->approved()->create();
        Livewire::test(ArticleComments::class, ['articleId' => $comment->article_id, 'locale' => 'en'])->call('like', $comment->id)->assertForbidden();
        $reader = User::factory()->create(['community_suspended_at' => now()]);
        Livewire::actingAs($reader)->test(ArticleComments::class, ['articleId' => $comment->article_id, 'locale' => 'en'])->call('like', $comment->id)->assertForbidden();
        Livewire::actingAs($reader)->test(ArticleComments::class, ['articleId' => $comment->article_id, 'locale' => 'en'])->set('body', 'Blocked')->call('submit')->assertForbidden();
    }

    public function test_pagination_sorting_and_reply_pagination_are_bounded_and_have_no_duplicate_rendering(): void
    {
        $article = $this->article();
        $old = Comment::factory()->approved()->for($article)->create(['body' => 'Oldest sentinel']);
        $popular = Comment::factory()->approved()->for($article)->create(['body' => 'Popular sentinel']);
        $popular->likes()->create(['user_id' => User::factory()->create()->id]);
        Comment::factory()->count(10)->approved()->for($article)->create();
        Comment::factory()->count(15)->approved()->for($article)->create(['parent_id' => $popular->id]);
        $component = Livewire::test(ArticleComments::class, ['articleId' => $article->id, 'locale' => 'en']);
        $component->assertDontSee('Oldest sentinel')->call('nextPage', 'commentsPage')->assertSee('Oldest sentinel');
        $component->set('sort', 'oldest')->assertSeeInOrder(['Oldest sentinel', 'Popular sentinel']);
        $component->set('sort', 'liked')->assertSee('Popular sentinel')->call('expand', $popular->id)->assertHasNoErrors();
        $html = $component->html();
        $this->assertSame(1, substr_count($html, 'Popular sentinel'));
        $this->assertSame(10, substr_count($html, 'class="community-reply"'));
        $component->call('nextPage', 'repliesPage')->assertHasNoErrors();
        $this->assertSame(5, substr_count($component->html(), 'class="community-reply"'));
    }

    public function test_render_rechecks_publication_after_mount_and_never_exposes_discussions_in_preview(): void
    {
        $article = $this->article();
        $component = Livewire::test(ArticleComments::class, ['articleId' => $article->id, 'locale' => 'en']);
        $article->update(['status' => ArticleStatus::Draft]);
        $component->call('$refresh')->assertNotFound();
        $this->actingAs(User::factory()->admin()->create())->get('/editorial/preview/'.$article->id.'/en')
            ->assertOk()->assertDontSee('id="comments"', false)->assertDontSee('wire:snapshot', false);
    }

    public function test_comment_query_count_does_not_grow_with_thread_count(): void
    {
        $article = $this->article();
        Comment::factory()->approved()->for($article)->create();
        DB::enableQueryLog();
        Livewire::test(ArticleComments::class, ['articleId' => $article->id, 'locale' => 'en']);
        $small = count(DB::getQueryLog());
        DB::disableQueryLog();
        Comment::factory()->count(8)->approved()->for($article)->create();
        DB::flushQueryLog();
        DB::enableQueryLog();
        Livewire::test(ArticleComments::class, ['articleId' => $article->id, 'locale' => 'en']);
        $large = count(DB::getQueryLog());
        DB::disableQueryLog();
        $this->assertLessThanOrEqual($small + 1, $large);
    }

    public function test_guest_livewire_updates_are_private_and_not_stored_in_shared_caches(): void
    {
        $article = $this->article();
        $component = Livewire::test(ArticleComments::class, ['articleId' => $article->id, 'locale' => 'en']);
        $response = $this->postJson(app('livewire')->getUpdateUri(), [
            'components' => [[
                'snapshot' => json_encode($component->snapshot, JSON_THROW_ON_ERROR),
                'updates' => [], 'calls' => [['path' => '', 'method' => '$refresh', 'params' => []]],
            ]],
        ], ['X-Livewire' => 'true'])->assertOk();
        $this->assertTrue($response->headers->hasCacheControlDirective('private'));
        $this->assertTrue($response->headers->hasCacheControlDirective('no-store'));
    }

    public function test_community_catalogs_have_matching_keys_and_placeholders(): void
    {
        $english = require lang_path('en/community.php');
        foreach (array_keys(config('news.locales')) as $locale) {
            $catalog = require lang_path($locale.'/community.php');
            $this->assertSame(array_keys($english), array_keys($catalog));
            foreach ($english as $key => $value) {
                $this->assertTrue(mb_check_encoding($catalog[$key], 'UTF-8'));
                preg_match_all('/:[a-z_]+/', $value, $expected);
                preg_match_all('/:[a-z_]+/', $catalog[$key], $actual);
                sort($expected[0]);
                sort($actual[0]);
                $this->assertSame($expected[0], $actual[0], $locale.'.'.$key);
            }
        }
    }
}
