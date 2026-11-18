<?php

namespace App\Support;

class MetalsMarketProvider
{
    public function __construct(private MarketHttpClient $http) {}

    /** @return array{quotes: array<string, MarketQuote>, error: ?string} */
    public function fetch(): array
    {
        $key = config('markets.goldapi.key');
        if (! MarketHttpClient::validKey($key)) {
            return ['quotes' => [], 'error' => 'missing_credentials'];
        }
        $quotes = [];
        $error = null;
        foreach (['gold' => 'XAU', 'silver' => 'XAG', 'platinum' => 'XPT', 'palladium' => 'XPD', 'copper' => 'HG'] as $asset => $symbol) {
            $result = $this->http->get('metals', 'https://goldapi.dev/api/v1/price/'.$symbol.'/USD', 'X-API-Key', $key);
            if ($result['error']) {
                $error = $result['error'];
                if (in_array($error, ['authentication_failed', 'rate_limited'], true)) {
                    break;
                }

                continue;
            }
            $data = $result['body'];
            $expectedUnit = $asset === 'copper' ? 'lb' : 'oz';
            if (($data['symbol'] ?? null) !== $symbol || ($data['currency'] ?? null) !== 'USD' || ($data['unit'] ?? null) !== $expectedUnit) {
                $error = 'invalid_response';

                continue;
            }
            // The provider's previous-close percentage is not a rolling 24-hour comparison.
            $quote = MarketQuote::valid($asset, $data['price'] ?? null, null, MarketQuote::isoTimestamp($data['updatedAt'] ?? null), now()->timestamp, 'goldapi');
            if ($quote) {
                $quotes[$asset] = $quote;
            } else {
                $error = 'invalid_response';
            }
        }

        return ['quotes' => $quotes, 'error' => $error];
    }
}
