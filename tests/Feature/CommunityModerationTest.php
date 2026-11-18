<?php

namespace Tests\Feature;

use App\CommentStatus;
use App\Filament\Resources\CommentReports\Pages\ManageCommentReports;
use App\Filament\Resources\Comments\Pages\ManageComments;
use App\Filament\Resources\Users\Pages\ListUsers;
use App\Livewire\ArticleComments;
use App\Models\Comment;
use App\Models\CommentLike;
use App\Models\CommentModerationEvent;
use App\Models\CommentReport;
use App\Models\User;
use App\Notifications\CommentReplyApproved;
use App\Support\CommunityComments;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use Tests\TestCase;

class CommunityModerationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    public function test_only_administrators_have_moderation_resource_access(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin)->get('/admin/comments')->assertOk();
        $this->get('/admin/comment-reports')->assertOk();
        foreach ([User::factory()->editor()->create(), User::factory()->create()] as $user) {
            $this->actingAs($user)->get('/admin/comments')->assertForbidden();
            $this->get('/admin/comment-reports')->assertForbidden();
        }
    }

    public function test_native_moderation_actions_are_audited_and_approval_is_idempotent(): void
    {
        $admin = User::factory()->admin()->create();
        $comment = Comment::factory()->create(['body' => 'Pending moderation sentinel']);
        $this->actingAs($admin);
        Livewire::test(ManageComments::class)->assertCanSeeTableRecords([$comment])->callTableAction('approve', $comment)->assertHasNoErrors();
        $this->assertSame(CommentStatus::Approved, $comment->fresh()->status);
        $events = CommentModerationEvent::count();
        app(CommunityComments::class)->moderate($admin, $comment->id, 'approve');
        $this->assertSame($events, CommentModerationEvent::count());
        Livewire::test(ManageComments::class)->callTableAction('reject', $comment)->assertHasNoErrors();
        $this->assertSame(CommentStatus::Rejected, $comment->fresh()->status);
        Livewire::test(ManageComments::class)->callTableAction('spam', $comment)->assertHasNoErrors();
        $this->assertSame(CommentStatus::Spam, $comment->fresh()->status);
        Livewire::test(ArticleComments::class, ['articleId' => $comment->article_id, 'locale' => 'en'])->assertDontSee($comment->body);
        $this->assertDatabaseHas('comment_moderation_events', ['comment_id' => $comment->id, 'user_id' => $admin->id, 'action' => 'spam']);
    }

    public function test_native_bulk_moderation_and_safe_restore_preserve_likes_and_reports(): void
    {
        $admin = User::factory()->admin()->create();
        $comments = Comment::factory()->count(2)->create();
        $this->actingAs($admin);
        Livewire::test(ManageComments::class)->callTableBulkAction('approve_selected', $comments)->assertHasNoErrors();
        $this->assertSame(2, Comment::approved()->count());
        $comment = $comments->first();
        CommentLike::factory()->for($comment)->create();
        CommentReport::factory()->for($comment)->create();
        Livewire::test(ManageComments::class)->callTableAction('remove', $comment)->assertHasNoErrors();
        $this->assertSoftDeleted($comment);
        $this->assertDatabaseCount('comment_likes', 1);
        $this->assertDatabaseCount('comment_reports', 1);
        Livewire::test(ManageComments::class)->filterTable('trashed', true)->callTableAction('restore', $comment)->assertHasNoErrors();
        $this->assertFalse($comment->fresh()->trashed());
        $this->assertSame(CommentStatus::Pending, $comment->fresh()->status);
    }

    public function test_reports_are_unique_active_reopen_after_resolution_and_never_remove_content_automatically(): void
    {
        $reader = User::factory()->create();
        $comment = Comment::factory()->approved()->create();
        $component = Livewire::actingAs($reader)->test(ArticleComments::class, ['articleId' => $comment->article_id, 'locale' => 'en']);
        $component->call('showReport', $comment->id)->set('reportReason', 'scam')->call('report')->assertHasNoErrors();
        $this->assertSame(CommentStatus::Approved, $comment->fresh()->status);
        $component->call('showReport', $comment->id)->call('report')->assertHasErrors('community');
        $this->assertDatabaseCount('comment_reports', 1);
        $report = CommentReport::sole();
        app(CommunityComments::class)->resolveReport(User::factory()->admin()->create(), $report->id);
        $component->call('report')->assertHasNoErrors();
        $this->assertNull($report->fresh()->resolved_at);
        $this->assertDatabaseCount('comment_reports', 1);
    }

    public function test_report_resource_exposes_reasons_and_resolves_reports_through_authorized_actions(): void
    {
        $admin = User::factory()->admin()->create();
        $report = CommentReport::factory()->create();
        $this->actingAs($admin);
        Livewire::test(ManageCommentReports::class)->assertCanSeeTableRecords([$report])->callTableAction('view', $report)->assertHasNoErrors();
        Livewire::test(ManageCommentReports::class)->callTableAction('resolve', $report)->assertHasNoErrors();
        $this->assertNotNull($report->fresh()->resolved_at);
        $this->assertSame($admin->id, $report->fresh()->resolved_by);
        $second = CommentReport::factory()->create();
        Livewire::test(ManageCommentReports::class)->callTableAction('mark_spam', $second)->assertHasNoErrors();
        $this->assertSame(CommentStatus::Spam, $second->comment->fresh()->status);
        $this->assertNotNull($second->fresh()->resolved_at);
    }

    public function test_editor_service_calls_cannot_moderate_or_resolve_reports(): void
    {
        $editor = User::factory()->editor()->create();
        $comment = Comment::factory()->approved()->create();
        $this->expectException(AuthorizationException::class);
        app(CommunityComments::class)->moderate($editor, $comment->id, 'spam');
    }

    public function test_non_administrator_cannot_resolve_reports(): void
    {
        $report = CommentReport::factory()->create();
        $this->expectException(AuthorizationException::class);
        app(CommunityComments::class)->resolveReport(User::factory()->editor()->create(), $report->id);
    }

    public function test_invalid_report_reason_is_rejected(): void
    {
        $comment = Comment::factory()->approved()->create();
        Livewire::actingAs(User::factory()->create())->test(ArticleComments::class, ['articleId' => $comment->article_id, 'locale' => 'en'])
            ->call('showReport', $comment->id)->set('reportReason', 'delete-everything')->call('report')->assertHasErrors('reason');
        $this->assertDatabaseCount('comment_reports', 0);
    }

    public function test_user_suspension_is_explicit_administrator_only_and_does_not_change_editorial_roles(): void
    {
        $admin = User::factory()->admin()->create();
        $reader = User::factory()->create();
        $this->actingAs($admin);
        Livewire::test(ListUsers::class)->callTableAction('suspend_community', $reader)->assertHasNoErrors();
        $this->assertNotNull($reader->fresh()->community_suspended_at);
        $this->assertFalse($reader->fresh()->is_admin);
        $this->assertDatabaseHas('comment_moderation_events', ['subject_user_id' => $reader->id, 'action' => 'account_suspended']);
        Livewire::test(ListUsers::class)->callTableAction('restore_community', $reader)->assertHasNoErrors();
        $this->assertNull($reader->fresh()->community_suspended_at);
        $this->expectException(AuthorizationException::class);
        app(CommunityComments::class)->suspend(User::factory()->editor()->create(), $reader, true);
    }

    public function test_reply_notifications_are_opt_in_only_after_approval_and_not_duplicated(): void
    {
        Notification::fake();
        $recipient = User::factory()->create(['reply_notifications' => true]);
        $parent = Comment::factory()->approved()->for($recipient)->create();
        $reply = Comment::factory()->for($parent->article)->create(['parent_id' => $parent->id]);
        $admin = User::factory()->admin()->create();
        Notification::assertNothingSent();
        app(CommunityComments::class)->moderate($admin, $reply->id, 'approve');
        Notification::assertSentToTimes($recipient, CommentReplyApproved::class, 1);
        app(CommunityComments::class)->moderate($admin, $reply->id, 'reject');
        app(CommunityComments::class)->moderate($admin, $reply->id, 'approve');
        Notification::assertSentToTimes($recipient, CommentReplyApproved::class, 1);
        $this->assertNotNull($reply->fresh()->reply_notified_at);
        $notification = new CommentReplyApproved($reply->id);
        $this->assertTrue($notification->shouldSend($recipient, 'mail'));
        $recipient->reply_notifications = false;
        $this->assertFalse($notification->shouldSend($recipient, 'mail'));
    }

    public function test_replies_do_not_notify_unverified_opted_out_or_removed_parent_authors(): void
    {
        Notification::fake();
        $admin = User::factory()->admin()->create();
        foreach ([
            User::factory()->create(['reply_notifications' => false]),
            User::factory()->unverified()->create(['reply_notifications' => true]),
        ] as $recipient) {
            $parent = Comment::factory()->approved()->for($recipient)->create();
            $reply = Comment::factory()->for($parent->article)->create(['parent_id' => $parent->id]);
            app(CommunityComments::class)->moderate($admin, $reply->id, 'approve');
        }
        Notification::assertNothingSent();
    }

    public function test_comment_moderation_view_escapes_legacy_markup_and_eager_loads_authors(): void
    {
        $comment = Comment::factory()->create(['body' => '<script>unsafeLegacy()</script>']);
        $this->actingAs(User::factory()->admin()->create());
        Livewire::test(ManageComments::class)->callTableAction('view', $comment)->assertDontSeeHtml('<script>unsafeLegacy()</script>');
        $article = $comment->article;
        DB::enableQueryLog();
        Livewire::test(ManageComments::class);
        $small = count(DB::getQueryLog());
        DB::disableQueryLog();
        Comment::factory()->count(8)->for($article)->create();
        DB::flushQueryLog();
        DB::enableQueryLog();
        Livewire::test(ManageComments::class);
        $large = count(DB::getQueryLog());
        DB::disableQueryLog();
        $this->assertLessThanOrEqual($small + 1, $large);
    }

    public function test_livewire_identifiers_are_locked_against_client_tampering(): void
    {
        $comment = Comment::factory()->approved()->create();
        $this->expectException(CannotUpdateLockedPropertyException::class);
        Livewire::test(ArticleComments::class, ['articleId' => $comment->article_id, 'locale' => 'en'])->set('articleId', 9999);
    }
}
