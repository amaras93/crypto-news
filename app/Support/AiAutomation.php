<?php

namespace App\Support;

use App\AiRunStatus;
use App\ArticleStatus;
use App\CandidateStatus;
use App\Jobs\VerifyNewsCandidate;
use App\Models\AiGenerationRun;
use App\Models\Article;
use App\Models\NewsCandidate;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class AiAutomation
{
    public function __construct(private AiSettings $settings, private AiEvidence $evidence, private AiQuality $quality) {}

    public function tick(): int
    {
        if (! $this->settings->automatic()) {
            return 0;
        }
        $lock = app(NewsDiscovery::class)->cache()->lock('ai:automation-dispatch', 60);
        if (! $lock->get()) {
            return 0;
        }
        try {
            $current = $this->settings->current();
            $actor = User::find($current['updated_by']);
            $author = User::find($current['author_id']);
            if (! $actor?->is_admin || ! $author?->isEditorial()) {
                return 0;
            }
            $pipeline = app(AiDrafting::class);
            $count = 0;
            foreach (AiGenerationRun::where('is_automated', true)->where('status', AiRunStatus::Failed)
                ->whereNotNull('retry_at')->where('retry_at', '<=', now())->where('attempt', '<', config('ai.max_attempts'))
                ->whereDoesntHave('article', fn ($query) => $query->whereIn('automation_status', ['paused', 'published']))
                ->whereDoesntHave('children')->oldest('id')->limit(10)->get() as $run) {
                try {
                    $pipeline->retry($actor, $run);
                    $count++;
                } catch (AiFailure) {
                    continue;
                }
            }
            foreach (Article::where('automation_status', 'processing')->oldest('id')->limit(10)->get() as $article) {
                $this->advance($article, $actor);
            }
            if (! config('ai.scheduled_generation_enabled') || ! $current['scheduled_generation_enabled'] || ! $pipeline->isOperational()) {
                return $count;
            }
            $limit = min(5, $this->settings->limits()['articles']);
            foreach (NewsCandidate::where('is_simulated', false)->whereNull('duplicate_of_id')
                ->whereIn('category_id', $current['enabled_category_ids'])->where('status', CandidateStatus::ApprovedForDraft)
                ->where('automation_status', 'evidence_verified')->whereDoesntHave('aiRuns')->oldest('id')->limit($limit)->get() as $candidate) {
                try {
                    $pipeline->generate($actor, $candidate, $author, automated: true);
                    $count++;
                } catch (AiFailure $failure) {
                    $this->holdCandidate($candidate, $failure->reason);
                }
            }
            foreach (NewsCandidate::where('is_simulated', false)->whereNull('duplicate_of_id')->whereDoesntHave('aiRuns')
                ->whereIn('category_id', $current['enabled_category_ids'])->whereIn('status', [CandidateStatus::New, CandidateStatus::ReviewRequired, CandidateStatus::ProcessingFailed])
                ->where('automation_attempts', '<', 3)
                ->where(fn ($query) => $query->whereNull('automation_status')->orWhere('automation_status', 'retry'))
                ->where(fn ($query) => $query->whereNull('automation_retry_at')->orWhere('automation_retry_at', '<=', now()))
                ->oldest('id')->limit($limit)->get() as $candidate) {
                VerifyNewsCandidate::dispatch($candidate->id)->afterCommit();
                $count++;
            }

            return $count;
        } finally {
            $lock->release();
        }
    }

    public function verifyCandidate(int $id): void
    {
        if (! $this->settings->automatic()) {
            return;
        }
        $lock = app(NewsDiscovery::class)->cache()->lock('ai:candidate:'.$id, 60);
        if (! $lock->get()) {
            return;
        }
        try {
            $candidate = NewsCandidate::findOrFail($id);
            if ($candidate->automation_attempts >= 3 || ! in_array((int) $candidate->category_id,
                array_map('intval', $this->settings->current()['enabled_category_ids']), true)
                || ($candidate->automation_retry_at && $candidate->automation_retry_at->isFuture())
                || ! in_array($candidate->automation_status, [null, 'retry'], true)) {
                return;
            }
            $candidate->increment('automation_attempts');
            try {
                app(AutomatedEvidence::class)->retrieve($candidate);
                Log::info('AI candidate evidence verified automatically', ['candidate_id' => $id]);
            } catch (FeedFailure $failure) {
                $retry = in_array($failure->reason, ['network_error', 'transport_error', 'provider_rate_limited', 'http_error'], true)
                    && $candidate->automation_attempts < 3;
                $candidate->forceFill(['automation_status' => $retry ? 'retry' : 'held', 'automation_error' => $failure->reason,
                    'automation_retry_at' => $retry ? now()->addSeconds(max(60 * (2 ** $candidate->automation_attempts), $failure->retrySeconds)) : null])->save();
            } catch (AiFailure $failure) {
                $this->holdCandidate($candidate, $failure->reason);
            }
        } finally {
            $lock->release();
        }
    }

    private function holdCandidate(NewsCandidate $candidate, string $reason): void
    {
        $candidate->forceFill(['automation_status' => 'held', 'automation_error' => $reason])->save();
        Log::notice('AI candidate held', ['candidate_id' => $candidate->id, 'reason' => $reason]);
    }

    public function advance(Article $article, User $actor): void
    {
        try {
            if (! $this->settings->automatic() || $article->automation_status !== 'processing') {
                return;
            }
            $article = $article->fresh(['translations', 'aiRun']);
            foreach (array_keys(config('news.locales')) as $locale) {
                $translation = $article->translation($locale);
                if ($translation?->ai_review_status === 'automated') {
                    $this->assertAccepted($article, $locale);

                    continue;
                }
                $task = $translation ? 'verification' : 'translation';
                $previous = AiGenerationRun::where('article_id', $article->id)->where('task', $task)->where('locale', $locale)->latest('id')->first();
                if ($previous) {
                    if (in_array($previous->status, [AiRunStatus::Failed, AiRunStatus::Uncertain, AiRunStatus::QualityBlocked, AiRunStatus::Cancelled], true)) {
                        if ($previous->status === AiRunStatus::Failed && $previous->retry_at && $previous->attempt < config('ai.max_attempts')) {
                            return;
                        }
                        throw new AiFailure($previous->error_code ?? 'automatic_stage_failed');
                    }

                    return;
                }
                if ($translation) {
                    $generated = $translation->aiRun;
                    if (! $generated || $generated->status !== AiRunStatus::Stored || $translation->ai_human_edited_at
                        || $translation->contentDigest() !== $translation->ai_generated_hash) {
                        throw new AiFailure('automatic_content_changed');
                    }
                    app(AiDrafting::class)->verify($actor, $article, $locale);
                } else {
                    $this->assertAccepted($article, 'en');
                    app(AiDrafting::class)->translate($actor, $article, [$locale]);
                }

                return;
            }
            app(ArticlePublication::class)->transition($actor, $article, 'publish_automatically');
        } catch (AiFailure|ValidationException $failure) {
            $reason = $failure instanceof AiFailure ? $failure->reason : 'automatic_publication_validation_failed';
            Article::whereKey($article->id)->where('automation_status', 'processing')
                ->update(['automation_status' => 'held', 'automation_error' => $reason]);
            Log::notice('AI article held', ['article_id' => $article->id, 'reason' => $reason]);
        }
    }

    public function accept(AiGenerationRun $run): void
    {
        $article = Article::query()->lockForUpdate()->findOrFail($run->article_id);
        if ($article->automation_status !== 'processing' || ! $this->settings->automatic()) {
            throw new AiFailure('automation_paused');
        }
        $translation = $article->translations()->where('locale', $run->locale)->lockForUpdate()->firstOrFail();
        $generated = $translation->aiRun;
        if (! $generated || $generated->status !== AiRunStatus::Stored || ($generated->quality_report['critical'] ?? ['missing_report'])
            || $translation->contentDigest() !== $run->target_digest || $translation->ai_generated_hash !== $run->target_digest
            || $this->quality->saved($translation, $generated)['critical']) {
            throw new AiFailure('automatic_validation_failed');
        }
        $translation->forceFill(['ai_review_status' => 'automated', 'ai_review_hash' => $translation->contentDigest(),
            'ai_reviewed_by' => null, 'ai_reviewed_at' => now()])->save();
        Log::info('AI language passed automated verification', ['article_id' => $article->id, 'locale' => $run->locale, 'verification_run_id' => $run->id]);
    }

    public function assertAccepted(Article $article, string $locale): void
    {
        $translation = $article->translation($locale);
        $generated = $translation?->aiRun;
        $verification = AiGenerationRun::where('article_id', $article->id)->where('task', 'verification')->where('locale', $locale)->latest('id')->first();
        if (! $translation || $translation->ai_review_status !== 'automated' || $translation->ai_reviewed_by
            || ! $translation->ai_reviewed_at || $translation->ai_human_edited_at
            || ! $generated || ! $generated->is_automated || $generated->status !== AiRunStatus::Stored
            || $generated->locale !== $locale || $generated->evidence_digest !== $article->aiRun->evidence_digest
            || ($generated->quality_report['critical'] ?? ['missing_report'])
            || $translation->contentDigest() !== $translation->ai_generated_hash || $translation->contentDigest() !== $translation->ai_review_hash
            || ! $verification || ! $verification->is_automated || $verification->status !== AiRunStatus::Stored
            || ($verification->quality_report['critical'] ?? ['missing_report']) || $verification->target_digest !== $translation->contentDigest()
            || $verification->english_digest !== $article->translation('en')?->contentDigest()
            || $verification->evidence_digest !== $article->aiRun->evidence_digest
            || $this->quality->saved($translation, $translation->aiRun)['critical']) {
            throw new AiFailure('automated_language_verification_required');
        }
        if ($this->evidence->digest($this->evidence->snapshot($article->aiRun->candidate)) !== $article->aiRun->evidence_digest) {
            throw new AiFailure('evidence_changed');
        }
    }

    public function assertPublishable(Article $article): void
    {
        if (! $this->settings->automatic() || $article->automation_status !== 'processing' || $article->ai_simulated || $article->isSponsored()
            || $article->status !== ArticleStatus::Draft || ! $article->aiRun?->is_automated
            || $article->aiRun->candidate->duplicate_of_id
            || ! in_array((int) $article->category_id, array_map('intval', $this->settings->current()['enabled_category_ids']), true)) {
            throw new AiFailure('automatic_publication_not_allowed');
        }
        foreach (array_keys(config('news.locales')) as $locale) {
            $this->assertAccepted($article, $locale);
        }
        app(ArticleSeo::class)->assertAutomaticPublication($article);
    }
}
