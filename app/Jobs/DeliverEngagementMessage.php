<?php

namespace App\Jobs;

use App\Support\EngagementDeliveries;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class DeliverEngagementMessage implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 60;

    public int $uniqueFor = 90;

    public function __construct(public string $deliveryId)
    {
        $this->onQueue('engagement');
    }

    public function uniqueId(): string
    {
        return $this->deliveryId;
    }

    public function handle(EngagementDeliveries $service): void
    {
        $service->process($this->deliveryId);
    }

    public function failed(?Throwable $exception): void
    {
        app(EngagementDeliveries::class)->recover($this->deliveryId);
    }
}
