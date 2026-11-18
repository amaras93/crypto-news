<?php

namespace App\Support;

use Illuminate\Contracts\Cache\Lock;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Throwable;

class MarketDataCache
{
    public function key(string $group): string
    {
        $tier = $group === 'crypto' ? ':'.(config('markets.coingecko.tier') === 'pro' ? 'pro' : 'demo') : '';

        return 'markets:v1:'.$group.$tier;
    }

    public function lock(string $group): Lock
    {
        return Cache::store(config('markets.cache_store') ?: null)->lock($this->key($group).':refresh', 90);
    }

    /** @return array<string, mixed> */
    public function read(string $group): array
    {
        $payload = Cache::store(config('markets.cache_store') ?: null)->get($this->key($group));
        try {
            $state = is_string($payload) ? json_decode(Crypt::decryptString($payload), true, 32, JSON_THROW_ON_ERROR) : [];
        } catch (Throwable) {
            $state = [];
        }
        $state = is_array($state) ? $state : [];
        $defaults = [
            'quotes' => [], 'next_attempt_at' => 0, 'blocked_until' => 0,
            'last_attempt_at' => null, 'last_success_at' => null, 'last_error' => null,
            'consecutive_failures' => 0, 'period' => now()->utc()->format('Y-m'),
            'requests' => 0, 'http_failures' => 0, 'invalid_quotes' => 0, 'refreshes' => 0,
        ];
        $state = array_replace($defaults, $state);
        if ($state['period'] !== $defaults['period']) {
            foreach (['period', 'requests', 'http_failures', 'invalid_quotes', 'refreshes'] as $field) {
                $state[$field] = $defaults[$field];
            }
        }

        return $state;
    }

    public function save(string $group, array $state): void
    {
        $payload = Crypt::encryptString(json_encode($state, JSON_THROW_ON_ERROR));
        Cache::store(config('markets.cache_store') ?: null)->forever($this->key($group), $payload);
    }

    /** Call only while holding the group refresh lock. */
    public function count(string $group, string $metric): void
    {
        $state = $this->read($group);
        $state[$metric]++;

        $this->save($group, $state);
    }
}
