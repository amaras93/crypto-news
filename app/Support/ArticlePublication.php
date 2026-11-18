<?php

namespace App\Support;

use App\ArticleStatus;
use App\Models\Article;
use App\Models\EditorialEvent;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class ArticlePublication
{
    public function __construct(private ArticleContent $content, private PublicUrl $urls) {}

    public function assertPublishable(Article $article, bool $requireHuman = true): void
    {
        $article->loadMissing('translations', 'author');
        app(AiEditorialReviewService::class)->assertPublishable($article, $requireHuman);
        app(SponsoredContentService::class)->assertPublishable($article, $requireHuman);
        if (! $article->author || trim($article->author->name) === '') {
            throw ValidationException::withMessages(['author_id' => 'Select a valid author.']);
        }
        $translations = $article->translations->whereIn('locale', array_keys(config('news.locales')));
        if ($translations->isEmpty()) {
            throw ValidationException::withMessages(['translations' => 'At least one completed public translation is required.']);
        }
        foreach ($translations as $translation) {
            if (! $this->urls->hasSafeArticleUrl($translation) || trim($translation->title) === ''
                || $this->content->render($translation->body)['text'] === '') {
                throw ValidationException::withMessages(['translations' => 'Every enabled public translation must have a headline, safe slug and readable body.']);
            }
        }
    }

    public function transition(User $actor, Article $article, string $action, ?CarbonImmutable $date = null): Article
    {
        $actor = $actor->fresh() ?? throw new AuthorizationException;

        return DB::transaction(function () use ($actor, $article, $action, $date): Article {
            $record = Article::query()->lockForUpdate()->findOrFail($article->id);
            $ability = in_array($action, ['save_draft', 'submit_review'], true) ? 'submit' : 'publish';
            Gate::forUser($actor)->authorize($ability, $record);
            $before = [$record->status, $record->published_at?->timestamp, $record->approved_at?->timestamp, $record->reviewer_id];
            switch ($action) {
                case 'save_draft':
                case 'unpublish':
                    $record->status = ArticleStatus::Draft;
                    $record->approved_at = null;
                    $record->reviewer_id = null;
                    break;
                case 'submit_review':
                    $this->assertPublishable($record, false);
                    $record->status = ArticleStatus::PendingReview;
                    $record->approved_at = null;
                    $record->reviewer_id = null;
                    break;
                case 'approve':
                    if ($record->status === ArticleStatus::Draft && $record->approved_at) {
                        return $record;
                    }
                    if ($record->status !== ArticleStatus::PendingReview) {
                        throw ValidationException::withMessages(['status' => 'Only articles in review can be approved.']);
                    }
                    $this->assertPublishable($record);
                    $record->status = ArticleStatus::Draft;
                    $record->reviewer_id = $actor->id;
                    $record->approved_at = now();
                    break;
                case 'schedule':
                    if (! $date || $date->lessThanOrEqualTo(now())) {
                        throw ValidationException::withMessages(['published_at' => 'Select a future UTC publication time.']);
                    }
                    $this->assertPublishable($record);
                    $record->status = ArticleStatus::Scheduled;
                    $record->published_at = $date;
                    $record->reviewer_id = $actor->id;
                    $record->approved_at = $record->approved_at ?? now();
                    break;
                case 'publish':
                    if ($record->status === ArticleStatus::Published && $record->published_at?->lessThanOrEqualTo(now())) {
                        return $record;
                    }
                    $this->assertPublishable($record);
                    $record->status = ArticleStatus::Published;
                    $record->published_at = now();
                    $record->reviewer_id = $actor->id;
                    $record->approved_at = $record->approved_at ?? now();
                    break;
                default:
                    throw ValidationException::withMessages(['status' => 'Unknown editorial operation.']);
            }
            $after = [$record->status, $record->published_at?->timestamp, $record->approved_at?->timestamp, $record->reviewer_id];
            if ($before !== $after) {
                $record->save();
                EditorialEvent::create(['article_id' => $record->id, 'user_id' => $actor->id, 'action' => $action]);
            }

            return $record->fresh(['translations', 'author', 'tags', 'category.translations']);
        });
    }

    public function publishScheduled(Article $article): bool
    {
        return DB::transaction(function () use ($article): bool {
            $record = Article::query()->lockForUpdate()->find($article->id);
            if (! $record || $record->status !== ArticleStatus::Scheduled || ! $record->published_at
                || $record->published_at->isFuture() || ! $record->approved_at || ! $record->reviewer?->is_admin) {
                return false;
            }
            $this->assertPublishable($record);
            $record->status = ArticleStatus::Published;
            $record->save();
            EditorialEvent::create(['article_id' => $record->id, 'user_id' => $record->reviewer_id, 'action' => 'scheduled_published']);

            return true;
        });
    }

    public function trash(User $actor, Article $article): void
    {
        $actor = $actor->fresh() ?? throw new AuthorizationException;
        DB::transaction(function () use ($actor, $article): void {
            $record = Article::query()->lockForUpdate()->findOrFail($article->id);
            Gate::forUser($actor)->authorize('delete', $record);
            $record->forceFill(['status' => ArticleStatus::Draft, 'approved_at' => null, 'reviewer_id' => null])->save();
            $record->delete();
            EditorialEvent::create(['article_id' => $record->id, 'user_id' => $actor->id, 'action' => 'trashed']);
        });
    }

    public function restore(User $actor, Article $article): void
    {
        $actor = $actor->fresh() ?? throw new AuthorizationException;
        DB::transaction(function () use ($actor, $article): void {
            $record = Article::withTrashed()->lockForUpdate()->findOrFail($article->id);
            Gate::forUser($actor)->authorize('restore', $record);
            $record->forceFill(['status' => ArticleStatus::Draft, 'approved_at' => null, 'reviewer_id' => null])->save();
            $record->restore();
            EditorialEvent::create(['article_id' => $record->id, 'user_id' => $actor->id, 'action' => 'restored']);
        });
    }
}
