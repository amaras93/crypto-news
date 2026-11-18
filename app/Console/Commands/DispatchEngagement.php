<?php

namespace App\Console\Commands;

use App\Support\EngagementDeliveries;
use Illuminate\Console\Command;

class DispatchEngagement extends Command
{
    protected $signature = 'engagement:dispatch';

    protected $description = 'Queue bounded pending engagement deliveries; never fetch market data or enable newsletter sending';

    public function handle(EngagementDeliveries $deliveries): int
    {
        $this->info('Pending engagement deliveries queued: '.$deliveries->dispatchPending());

        return self::SUCCESS;
    }
}
