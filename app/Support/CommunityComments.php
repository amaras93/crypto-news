<?php

namespace App\Support;

use App\CommentStatus;
use App\Models\Article;
use App\Models\Comment;
use App\Models\CommentLike;
use App\Models\CommentModerationEvent;
use App\Models\CommentReport;
use App\Models\User;
use App\Notifications\CommentReplyApproved;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class CommunityComments
{
    public function publicArticle(int $articleId, string $locale, bool $lock = false): Article
    {
        abort_unless(array_key_exists($locale, config('news.locales')), 404);
        $article = Article::query()->published()->whereKey($articleId)
            ->withWhereHas('translations', fn (Builder|Relation $query): Builder|Relation => $query->where('locale', $locale)->publicReady())
            ->when($lock, fn (Builder $query): Builder => $query->lockForUpdate())->firstOrFail();
        abort_unless(app(PublicUrl::class)->isPublicTranslation($article->translation($locale)), 404);

        return $article;
    }

    public function isPublic(Comment $comment): bool
    {
        if ($comment->trashed() || $comment->status !== CommentStatus::Approved || ! array_key_exists($comment->locale, config('news.locales'))) {
            return false;
        }
        $article = Article::query()->published()->whereKey($comment->article_id)->with('translations')->first();

        return $article && $article->translation($comment->locale)
            && app(PublicUrl::class)->isPublicTranslation($article->translation($comment->locale));
    }

    /** @param array<string, mixed> $data */
    public function create(User $actor, int $articleId, string $locale, array $data, ?int $parentId = null): Comment
    {
        $actor = $this->actor($actor);
        Gate::forUser($actor)->authorize('create', Comment::class);
        $this->writingLimits($actor);
        $body = $this->body($data);

        return DB::transaction(function () use ($actor, $articleId, $locale, $body, $parentId): Comment {
            $this->publicArticle($articleId, $locale, true);
            Gate::forUser($this->actor($actor))->authorize('create', Comment::class);
            if ($parentId !== null) {
                $parent = Comment::query()->lockForUpdate()->findOrFail($parentId);
                abort_unless($parent->article_id === $articleId && $parent->locale === $locale
                    && $parent->parent_id === null && $parent->status === CommentStatus::Approved, 404);
            }
            $fingerprint = $this->fingerprint($actor->id, $articleId, $locale, $parentId, $body);
            $this->uniqueBody($fingerprint);
            $comment = Comment::query()->create([
                'article_id' => $articleId, 'user_id' => $actor->id, 'locale' => $locale,
                'parent_id' => $parentId, 'body' => $body, 'fingerprint' => $fingerprint,
            ]);
            $comment->status = config('community.require_approval') ? CommentStatus::Pending : CommentStatus::Approved;
            $comment->save();
            $this->event($comment, $actor, 'submitted', null);
            if ($comment->status === CommentStatus::Approved) {
                $this->notifyReply($comment);
            }

            return $comment;
        });
    }

    /** @param array<string, mixed> $data */
    public function edit(User $actor, int $commentId, int $articleId, string $locale, array $data): Comment
    {
        $actor = $this->actor($actor);
        $this->writingLimits($actor);
        $body = $this->body($data);

        return DB::transaction(function () use ($actor, $commentId, $articleId, $locale, $body): Comment {
            $this->publicArticle($articleId, $locale, true);
            $comment = $this->inDiscussion($commentId, $articleId, $locale, true);
            Gate::forUser($this->actor($actor))->authorize('update', $comment);
            $fingerprint = $this->fingerprint($actor->id, $articleId, $locale, $comment->parent_id, $body);
            $this->uniqueBody($fingerprint, $comment->id);
            if ($comment->body === $body) {
                return $comment;
            }
            $previous = $comment->status;
            $comment->body = $body;
            $comment->fingerprint = $fingerprint;
            $comment->status = CommentStatus::Pending;
            $comment->edited_at = now();
            $comment->save();
            $this->event($comment, $actor, 'edited', $previous);

            return $comment;
        });
    }

    public function remove(User $actor, int $commentId, int $articleId, string $locale): void
    {
        DB::transaction(function () use ($actor, $commentId, $articleId, $locale): void {
            $comment = $this->inDiscussion($commentId, $articleId, $locale, true);
            Gate::forUser($this->actor($actor))->authorize('delete', $comment);
            $comment->delete();
            $this->event($comment, $actor, 'author_removed', $comment->status);
        });
    }

    public function toggleLike(User $actor, int $commentId, int $articleId, string $locale): bool
    {
        $actor = $this->activeActor($actor);
        CommunityLimits::hit('like', (string) $actor->id, 30, 60);

        return DB::transaction(function () use ($actor, $commentId, $articleId, $locale): bool {
            $this->publicArticle($articleId, $locale);
            $comment = $this->inDiscussion($commentId, $articleId, $locale, true);
            abort_unless($comment->status === CommentStatus::Approved, 404);
            $this->activeActor($actor);
            $like = CommentLike::query()->where('comment_id', $comment->id)->where('user_id', $actor->id)->first();
            if ($like) {
                $like->delete();

                return false;
            }
            CommentLike::query()->create(['comment_id' => $comment->id, 'user_id' => $actor->id]);

            return true;
        });
    }

    public function report(User $actor, int $commentId, int $articleId, string $locale, string $reason): CommentReport
    {
        $actor = $this->activeActor($actor);
        CommunityLimits::hit('report', (string) $actor->id, 5, 3600);
        Validator::make(['reason' => $reason], ['reason' => ['required', Rule::in(CommentReport::REASONS)]])->validate();

        return DB::transaction(function () use ($actor, $commentId, $articleId, $locale, $reason): CommentReport {
            $this->publicArticle($articleId, $locale);
            $comment = $this->inDiscussion($commentId, $articleId, $locale, true);
            abort_unless($comment->status === CommentStatus::Approved, 404);
            $this->activeActor($actor);
            $report = CommentReport::query()->firstOrNew(['comment_id' => $comment->id, 'user_id' => $actor->id]);
            if ($report->exists && ! $report->resolved_at) {
                throw ValidationException::withMessages(['community' => __('community.report_duplicate')]);
            }
            $report->reason = $reason;
            $report->resolved_at = null;
            $report->resolved_by = null;
            $report->save();
            $this->event($comment, $actor, 'reported', $comment->status);

            return $report;
        });
    }

    public function moderate(User $actor, int $commentId, string $action): Comment
    {
        return DB::transaction(function () use ($actor, $commentId, $action): Comment {
            $actor = $this->actor($actor);
            $comment = Comment::withTrashed()->lockForUpdate()->findOrFail($commentId);
            Gate::forUser($actor)->authorize('moderate', $comment);
            $previous = $comment->status;
            abort_unless(in_array($action, ['approve', 'reject', 'spam', 'remove', 'restore'], true), 422);
            if ($action === 'restore') {
                Gate::forUser($actor)->authorize('restore', $comment);
                $comment->status = CommentStatus::Pending;
                $comment->restore();
            } elseif ($action === 'remove') {
                if ($comment->trashed()) {
                    return $comment;
                }
                $comment->delete();
            } else {
                abort_if($comment->trashed(), 422);
                $comment->status = match ($action) {
                    'approve' => CommentStatus::Approved, 'reject' => CommentStatus::Rejected, 'spam' => CommentStatus::Spam,
                };
                if ($comment->status === $previous) {
                    return $comment;
                }
                $comment->save();
            }
            $this->event($comment, $actor, $action, $previous);
            if (in_array($action, ['reject', 'spam', 'remove'], true)) {
                $comment->reports()->whereNull('resolved_at')->update(['resolved_at' => now(), 'resolved_by' => $actor->id]);
            }
            if ($action === 'approve') {
                $this->notifyReply($comment);
            }

            return $comment;
        });
    }

    public function resolveReport(User $actor, int $reportId): void
    {
        DB::transaction(function () use ($actor, $reportId): void {
            $actor = $this->actor($actor);
            $report = CommentReport::query()->lockForUpdate()->findOrFail($reportId);
            Gate::forUser($actor)->authorize('update', $report);
            if ($report->resolved_at) {
                return;
            }
            $report->resolved_at = now();
            $report->resolved_by = $actor->id;
            $report->save();
            $this->event($report->comment, $actor, 'report_resolved', $report->comment->status);
        });
    }

    public function suspend(User $actor, User $target, bool $suspended): void
    {
        DB::transaction(function () use ($actor, $target, $suspended): void {
            Gate::forUser($this->actor($actor))->authorize('update', $target);
            $user = User::query()->lockForUpdate()->findOrFail($target->id);
            abort_if($user->id === $actor->id, 403);
            if ((bool) $user->community_suspended_at === $suspended) {
                return;
            }
            $user->community_suspended_at = $suspended ? now() : null;
            $user->save();
            CommentModerationEvent::query()->create(['user_id' => $actor->id, 'subject_user_id' => $user->id, 'action' => $suspended ? 'account_suspended' : 'account_restored']);
        });
    }

    private function actor(User $actor): User
    {
        return $actor->fresh() ?? throw new AuthorizationException;
    }

    private function activeActor(User $actor): User
    {
        $actor = $this->actor($actor);
        abort_if($actor->community_suspended_at, 403);

        return $actor;
    }

    private function writingLimits(User $actor): void
    {
        CommunityLimits::hit('comment-minute', (string) $actor->id, 3, 60);
        CommunityLimits::hit('comment-hour', (string) $actor->id, 30, 3600);
        CommunityLimits::hit('comment-ip', (string) request()->ip(), 15, 60);
    }

    /** @param array<string, mixed> $data */
    private function body(array $data): string
    {
        $data['body'] = is_string($data['body'] ?? null) ? trim(str_replace(["\r\n", "\r"], "\n", $data['body'])) : $data['body'] ?? null;
        $values = Validator::make($data, [
            'body' => ['required', 'string', 'max:2000', 'not_regex:/<[^>]*>/', 'not_regex:/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u'],
            'website' => ['nullable', 'size:0'],
        ])->validate();

        return $values['body'];
    }

    private function fingerprint(int $userId, int $articleId, string $locale, ?int $parentId, string $body): string
    {
        return hash('sha256', json_encode([$userId, $articleId, $locale, $parentId, preg_replace('/\s+/u', ' ', $body)], JSON_THROW_ON_ERROR));
    }

    private function uniqueBody(string $fingerprint, ?int $except = null): void
    {
        if (Comment::withTrashed()->where('fingerprint', $fingerprint)->when($except, fn (Builder $query): Builder => $query->whereKeyNot($except))->exists()) {
            throw ValidationException::withMessages(['body' => __('community.duplicate_comment')]);
        }
    }

    private function inDiscussion(int $commentId, int $articleId, string $locale, bool $lock = false): Comment
    {
        return Comment::query()->whereKey($commentId)->where('article_id', $articleId)->where('locale', $locale)
            ->when($lock, fn (Builder $query): Builder => $query->lockForUpdate())->firstOrFail();
    }

    private function event(Comment $comment, User $actor, string $action, ?CommentStatus $previous): void
    {
        CommentModerationEvent::query()->create(['comment_id' => $comment->id, 'user_id' => $actor->id, 'action' => $action, 'previous_status' => $previous?->value, 'new_status' => $comment->status->value]);
    }

    private function notifyReply(Comment $comment): void
    {
        if (! $comment->parent_id || $comment->reply_notified_at || ! $this->isPublic($comment)) {
            return;
        }
        $parent = $comment->parent;
        $recipient = $parent?->user;
        if (! $parent || $parent->trashed() || $parent->status !== CommentStatus::Approved || ! $recipient
            || $recipient->id === $comment->user_id || ! $recipient->hasVerifiedEmail() || ! $recipient->reply_notifications || $recipient->community_suspended_at) {
            return;
        }
        $comment->reply_notified_at = now();
        $comment->save();
        $recipient->notify(new CommentReplyApproved($comment->id));
    }
}
