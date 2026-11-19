<?php

namespace App\Support;

use App\ArticleStatus;
use App\Models\AiEditorialReview;
use App\Models\AiGenerationRun;
use App\Models\Article;
use App\Models\ArticleTranslation;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class AiEditorialReviewService
{
    public function __construct(private AiEvidence $evidence, private AiQuality $quality) {}

    public function review(User $actor, Article $article, string $locale, bool $approve, bool $factsConfirmed, string $notes): void
    {
        Gate::forUser($actor->fresh())->authorize('update', $article);
        Validator::make(['notes' => $notes, 'confirmed' => $factsConfirmed], [
            'notes' => ['required', 'string', 'min:30', 'max:2000'], 'confirmed' => $approve ? ['accepted'] : ['boolean'],
        ])->validate();
        DB::transaction(function () use ($actor, $article, $locale, $approve, $notes): void {
            $record = Article::query()->lockForUpdate()->findOrFail($article->id);
            Gate::forUser($actor->fresh())->authorize('update', $record);
            if (! $record->ai_generation_run_id || ! in_array($record->status, [ArticleStatus::Draft, ArticleStatus::PendingReview], true)) {
                throw new AiFailure('only_unpublished_ai_drafts_can_be_verified');
            }
            if ($record->automation_status) {
                $record->forceFill(['automation_status' => 'paused'])->save();
            }
            $translation = $record->translations()->where('locale', $locale)->lockForUpdate()->firstOrFail();
            $run = $translation->aiRun ?? $record->aiRun;
            if ($approve && $locale !== 'en') {
                $this->assertEnglishReviewed($record->fresh(['translations']));
            }
            $report = $this->quality->saved($translation, $run);
            if ($approve) {
                $this->assertEvidence($run);
                if ($report['critical'] || ($run->quality_report['critical'] ?? ['missing_report'])) {
                    throw ValidationException::withMessages(['ai_review' => 'Critical verification checks must be resolved before approval: '.implode(', ', $report['critical'])]);
                }
                if ($locale !== 'en') {
                    $english = $record->fresh(['translations'])->translation('en');
                    $text = app(ArticleContent::class)->render($translation->body)['text'].' '.$translation->title.' '.$translation->excerpt.' '.$translation->meta_title.' '.$translation->meta_description.' '.implode(' ', $translation->summary ?? []);
                    $source = app(ArticleContent::class)->render($english->body)['text'].' '.$english->title.' '.$english->excerpt.' '.$english->meta_title.' '.$english->meta_description.' '.implode(' ', $english->summary ?? []);
                    if ($this->quality->numbers($text) !== $this->quality->numbers($source)) {
                        throw new AiFailure('translation_numbers_or_dates_changed');
                    }
                    $entities = collect($record->aiRun->evidence_snapshot['claims'])->flatMap(fn (array $claim): array => $claim['entities'])->unique();
                    foreach ($entities as $entity) {
                        if (str_contains($source, $entity) && ! str_contains($text, $entity)) {
                            throw new AiFailure('translation_entity_missing');
                        }
                    }
                }
            }
            $translation->forceFill(['ai_review_status' => $approve ? 'approved' : 'rejected',
                'ai_review_hash' => $approve ? $translation->contentDigest() : null, 'ai_reviewed_by' => $actor->id, 'ai_reviewed_at' => now()])->save();
            if (! $approve) {
                $record->forceFill(['status' => ArticleStatus::Draft, 'approved_at' => null, 'reviewer_id' => null])->save();
            }
            AiEditorialReview::create(['article_translation_id' => $translation->id, 'ai_generation_run_id' => $run->id,
                'user_id' => $actor->id, 'decision' => $approve ? 'approved' : 'rejected',
                'content_digest' => $translation->contentDigest(), 'evidence_digest' => $run->evidence_digest,
                'notes' => $notes, 'quality_report' => $report]);
        }, 3);
    }

    public function assertEnglishReviewed(Article $article): void
    {
        $english = $article->translation('en');
        if (! $english || ! $this->isReviewed($english)) {
            throw new AiFailure('english_human_review_required');
        }
        $this->assertEvidence($article->aiRun);
        $report = $this->quality->saved($english, $article->aiRun);
        if ($report['critical']) {
            throw new AiFailure('critical_quality_failure');
        }
    }

    public function assertPublishable(Article $article, bool $requireHuman): void
    {
        if (! $article->ai_generation_run_id) {
            return;
        }
        $run = $article->aiRun;
        if (! $run || ($run->quality_report['critical'] ?? ['missing_report'])) {
            throw ValidationException::withMessages(['ai_review' => 'The AI draft has not passed quality screening.']);
        }
        if ($requireHuman) {
            try {
                if ($article->ai_simulated) {
                    throw new AiFailure('simulated_articles_cannot_be_published');
                }
                $this->assertEnglishReviewed($article);
                foreach ($article->translations as $translation) {
                    if ($translation->locale !== 'en' && $translation->ai_review_status === 'approved'
                        && (! $this->isReviewed($translation) || $this->quality->saved($translation, $translation->aiRun ?? $run)['critical'])) {
                        throw new AiFailure('translation_review_outdated');
                    }
                }
            } catch (AiFailure $failure) {
                throw ValidationException::withMessages(['ai_review' => $failure->reason]);
            }
        }
    }

    private function isReviewed(ArticleTranslation $translation): bool
    {
        return $translation->ai_review_status === 'approved' && $translation->ai_review_hash === $translation->contentDigest()
            && $translation->ai_reviewed_at && User::find($translation->ai_reviewed_by)?->isEditorial();
    }

    private function assertEvidence(AiGenerationRun $run): void
    {
        if ($this->evidence->digest($this->evidence->snapshot($run->candidate)) !== $run->evidence_digest) {
            throw new AiFailure('evidence_changed');
        }
    }
}
