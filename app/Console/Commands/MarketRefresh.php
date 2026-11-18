<?php

namespace App\Console\Commands;

use App\Jobs\RefreshMarketData;
use App\Support\MarketDataCache;
use App\Support\MarketDataService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

#[Signature('markets:refresh {--group=all : all, crypto or metals} {--queued : Dispatch eligible refreshes to the existing queue} {--status : Show sanitized cache usage without fetching}')]
#[Description('Refresh due market quotes safely; always respects locks, intervals and provider cooldowns')]
class MarketRefresh extends Command
{
    public function handle(MarketDataService $markets, MarketDataCache $cache): int
    {
        $group = $this->option('group');
        if (! in_array($group, ['all', 'crypto', 'metals'], true)) {
            $this->error('Choose --group=all, crypto or metals.');

            return self::INVALID;
        }
        $exit = self::SUCCESS;
        foreach ($group === 'all' ? ['crypto', 'metals'] : [$group] as $selected) {
            if ($this->option('status')) {
                try {
                    $state = $cache->read($selected);
                    $this->line($selected.': '.json_encode(array_intersect_key($state, array_flip([
                        'period', 'requests', 'http_failures', 'invalid_quotes', 'refreshes',
                        'last_attempt_at', 'last_success_at', 'last_error', 'next_attempt_at', 'blocked_until',
                    ])), JSON_THROW_ON_ERROR));
                } catch (Throwable) {
                    $this->error($selected.': cache_unavailable');
                    $exit = self::FAILURE;
                }

                continue;
            }
            if ($this->option('queued')) {
                if ($markets->isDue($selected)) {
                    RefreshMarketData::dispatch($selected);
                    $this->info($selected.': queued');
                } else {
                    $this->line($selected.': '.($markets->blockReason($selected) ?? 'not_due'));
                }

                continue;
            }
            $result = $markets->refresh($selected);
            $this->line($selected.': '.$result['status'].' ('.$result['updated'].' quotes updated)');
            if (! in_array($result['status'], ['refreshed', 'not_due', 'locked', 'demo'], true)) {
                $exit = self::FAILURE;
            }
        }

        return $exit;
    }
}
