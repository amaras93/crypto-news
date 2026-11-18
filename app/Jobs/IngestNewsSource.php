<?php

namespace App\Jobs;

use App\Support\NewsDiscovery;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class IngestNewsSource implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 45;

    public int $uniqueFor = 600;

    public function __construct(public readonly int $runId, public readonly string $token) {}

    public function uniqueId(): string
    {
        return 'news-ingestion:'.$this->runId.':'.$this->token;
    }

    public function uniqueVia(): Repository
    {
        return app(NewsDiscovery::class)->cache();
    }

    public function handle(NewsDiscovery $discovery): void
    {
        $discovery->process($this->runId, $this->token);
    }

    public function failed(?Throwable $exception): void
    {
        app(NewsDiscovery::class)->failed($this->runId, $this->token);
    }
}
