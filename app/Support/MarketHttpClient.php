<?php

namespace App\Support;

use DateTimeImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Throwable;

class MarketHttpClient
{
    public function __construct(private MarketDataCache $cache) {}

    public static function validKey(mixed $key): bool
    {
        return is_string($key) && preg_match('/^[\x21-\x7E]{1,512}$/D', $key) === 1;
    }

    /** @return array{body: ?array, error: ?string} */
    public function get(string $group, string $url, string $header, string $key, array $query = []): array
    {
        $allowed = [
            'crypto' => [
                'https://api.coingecko.com/api/v3/simple/price' => 'x-cg-demo-api-key',
                'https://pro-api.coingecko.com/api/v3/simple/price' => 'x-cg-pro-api-key',
            ],
            'metals' => [
                'https://goldapi.dev/api/v1/price/XAU/USD' => 'X-API-Key',
                'https://goldapi.dev/api/v1/price/XAG/USD' => 'X-API-Key',
                'https://goldapi.dev/api/v1/price/XPT/USD' => 'X-API-Key',
                'https://goldapi.dev/api/v1/price/XPD/USD' => 'X-API-Key',
                'https://goldapi.dev/api/v1/price/HG/USD' => 'X-API-Key',
            ],
        ];
        if (($allowed[$group][$url] ?? null) !== $header || ! self::validKey($key)) {
            return ['body' => null, 'error' => 'invalid_configuration'];
        }
        for ($attempt = 0; $attempt < 2; $attempt++) {
            if ($this->cache->read($group)['blocked_until'] > now()->timestamp) {
                return ['body' => null, 'error' => 'rate_limited'];
            }
            $this->cache->count($group, 'requests');
            try {
                $response = Http::acceptJson()->withHeaders([$header => $key])
                    ->connectTimeout(3)->timeout(8)->withoutRedirecting()
                    ->withOptions(['verify' => true])->get($url, $query);
            } catch (ConnectionException) {
                $this->cache->count($group, 'http_failures');
                if ($attempt === 0) {
                    usleep(250000);

                    continue;
                }

                return ['body' => null, 'error' => 'network_error'];
            } catch (Throwable) {
                $this->cache->count($group, 'http_failures');

                return ['body' => null, 'error' => 'transport_error'];
            }
            if ($response->status() === 429) {
                $this->cache->count($group, 'http_failures');
                $state = $this->cache->read($group);
                $state['blocked_until'] = $this->retryAt($response->header('Retry-After'));
                $this->cache->save($group, $state);

                return ['body' => null, 'error' => 'rate_limited'];
            }
            if (! $response->ok()) {
                $this->cache->count($group, 'http_failures');
                if ($response->serverError() && $attempt === 0) {
                    usleep(250000);

                    continue;
                }

                return ['body' => null, 'error' => in_array($response->status(), [401, 403], true) ? 'authentication_failed' : 'http_error'];
            }
            try {
                $body = strlen($response->body()) <= 100000 ? json_decode($response->body(), true, 32, JSON_THROW_ON_ERROR) : null;
            } catch (Throwable) {
                $body = null;
            }

            return is_array($body) && ! array_is_list($body)
                ? ['body' => $body, 'error' => null]
                : ['body' => null, 'error' => 'invalid_response'];
        }

        return ['body' => null, 'error' => 'http_error'];
    }

    private function retryAt(string $header): int
    {
        $now = now()->timestamp;
        if (ctype_digit($header) && strlen($header) <= 9) {
            return $now + max(60, (int) $header);
        }
        $date = DateTimeImmutable::createFromFormat(DATE_RFC7231, $header);

        return $date ? max($now + 60, $date->getTimestamp()) : $now + 3600;
    }
}
