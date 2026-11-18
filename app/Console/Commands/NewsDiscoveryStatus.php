<?php

namespace App\Console\Commands;

use App\Models\AiBudgetPeriod;
use App\Models\IngestionRun;
use App\Models\NewsCandidate;
use App\Models\NewsSource;
use App\Support\DiscoverySettingsManager;
use Illuminate\Console\Command;

class NewsDiscoveryStatus extends Command
{
    protected $signature = 'news:status';

    protected $description = 'Inspect discovery and accounting status without requests or writes';

    public function handle(DiscoverySettingsManager $settings): int
    {
        $this->info('Discovery: '.($settings->enabled() ? 'enabled' : 'disabled'));
        $this->line('AI generation: unavailable in Phase 07; automatic publishing: disabled');
        $this->line('Enabled sources: '.NewsSource::query()->where('enabled', true)->count());
        $this->line('Candidates: '.NewsCandidate::query()->count());
        $this->table(['Run state', 'Count'], IngestionRun::query()->selectRaw('status, count(*) as total')->groupBy('status')->get()->map(fn (IngestionRun $run): array => [$run->status->value, $run->total])->all());
        $this->table(['UTC accounting period', 'Reserved estimate (micro-USD)', 'Recorded actual (micro-USD)'],
            AiBudgetPeriod::query()->whereIn('period_key', ['day:'.now()->utc()->toDateString(), 'month:'.now()->utc()->format('Y-m')])
                ->get()->map(fn (AiBudgetPeriod $period): array => [$period->period_key, $period->reserved_microusd, $period->actual_microusd])->all());

        return self::SUCCESS;
    }
}
