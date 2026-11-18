<?php

namespace App\Support;

use Carbon\CarbonImmutable;

class AiPricing
{
    /** @return array{model: string, verified_at: string, input: int, cached: int, write: int, output: int} */
    public function snapshot(string $model): array
    {
        $pricing = config('ai.pricing');
        try {
            $date = CarbonImmutable::createFromFormat('!Y-m-d', $pricing['verified_at']);
        } catch (\Throwable) {
            throw new AiFailure('pricing_not_verified');
        }
        if (! $date || $date->isFuture() || $date->lessThan(now()->utc()->subDays(30)) || $pricing['model'] !== $model) {
            throw new AiFailure('pricing_not_verified');
        }
        foreach (['input', 'cached', 'output'] as $field) {
            if (! is_int($pricing[$field]) || $pricing[$field] <= 0 || $pricing[$field] > 1000000000) {
                throw new AiFailure('invalid_pricing');
            }
        }

        return $pricing;
    }

    /**
     * @param  array{input_tokens: int, cached_input_tokens: int, output_tokens: int, cache_write_tokens?: int}  $usage
     * @param  array{input: int, cached: int, output: int, write?: int}  $rates
     */
    public function cost(array $usage, array $rates): int
    {
        $input = $usage['input_tokens'] ?? null;
        $cached = $usage['cached_input_tokens'] ?? null;
        $output = $usage['output_tokens'] ?? null;
        $write = $usage['cache_write_tokens'] ?? 0;
        foreach ([$input, $cached, $output, $write] as $value) {
            if (! is_int($value) || $value < 0 || $value > 1000000) {
                throw new AiFailure('usage_unreliable', true);
            }
        }
        if ($cached + $write > $input || ($write > 0 && ($rates['write'] ?? 0) <= 0)) {
            throw new AiFailure('usage_unreliable', true);
        }

        return $this->component($input - $cached - $write, $rates['input'])
            + $this->component($cached, $rates['cached']) + $this->component($write, $rates['write'] ?? 0)
            + $this->component($output, $rates['output']);
    }

    public function reservation(array $payload, array $schema, array $rates): int
    {
        $upper = strlen(json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR))
            + strlen(json_encode($schema, JSON_THROW_ON_ERROR)) + 2048;
        if ($upper > config('ai.max_input_tokens')) {
            throw new AiFailure('input_limit_exceeded');
        }
        $inputRate = max($rates['input'], $rates['cached'], $rates['write'] ?? 0);

        return max(1, $this->component($upper, $inputRate) + $this->component(config('ai.max_output_tokens'), $rates['output']));
    }

    public function component(int $tokens, int $rate): int
    {
        return intdiv($tokens * $rate + 999999, 1000000);
    }
}
