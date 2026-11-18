<?php

namespace App\Jobs;

use App\Support\NewsDigests;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class BuildNewsDigests implements ShouldBeUnique, ShouldQueue
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
        return 'BuildNewsDigests';
    }

    public function handle(NewsDigests $service): void
    {
        $service->queueScheduled();
    }
}
