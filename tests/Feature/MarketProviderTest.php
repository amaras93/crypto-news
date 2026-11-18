<?php

namespace Tests\Feature;

use App\Support\CryptoMarketProvider;
use App\Support\MarketDataCache;
use App\Support\MarketHttpClient;
use App\Support\MetalsMarketProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class MarketProviderTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->freezeSecond();
        config([
            'markets.cache_store' => 'array', 'markets.coingecko.tier' => 'demo',
            'markets.coingecko.key' => 'not-a-real-crypto-key',
            'markets.goldapi.key' => 'not-a-real-metals-key',
        ]);
        Http::preventStrayRequests();
    }

    private function cryptoBody(): array
    {
        return [
            'bitcoin' => ['usd' => 90000, 'usd_24h_change' => 1.25, 'last_updated_at' => now()->subSeconds(20)->timestamp],
            'ethereum' => ['usd' => 3000.5, 'usd_24h_change' => -2.5, 'last_updated_at' => now()->timestamp],
            'solana' => ['usd' => 150, 'usd_24h_change' => 0, 'last_updated_at' => now()->timestamp],
            'binancecoin' => ['usd' => 600, 'usd_24h_change' => null, 'last_updated_at' => now()->timestamp],
            'ripple' => ['usd' => 0.5, 'usd_24h_change' => 1, 'last_updated_at' => now()->timestamp],
            'cardano' => ['usd' => 0.3, 'usd_24h_change' => 1, 'last_updated_at' => now()->timestamp],
            'dogecoin' => ['usd' => 0.1, 'usd_24h_change' => 1, 'last_updated_at' => now()->timestamp],
            'tron' => ['usd' => 0.2, 'usd_24h_change' => 1, 'last_updated_at' => now()->timestamp],
            'avalanche-2' => ['usd' => 20, 'usd_24h_change' => 1, 'last_updated_at' => now()->timestamp],
            'chainlink' => ['usd' => 10, 'usd_24h_change' => 1, 'last_updated_at' => now()->timestamp],
            'the-open-network' => ['usd' => 4, 'usd_24h_change' => 1, 'last_updated_at' => now()->timestamp],
            'polkadot' => ['usd' => 6, 'usd_24h_change' => 1, 'last_updated_at' => now()->timestamp],
            'litecoin' => ['usd' => 75, 'usd_24h_change' => 1, 'last_updated_at' => now()->timestamp],
            'bitcoin-cash' => ['usd' => 300, 'usd_24h_change' => 1, 'last_updated_at' => now()->timestamp],
            'stellar' => ['usd' => 0.15, 'usd_24h_change' => 1, 'last_updated_at' => now()->timestamp],
            'near' => ['usd' => 3, 'usd_24h_change' => 1, 'last_updated_at' => now()->timestamp],
            'uniswap' => ['usd' => 5, 'usd_24h_change' => 1, 'last_updated_at' => now()->timestamp],
            'sui' => ['usd' => 2, 'usd_24h_change' => 1, 'last_updated_at' => now()->timestamp],
            'aptos' => ['usd' => 7, 'usd_24h_change' => 1, 'last_updated_at' => now()->timestamp],
            'ethereum-classic' => ['usd' => 20, 'usd_24h_change' => 1, 'last_updated_at' => now()->timestamp],
        ];
    }

    private function metalBody(string $symbol): array
    {
        return ['symbol' => $symbol, 'currency' => 'USD', 'unit' => $symbol === 'HG' ? 'lb' : 'oz', 'price' => $symbol === 'XAU' ? 2600.5 : 30.5,
            'updatedAt' => now()->timezone('Asia/Yerevan')->toIso8601String(), 'prevClose' => 2500, 'chPercent' => 4.02];
    }

    public function test_crypto_is_batched_authenticated_and_normalized_with_real_changes(): void
    {
        Http::fake(['*' => Http::response($this->cryptoBody())]);
        $result = app(CryptoMarketProvider::class)->fetch();

        $this->assertNull($result['error']);
        $this->assertCount(20, $result['quotes']);
        $this->assertSame(90000.0, $result['quotes']['bitcoin']->price);
        $this->assertSame('BTC', $result['quotes']['bitcoin']->symbol);
        $this->assertSame(-2.5, $result['quotes']['ethereum']->change24h);
        $this->assertSame(0.0, $result['quotes']['solana']->change24h);
        $this->assertNull($result['quotes']['bnb']->change24h);
        $this->assertSame(now()->timestamp - 20, $result['quotes']['bitcoin']->providerTimestamp);
        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request) => str_starts_with($request->url(), 'https://api.coingecko.com/api/v3/simple/price?')
            && $request->hasHeader('x-cg-demo-api-key', 'not-a-real-crypto-key')
            && $request['ids'] === 'bitcoin,ethereum,solana,binancecoin,ripple,cardano,dogecoin,tron,avalanche-2,chainlink,the-open-network,polkadot,litecoin,bitcoin-cash,stellar,near,uniswap,sui,aptos,ethereum-classic'
            && $request['vs_currencies'] === 'usd'
            && $request['include_last_updated_at'] === 'true'
            && $request['include_24hr_change'] === 'true'
            && ! str_contains($request->url(), 'not-a-real-crypto-key'));
    }

    public function test_paid_configuration_uses_the_pro_host_and_header_only(): void
    {
        config(['markets.coingecko.tier' => 'pro']);
        Http::fake(['*' => Http::response($this->cryptoBody())]);

        $this->assertNull(app(CryptoMarketProvider::class)->fetch()['error']);
        Http::assertSent(fn (Request $request) => str_starts_with($request->url(), 'https://pro-api.coingecko.com/api/v3/simple/price?')
            && $request->hasHeader('x-cg-pro-api-key') && ! $request->hasHeader('x-cg-demo-api-key'));
    }

    public function test_metals_validate_units_and_do_not_mislabel_previous_close_as_24_hour_change(): void
    {
        Http::fake(fn (Request $request) => Http::response($this->metalBody(basename(dirname(parse_url($request->url(), PHP_URL_PATH))))));
        $result = app(MetalsMarketProvider::class)->fetch();

        $this->assertNull($result['error']);
        $this->assertCount(5, $result['quotes']);
        $this->assertSame(2600.5, $result['quotes']['gold']->price);
        $this->assertSame(30.5, $result['quotes']['silver']->price);
        foreach ($result['quotes'] as $quote) {
            $this->assertSame('USD', $quote->currency);
            $this->assertSame($quote->asset === 'copper' ? 'pound' : 'troy_ounce', $quote->unit);
            $this->assertNull($quote->change24h);
            $this->assertSame(now()->timestamp, $quote->providerTimestamp);
        }
        Http::assertSentCount(5);
        foreach (['XAU', 'XAG', 'XPT', 'XPD', 'HG'] as $symbol) {
            Http::assertSent(fn (Request $request) => $request->url() === 'https://goldapi.dev/api/v1/price/'.$symbol.'/USD'
                && $request->hasHeader('X-API-Key', 'not-a-real-metals-key'));
        }
        Http::assertSent(fn (Request $request) => $request->url() === 'https://goldapi.dev/api/v1/price/XAG/USD'
            && $request->hasHeader('X-API-Key', 'not-a-real-metals-key'));
    }

    #[DataProvider('badCryptoFields')]
    public function test_invalid_crypto_quotes_are_rejected_without_losing_valid_assets(string $field, mixed $value): void
    {
        $body = $this->cryptoBody();
        $body['bitcoin'][$field] = $value === 'future' ? now()->addDay()->timestamp : $value;
        Http::fake(['*' => Http::response($body)]);
        $result = app(CryptoMarketProvider::class)->fetch();

        $this->assertSame('invalid_response', $result['error']);
        $this->assertArrayNotHasKey('bitcoin', $result['quotes']);
        $this->assertCount(19, $result['quotes']);
    }

    public static function badCryptoFields(): array
    {
        return [
            'negative' => ['usd', -1], 'zero' => ['usd', 0], 'numeric string' => ['usd', '123.45'],
            'boolean' => ['usd', true], 'array' => ['usd', [123]], 'missing' => ['usd', null],
            'timestamp string' => ['last_updated_at', '1700000000'],
            'timestamp zero' => ['last_updated_at', 0], 'future' => ['last_updated_at', 'future'],
        ];
    }

    #[DataProvider('badMetalFields')]
    public function test_malformed_metal_responses_cannot_be_shown_as_valid_spot_quotes(string $field, mixed $value): void
    {
        Http::fake(function (Request $request) use ($field, $value) {
            $body = $this->metalBody(basename(dirname(parse_url($request->url(), PHP_URL_PATH))));
            $body[$field] = $value === 'future' ? now()->addDay()->toIso8601String() : $value;

            return Http::response($body);
        });
        $result = app(MetalsMarketProvider::class)->fetch();
        $this->assertSame([], $result['quotes']);
        $this->assertSame('invalid_response', $result['error']);
    }

    public static function badMetalFields(): array
    {
        return [
            'currency' => ['currency', 'EUR'], 'unit' => ['unit', 'gram'], 'symbol' => ['symbol', 'UNKNOWN'],
            'negative price' => ['price', -10], 'string price' => ['price', '2600'], 'missing price' => ['price', null],
            'timestamp absent' => ['updatedAt', null], 'timestamp without timezone' => ['updatedAt', '2026-11-16T10:00:00'],
            'invalid calendar date' => ['updatedAt', '2026-02-30T10:00:00Z'], 'future' => ['updatedAt', 'future'],
        ];
    }

    #[DataProvider('incorrectMetalUnits')]
    public function test_wrong_units_reject_only_the_affected_metal(string $symbol, string $unit, string $asset): void
    {
        Http::fake(function (Request $request) use ($symbol, $unit) {
            $requestedSymbol = basename(dirname(parse_url($request->url(), PHP_URL_PATH)));
            $body = $this->metalBody($requestedSymbol);
            if ($requestedSymbol === $symbol) {
                $body['unit'] = $unit;
            }

            return Http::response($body);
        });
        $result = app(MetalsMarketProvider::class)->fetch();
        $this->assertSame('invalid_response', $result['error']);
        $this->assertCount(4, $result['quotes']);
        $this->assertArrayNotHasKey($asset, $result['quotes']);
        Http::assertSentCount(5);
    }

    public static function incorrectMetalUnits(): array
    {
        return [
            'gold pound' => ['XAU', 'lb', 'gold'], 'silver pound' => ['XAG', 'lb', 'silver'],
            'platinum pound' => ['XPT', 'lb', 'platinum'], 'palladium pound' => ['XPD', 'lb', 'palladium'],
            'copper ounce' => ['HG', 'oz', 'copper'],
        ];
    }

    #[DataProvider('providerErrors')]
    public function test_http_failures_are_sanitized_and_retries_are_bounded(string $group, int $status, string $error, int $requests): void
    {
        Http::fake(['*' => Http::response(['error' => 'private-provider-response-not-for-display'], $status, ['Retry-After' => '3600'])]);
        $result = app($group === 'crypto' ? CryptoMarketProvider::class : MetalsMarketProvider::class)->fetch();

        $this->assertSame([], $result['quotes']);
        $this->assertSame($error, $result['error']);
        Http::assertSentCount($requests);
        $this->assertSame($requests, app(MarketDataCache::class)->read($group)['http_failures']);
    }

    public static function providerErrors(): array
    {
        $cases = [];
        foreach (['crypto', 'metals'] as $group) {
            foreach ([401, 403, 429, 500] as $status) {
                $cases[$group.' '.$status] = [$group, $status, match ($status) {
                    401, 403 => 'authentication_failed', 429 => 'rate_limited', default => 'http_error',
                }, $status === 500 ? ($group === 'crypto' ? 2 : 10) : 1];
            }
        }

        return $cases;
    }

    #[DataProvider('providerGroups')]
    public function test_connection_timeouts_are_not_thrown_or_retried_without_limit(string $group): void
    {
        Http::fake(['*' => Http::failedConnection('Secret diagnostic that must stay private')]);
        $result = app($group === 'crypto' ? CryptoMarketProvider::class : MetalsMarketProvider::class)->fetch();

        $this->assertSame('network_error', $result['error']);
        $this->assertSame([], $result['quotes']);
        Http::assertSentCount($group === 'crypto' ? 2 : 10);
    }

    #[DataProvider('providerGroups')]
    public function test_missing_keys_fail_before_any_outbound_request(string $group): void
    {
        config([$group === 'crypto' ? 'markets.coingecko.key' : 'markets.goldapi.key' => '']);
        $this->assertSame('missing_credentials', app($group === 'crypto' ? CryptoMarketProvider::class : MetalsMarketProvider::class)->fetch()['error']);
        Http::assertNothingSent();
    }

    public static function providerGroups(): array
    {
        return ['crypto' => ['crypto'], 'metals' => ['metals']];
    }

    public function test_malformed_json_and_untrusted_hosts_or_credentials_are_rejected(): void
    {
        Http::fake(['*' => Http::response('{"invalid":', 200)]);
        $this->assertSame('invalid_response', app(CryptoMarketProvider::class)->fetch()['error']);
        Http::assertSentCount(1);
        $client = app(MarketHttpClient::class);
        $this->assertSame('invalid_configuration', $client->get('crypto', 'https://attacker.invalid/quotes', 'x-cg-demo-api-key', 'dummy')['error']);
        $this->assertSame('invalid_configuration', $client->get('crypto', 'http://api.coingecko.com/api/v3/simple/price', 'x-cg-demo-api-key', 'dummy')['error']);
        $this->assertSame('invalid_configuration', $client->get('crypto', 'https://api.coingecko.com/api/v3/simple/price', 'x-cg-demo-api-key', "key\r\nInjected: value")['error']);
        Http::assertSentCount(1);
    }

    public function test_missing_or_invalid_changes_do_not_invalidate_a_real_price(): void
    {
        $body = $this->cryptoBody();
        unset($body['bitcoin']['usd_24h_change']);
        $body['ethereum']['usd_24h_change'] = 'fake';
        Http::fake(['*' => Http::response($body)]);
        $result = app(CryptoMarketProvider::class)->fetch();

        $this->assertNull($result['error']);
        $this->assertNull($result['quotes']['bitcoin']->change24h);
        $this->assertNull($result['quotes']['ethereum']->change24h);
        $this->assertSame(90000.0, $result['quotes']['bitcoin']->price);
    }

    public function test_redirect_responses_are_rejected_without_forwarding_credentials(): void
    {
        Http::fake(['*' => Http::response('', 302, ['Location' => 'https://attacker.invalid/collect'])]);
        $this->assertSame('http_error', app(CryptoMarketProvider::class)->fetch()['error']);
        Http::assertSentCount(1);
        Http::assertNotSent(fn (Request $request) => str_contains($request->url(), 'attacker.invalid'));
    }
}
