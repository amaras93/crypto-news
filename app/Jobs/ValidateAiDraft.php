<?php

namespace App\Jobs;

use App\AiRunStatus;
use App\Models\AiGenerationRun;
use App\Support\AiDrafting;
use App\Support\AiFailure;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class ValidateAiDraft implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 75;

    public int $uniqueFor = 90;

    public function __construct(public int $runId)
    {
        $this->onQueue('ai');
    }

    public function uniqueId(): string
    {
        return (string) $this->runId;
    }

    public function handle(AiDrafting $pipeline): void
    {
        $pipeline->validate($this->runId);
    }

    public function failed(?Throwable $exception): void
    {
        $run = AiGenerationRun::find($this->runId);
        if ($run && in_array($run->status, [AiRunStatus::Queued, AiRunStatus::Requesting], true)) {
            app(AiDrafting::class)->fail($run, new AiFailure('worker_failed', uncertain: (bool) $run->request_sent_at && $run->actual_microusd === null && ! $run->is_simulated));
        }
    }
}
