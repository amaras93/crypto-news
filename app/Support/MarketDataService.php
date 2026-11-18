<?php

namespace App\Support;

use Illuminate\Contracts\Cache\Lock;
use Throwable;

class MarketDataService
{
    public function __construct(
        private MarketDataCache $cache,
        private CryptoMarketProvider $crypto,
        private MetalsMarketProvider $metals,
    ) {}

    public function interval(string $group): int
    {
        return max(1, min(1440, (int) config('markets.'.$group.'_refresh_minutes', $group === 'crypto' ? 10 : 30))) * 60;
    }

    public function blockReason(string $group): ?string
    {
        if (config('markets.mode') === 'demo') {
            return 'demo';
        }
        if (config('markets.mode') !== 'live' || ! in_array($group, ['crypto', 'metals'], true)) {
            return 'invalid_configuration';
        }
        if (! config('markets.rights_confirmed')) {
            return 'rights_not_confirmed';
        }
        if ($group === 'crypto' && (! in_array(config('markets.coingecko.tier'), ['demo', 'pro'], true)
            || (app()->environment('production') && config('markets.coingecko.tier') === 'demo'))) {
            return 'license_required';
        }
        $store = config('markets.cache_store') ?: config('cache.default');
        if (app()->environment('production') && ! in_array(config('cache.stores.'.$store.'.driver'), ['database', 'redis'], true)) {
            return 'invalid_cache_store';
        }

        return null;
    }

    public function isDue(string $group): bool
    {
        if ($this->blockReason($group)) {
            return false;
        }
        try {
            $state = $this->cache->read($group);

            return max($state['next_attempt_at'], $state['blocked_until']) <= now()->timestamp;
        } catch (Throwable) {
            return false;
        }
    }

    /** @return array{status: string, updated: int} */
    public function refresh(string $group): array
    {
        if ($reason = $this->blockReason($group)) {
            return ['status' => $reason, 'updated' => 0];
        }
        $lock = null;
        $acquired = false;
        try {
            $lock = $this->cache->lock($group);
            $acquired = $lock->get();
            if (! $acquired) {
                return ['status' => 'locked', 'updated' => 0];
            }
            if (! $this->isDue($group)) {
                return ['status' => 'not_due', 'updated' => 0];
            }
            $startedAt = now()->timestamp;
            $reservation = $this->cache->read($group);
            $reservation['last_attempt_at'] = $startedAt;
            $reservation['next_attempt_at'] = $startedAt + $this->interval($group);
            $this->cache->save($group, $reservation);
            $result = $group === 'crypto' ? $this->crypto->fetch() : $this->metals->fetch();
            $state = $this->cache->read($group);
            $updated = 0;
            foreach ($result['quotes'] as $asset => $quote) {
                $previous = MarketQuote::fromCache($asset, $state['quotes'][$asset] ?? null);
                if ($previous && $quote->providerTimestamp < $previous->providerTimestamp) {
                    $result['error'] = 'out_of_order';

                    continue;
                }
                $state['quotes'][$asset] = $quote->toArray();
                $updated++;
            }
            $state['refreshes']++;
            $state['last_attempt_at'] = $startedAt;
            $state['last_error'] = $result['error'];
            if ($updated > 0) {
                $state['last_success_at'] = now()->timestamp;
            }
            if ($result['error']) {
                $state['consecutive_failures'] = min(20, $state['consecutive_failures'] + 1);
                if ($result['error'] === 'invalid_response') {
                    $state['invalid_quotes'] += count(array_filter(config('markets.assets'), fn (array $asset): bool => $asset['group'] === $group)) - $updated;
                }
            } else {
                $state['consecutive_failures'] = 0;
                $state['blocked_until'] = 0;
            }
            $backoff = $result['error'] ? min(3600, 60 * (2 ** min(6, $state['consecutive_failures']))) : 0;
            if (in_array($result['error'], ['authentication_failed', 'missing_credentials'], true)) {
                $backoff = 3600;
            }
            $state['next_attempt_at'] = max($state['blocked_until'], now()->timestamp + max($this->interval($group), $backoff));
            $this->cache->save($group, $state);

            return ['status' => $result['error'] ? ($updated > 0 ? 'partial' : $result['error']) : 'refreshed', 'updated' => $updated];
        } catch (Throwable) {
            return ['status' => 'cache_or_refresh_unavailable', 'updated' => 0];
        } finally {
            if ($acquired && $lock instanceof Lock) {
                try {
                    $lock->release();
                } catch (Throwable) {
                    // A failed release expires automatically; never log transport exception payloads.
                }
            }
        }
    }

    /**
     * This read path never dispatches work or requests a provider, including on a cold cache.
     *
     * @return array{mode: string, quotes: list<MarketQuote>}
     */
    public function snapshot(): array
    {
        $demoMode = config('markets.mode') === 'demo';
        $states = [];
        if (! $demoMode) {
            foreach (['crypto', 'metals'] as $group) {
                try {
                    $states[$group] = $this->blockReason($group) ? [] : $this->cache->read($group);
                } catch (Throwable) {
                    $states[$group] = [];
                }
            }
        }
        $demo = collect(config('markets.demo'))->keyBy('name');
        $quotes = [];
        foreach (config('markets.assets') as $asset => $definition) {
            if ($demoMode) {
                $quotes[] = $demo->has($asset) ? MarketQuote::demo($asset, $demo->get($asset)) : MarketQuote::unavailable($asset);

                continue;
            }
            $state = $states[$definition['group']] ?? [];
            $quote = MarketQuote::fromCache($asset, $state['quotes'][$asset] ?? null);
            if (! $quote) {
                $quotes[] = MarketQuote::unavailable($asset);

                continue;
            }
            $age = now()->timestamp - min($quote->providerTimestamp, $quote->fetchedAt);
            $interval = $this->interval($definition['group']);
            $staleAfter = $interval * max(2, min(48, (int) config('markets.stale_multiplier', 3)));
            $status = $age > $staleAfter ? 'stale'
                : ($age > $interval + 60 || ($state['last_error'] && $state['last_attempt_at'] > $quote->fetchedAt) ? 'delayed' : 'current');
            $quotes[] = $quote->withStatus($status);
        }

        return ['mode' => $demoMode ? 'demo' : 'live', 'quotes' => $quotes];
    }
}
