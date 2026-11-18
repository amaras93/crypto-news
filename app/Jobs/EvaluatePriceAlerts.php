<?php

namespace App\Jobs;

use App\Support\PriceAlerts;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class EvaluatePriceAlerts implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 60;

    public int $uniqueFor = 90;

    public function __construct()
    {
        $this->onQueue('engagement');
    }

    public function uniqueId(): string
    {
        return 'EvaluatePriceAlerts';
    }

    public function handle(PriceAlerts $service): void
    {
        $service->evaluate();
    }
}
