<?php

namespace App\Console\Commands;

use App\Models\IngestionRun;
use App\Models\User;
use App\Support\NewsDiscovery;
use Illuminate\Console\Command;

class RetryNewsIngestion extends Command
{
    protected $signature = 'news:retry {run : Failed ingestion run ID} {--admin= : Existing administrator ID}';

    protected $description = 'Queue one eligible failed run with authorization, cooldowns and the three-attempt cap';

    public function handle(NewsDiscovery $discovery): int
    {
        $actor = User::query()->find($this->option('admin'));
        $run = IngestionRun::query()->find($this->argument('run'));
        if (! $actor?->is_admin || ! $run) {
            $this->error('Select an existing run and a current administrator.');

            return self::FAILURE;
        }
        if (! $discovery->retry($actor, $run)) {
            $this->warn('Not eligible: check discovery state, cooldown, configuration version and attempts.');

            return self::FAILURE;
        }
        $this->info('Retry queued.');

        return self::SUCCESS;
    }
}
