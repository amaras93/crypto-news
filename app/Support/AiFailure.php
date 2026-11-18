<?php

namespace App\Support;

use RuntimeException;

class AiFailure extends RuntimeException
{
    public function __construct(public readonly string $reason, public readonly bool $uncertain = false,
        public readonly bool $retryable = false, public readonly int $retrySeconds = 60,
        public readonly ?array $usage = null, public readonly ?string $responseId = null)
    {
        parent::__construct($reason);
    }
}
