<?php

namespace App\Support;

use App\AiRunStatus;
use App\ArticleStatus;
use App\Models\AiGenerationRun;
use App\Models\Article;
use App\Models\User;

class AiRecovery
{
    public function plan(AiGenerationRun $run): ?string
    {
        $inconsistent = $run->task === 'verification' && $run->schema_version === 'verification-v2'
            && app(AiSemanticVerification::class)->inconsistent($run->output_document ?? []);
        $formatWarning = $run->task === 'translation' && ($run->quality_report['critical'] ?? []) === ['model_reported_unsupported_claims'];
        if (! $inconsistent && ! $formatWarning && ($run->quality_report['recovery']['state'] ?? null) === 'held' && ($run->quality_report['recovery']['reason'] ?? null) !== 'recovery_budget_unavailable') {
            return null;
        }
        if (! $run->is_automated || $run->is_simulated || $run->children()->exists()
            || $run->attempt >= config('ai.max_attempts') || $run->reservation?->status !== 'settled') {
            return null;
        }
        if ($run->article_id && AiGenerationRun::where('article_id', $run->article_id)->where('locale', $run->locale)
            ->where('task', $run->task)->whereNotNull('parent_run_id')->count() >= 2) {
            return null;
        }
        if ($run->status === AiRunStatus::Failed && in_array($run->error_code, ['invalid_structured_output', 'incomplete_response'], true)) {
            return 'format_correction';
        }
        if ($run->status !== AiRunStatus::QualityBlocked || $run->error_code === 'originality_correction_available'
            || $run->error_code === 'originality_correction_queued' || $run->parent?->error_code === 'originality_correction_queued') {
            return null;
        }
        $critical = $run->quality_report['critical'] ?? [];
        if ($critical === []) {
            return null;
        }
        if ($run->task === 'verification') {
            if ($run->schema_version === 'verification-v1') {
                return $critical === ['semantic_verification_failed'] ? 'legacy_verification' : null;
            }
            if (array_intersect($critical, ['semantic_contradicted', 'semantic_absent']) !== []) {
                return null;
            }
            if ($inconsistent) {
                return 'verification_correction';
            }
            $document = $run->output_document;
            if ($run->locale !== 'en' && ($document['faithful_translation'] === false || $document['correct_language'] === false || $document['original'] === false)) {
                return AiGenerationRun::where('article_id', $run->article_id)->where('locale', $run->locale)
                    ->where('task', 'translation')->whereNotNull('parent_run_id')->count() < 2 ? 'translation_correction' : null;
            }
            if (array_intersect($critical, ['semantic_uncertain', 'semantic_evidence_reference_invalid', 'semantic_field_coverage_incomplete', 'semantic_evidence_missing']) !== []) {
                return 'verification_correction';
            }

            return null;
        }
        if (! in_array($run->task, ['english', 'translation'], true)) {
            return null;
        }
        if (in_array('excessive_source_similarity', $critical, true) && $run->task === 'english') {
            return null;
        }
        if ($run->task === 'translation' && $critical === ['model_reported_unsupported_claims']) {
            return 'format_correction';
        }
        foreach ($critical as $failure) {
            if (! preg_match('/\A(?:claim_mismatch:)?(?:technical_evidence_id_in_public_text|unsupported_(?:entity|number|symbol|currency):[^\r\n]+|excessive_source_similarity|language_script_mismatch|translation_numbers_or_dates_changed|translation_entity_missing:[^\r\n]+|seo_[a-z_]+)\z/D', $failure)) {
                return null;
            }
        }

        return 'content_correction';
    }

    public function tick(User $actor): int
    {
        $count = 0;
        foreach (AiGenerationRun::where('is_automated', true)->whereIn('status', [AiRunStatus::QualityBlocked, AiRunStatus::Failed])
            ->whereDoesntHave('children')->where('attempt', '<', config('ai.max_attempts'))
            ->where(fn ($query) => $query->whereNull('quality_report->recovery->state')->orWhere('quality_report->recovery->state', 'available')
                ->orWhere('quality_report->recovery->reason', 'recovery_budget_unavailable')
                ->orWhere(fn ($verifier) => $verifier->where('task', 'verification')->where('schema_version', 'verification-v2')
                    ->where('quality_report->recovery->reason', 'critical_quality_failure'))
                ->orWhere(fn ($translation) => $translation->where('task', 'translation')
                    ->where('quality_report->recovery->reason', 'critical_quality_failure')
                    ->whereJsonContains('quality_report->critical', 'model_reported_unsupported_claims')))->oldest('id')->limit(50)->get() as $run) {
            if (! $run->article_id && (Article::where('automation_status', 'processing')->exists()
                || AiGenerationRun::where('task', 'english')->where('news_candidate_id', '<>', $run->news_candidate_id)
                    ->whereIn('status', [AiRunStatus::Queued, AiRunStatus::Requesting, AiRunStatus::Received, AiRunStatus::Validated])->exists())) {
                continue;
            }
            $plan = $this->plan($run);
            if (! $plan) {
                if (! in_array($run->error_code, ['originality_correction_available', 'originality_correction_queued'], true)
                    && ! ($run->status === AiRunStatus::Failed && $run->retry_at)) {
                    $report = $run->quality_report ?? [];
                    $report['recovery'] = ['state' => 'held', 'reason' => $run->error_code ?? 'stage_not_safely_recoverable'];
                    $run->forceFill(['quality_report' => $report])->save();
                }

                continue;
            }
            if ($run->retry_at && $run->retry_at->isFuture()) {
                continue;
            }
            $article = $run->article;
            if ($article && ($article->status !== ArticleStatus::Draft || in_array($article->automation_status, ['paused', 'published'], true))) {
                continue;
            }
            if (! $run->retry_at) {
                $report = $run->quality_report ?? [];
                $report['recovery'] = ['state' => 'available', 'plan' => $plan];
                $run->forceFill(['retry_at' => now()->addSeconds(60 * (2 ** ($run->attempt - 1))), 'quality_report' => $report])->save();

                continue;
            }
            try {
                app(AiDrafting::class)->recoverStage($actor, $run);
                $count++;
            } catch (AiFailure $failure) {
                $report = $run->quality_report ?? [];
                $report['recovery'] = ['state' => 'held', 'reason' => $failure->reason, 'plan' => $plan];
                $run->forceFill(['quality_report' => $report, 'retry_at' => null])->save();
                if ($failure->reason === 'recovery_budget_unavailable') {
                    $run->forceFill(['retry_at' => now()->utc()->addDay()->startOfDay()])->save();
                }
            }
        }

        return $count;
    }

    public function pending(AiGenerationRun $run): bool
    {
        return $this->plan($run) !== null;
    }
}
