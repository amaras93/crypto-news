<?php

namespace App\Support;

use RuntimeException;

class FeedFailure extends RuntimeException
{
    public function __construct(public readonly string $reason, public readonly int $retrySeconds = 3600)
    {
        parent::__construct($reason);
    }
}
