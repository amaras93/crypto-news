<?php

namespace App\Console\Commands;

use App\AiRunStatus;
use App\CandidateStatus;
use App\Models\AiGenerationRun;
use App\Models\Article;
use App\Models\NewsCandidate;
use App\Models\User;
use App\Support\AiAutomation;
use App\Support\AiDrafting;
use App\Support\AiFailure;
use App\Support\AiSettings;
use Illuminate\Console\Command;

class ScheduleAiDrafts extends Command
{
    protected $signature = 'ai:dispatch';

    protected $description = 'Resume bounded AI stages and run enabled evidence, generation, translation and publishing workflows';

    public function handle(AiSettings $settings, AiDrafting $pipeline): int
    {
        $current = $settings->current();
        if (! $settings->enabledFor('english') && ! $settings->enabledFor('translation')) {
            $this->info('AI generation stopped; no work queued.');

            return self::SUCCESS;
        }
        foreach (AiGenerationRun::where('status', AiRunStatus::Requesting)->where('updated_at', '<', now()->subMinutes(5))->limit(20)->get() as $run) {
            $pipeline->fail($run, new AiFailure('worker_outcome_unknown', uncertain: (bool) $run->request_sent_at));
        }
        $count = 0;
        $count += app(AiAutomation::class)->tick();
        foreach (AiGenerationRun::whereIn('status', [AiRunStatus::Queued, AiRunStatus::Received, AiRunStatus::Validated])
            ->where('is_simulated', false)->oldest('id')->limit(20)->get() as $run) {
            if ($settings->enabledFor($run->task)) {
                $pipeline->dispatch($run);
                $count++;
            }
        }
        if (! $settings->automatic() && config('ai.scheduled_generation_enabled') && $current['scheduled_generation_enabled'] && $pipeline->isOperational()) {
            $actor = User::find($current['updated_by'] ?? null);
            $author = User::find($current['author_id']);
            if ($actor?->is_admin && $author?->isEditorial()) {
                $ids = Article::withTrashed()->whereNotNull('ai_candidate_id')->select('ai_candidate_id');
                foreach (NewsCandidate::where('status', CandidateStatus::ApprovedForDraft)->where('is_simulated', false)
                    ->whereNotIn('id', $ids)->whereDoesntHave('aiRuns')->oldest('id')->limit(min(5, $settings->limits()['articles']))->get() as $candidate) {
                    try {
                        $pipeline->generate($actor, $candidate, $author);
                        $count++;
                    } catch (AiFailure) {
                        continue;
                    }
                }
            }
        }
        $this->info('Queued AI stages: '.$count.'. Automatic publication requires all eight verified languages and enabled controls.');

        return self::SUCCESS;
    }
}
