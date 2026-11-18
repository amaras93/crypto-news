<?php

namespace App\Console\Commands;

use App\Models\AiGenerationRun;
use App\Models\User;
use App\Support\AiDrafting;
use App\Support\AiFailure;
use Illuminate\Console\Command;

class RetryAiDraft extends Command
{
    protected $signature = 'ai:retry {run : Failed run ID} {--admin= : Existing administrator ID authorizing the retry}';

    protected $description = 'Explicitly retry eligible rate-limited or unsent AI work after backoff; never retries uncertain costs';

    public function handle(AiDrafting $pipeline): int
    {
        $actor = User::find($this->option('admin'));
        $run = AiGenerationRun::find($this->argument('run'));
        if (! $actor?->is_admin || ! $run) {
            $this->error('An existing administrator and generation run are required.');

            return self::FAILURE;
        }
        try {
            $next = $pipeline->retry($actor, $run);
            $this->info('Queued retry run '.$next->id);

            return self::SUCCESS;
        } catch (AiFailure $failure) {
            $this->error($failure->reason);

            return self::FAILURE;
        }
    }
}
