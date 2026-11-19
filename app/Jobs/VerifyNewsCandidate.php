<?php

namespace App\Jobs;

use App\Models\NewsCandidate;
use App\Support\AiAutomation;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class VerifyNewsCandidate implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 45;

    public int $uniqueFor = 90;

    public function __construct(public int $candidateId)
    {
        $this->onQueue('ai');
    }

    /**
     * Execute the job.
     */
    public function uniqueId(): string
    {
        return (string) $this->candidateId;
    }

    public function handle(AiAutomation $automation): void
    {
        $automation->verifyCandidate($this->candidateId);
    }

    public function failed(?Throwable $exception): void
    {
        $candidate = NewsCandidate::find($this->candidateId);
        if ($candidate && in_array($candidate->automation_status, [null, 'retry'], true)) {
            $candidate->forceFill(['automation_status' => $candidate->automation_attempts < 3 ? 'retry' : 'held',
                'automation_error' => 'evidence_worker_failed', 'automation_retry_at' => now()->addMinutes(5)])->save();
        }
    }
}
