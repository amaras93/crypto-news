<?php

namespace App\Support;

use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

class CommunityLimits
{
    public static function hit(string $action, string $identity, int $attempts, int $seconds): void
    {
        $key = 'community:'.$action.':'.hash('sha256', $identity);
        if (RateLimiter::tooManyAttempts($key, $attempts)) {
            throw ValidationException::withMessages(['community' => __('community.rate_limited', ['seconds' => RateLimiter::availableIn($key)])]);
        }
        RateLimiter::hit($key, $seconds);
    }
}
