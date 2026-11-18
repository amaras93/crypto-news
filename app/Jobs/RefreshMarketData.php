<?php

namespace App\Jobs;

use App\Support\MarketDataService;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;

class RefreshMarketData implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 50;

    public int $uniqueFor = 120;

    public function __construct(public readonly string $group)
    {
        if ($group === 'metals') {
            $this->timeout = 85;
        }
    }

    public function uniqueId(): string
    {
        return 'market-refresh:'.$this->group;
    }

    public function uniqueVia(): Repository
    {
        return Cache::store(config('markets.cache_store') ?: null);
    }

    public function handle(MarketDataService $markets): void
    {
        $markets->refresh($this->group);
    }
}
