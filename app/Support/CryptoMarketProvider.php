<?php

namespace App\Support;

class CryptoMarketProvider
{
    public function __construct(private MarketHttpClient $http) {}

    /** @return array{quotes: array<string, MarketQuote>, error: ?string} */
    public function fetch(): array
    {
        $tier = config('markets.coingecko.tier');
        $key = config('markets.coingecko.key');
        if (! in_array($tier, ['demo', 'pro'], true)) {
            return ['quotes' => [], 'error' => 'invalid_configuration'];
        }
        if (! MarketHttpClient::validKey($key)) {
            return ['quotes' => [], 'error' => 'missing_credentials'];
        }
        $assets = array_filter(config('markets.assets'), fn (array $asset) => $asset['group'] === 'crypto');
        $result = $this->http->get('crypto',
            $tier === 'pro' ? 'https://pro-api.coingecko.com/api/v3/simple/price' : 'https://api.coingecko.com/api/v3/simple/price',
            $tier === 'pro' ? 'x-cg-pro-api-key' : 'x-cg-demo-api-key',
            $key,
            ['ids' => implode(',', array_column($assets, 'provider_id')), 'vs_currencies' => 'usd', 'include_24hr_change' => 'true', 'include_last_updated_at' => 'true'],
        );
        if ($result['error']) {
            return ['quotes' => [], 'error' => $result['error']];
        }
        $quotes = [];
        $fetchedAt = now()->timestamp;
        foreach ($assets as $asset => $definition) {
            $data = $result['body'][$definition['provider_id']] ?? null;
            if (! is_array($data)) {
                continue;
            }
            $quote = MarketQuote::valid($asset, $data['usd'] ?? null, $data['usd_24h_change'] ?? null, $data['last_updated_at'] ?? null, $fetchedAt, 'coingecko');
            if ($quote) {
                $quotes[$asset] = $quote;
            }
        }

        return ['quotes' => $quotes, 'error' => count($quotes) === count($assets) ? null : 'invalid_response'];
    }
}
