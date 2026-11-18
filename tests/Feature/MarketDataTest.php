<?php

namespace Tests\Feature;

use App\Jobs\RefreshMarketData;
use App\Models\Article;
use App\Models\ArticleTranslation;
use App\Support\CryptoMarketProvider;
use App\Support\MarketDataCache;
use App\Support\MarketDataService;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Number;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class MarketDataTest extends TestCase
{
    use RefreshDatabase;

    private ?string $omittedAsset = null;

    private ?int $sourceTimestamp = null;

    private int $providerStatus = 200;

    private string $retryAfter = '';

    private bool $httpFaked = false;

    protected function setUp(): void
    {
        parent::setUp();
        $this->freezeSecond();
        config([
            'markets.mode' => 'live', 'markets.rights_confirmed' => true,
            'markets.cache_store' => 'array', 'markets.coingecko.tier' => 'demo',
            'markets.coingecko.key' => 'not-a-real-crypto-key', 'markets.goldapi.key' => 'not-a-real-metals-key',
            'markets.crypto_refresh_minutes' => 10, 'markets.metals_refresh_minutes' => 30,
        ]);
        Http::preventStrayRequests();
    }

    private function fakeQuotes(?string $omit = null, ?int $timestamp = null): void
    {
        $this->omittedAsset = $omit;
        $this->sourceTimestamp = $timestamp;
        $this->providerStatus = 200;
        if ($this->httpFaked) {
            return;
        }
        $this->httpFaked = true;
        Http::fake(function (Request $request) {
            if ($this->providerStatus !== 200) {
                return Http::response([], $this->providerStatus, ['Retry-After' => $this->retryAfter]);
            }
            if (str_contains($request->url(), 'coingecko.com')) {
                $body = [];
                foreach (['bitcoin' => 91000, 'ethereum' => 3100, 'solana' => 155, 'binancecoin' => 610, 'ripple' => 0.5, 'cardano' => 0.3, 'dogecoin' => 0.1, 'tron' => 0.2, 'avalanche-2' => 20, 'chainlink' => 10,
                    'the-open-network' => 4, 'polkadot' => 6, 'litecoin' => 75, 'bitcoin-cash' => 300, 'stellar' => 0.15, 'near' => 3, 'uniswap' => 5, 'sui' => 2, 'aptos' => 7, 'ethereum-classic' => 20] as $asset => $price) {
                    if ($asset !== $this->omittedAsset) {
                        $body[$asset] = ['usd' => $price, 'usd_24h_change' => 1.1, 'last_updated_at' => $this->sourceTimestamp ?? now()->timestamp];
                    }
                }

                return Http::response($body);
            }

            $symbol = basename(dirname(parse_url($request->url(), PHP_URL_PATH)));

            return Http::response(['symbol' => $symbol,
                'currency' => 'USD', 'unit' => $symbol === 'HG' ? 'lb' : 'oz', 'price' => $symbol === 'XAU' ? 2700 : 32,
                'updatedAt' => now()->toIso8601String()]);
        });
    }

    public function test_metal_job_budget_covers_five_bounded_requests_without_overlapping_queue_retries(): void
    {
        $metals = new RefreshMarketData('metals');
        $this->assertGreaterThan(5 * (8 * 2 + 0.25), $metals->timeout);
        $this->assertLessThan(90, $metals->timeout);
        $this->assertLessThan(config('queue.connections.database.retry_after'), $metals->timeout);
        $this->assertSame(50, (new RefreshMarketData('crypto'))->timeout);
        $this->assertSame(1, $metals->tries);
    }

    public function test_refresh_intervals_cache_hits_and_partial_replacement_preserve_known_valid_quotes(): void
    {
        $this->fakeQuotes();
        $markets = app(MarketDataService::class);
        $this->assertSame('refreshed', $markets->refresh('crypto')['status']);
        $this->assertSame('refreshed', $markets->refresh('metals')['status']);
        $this->assertSame('not_due', $markets->refresh('crypto')['status']);
        Http::assertSentCount(6);
        $original = $markets->snapshot()['quotes'][0];
        $this->travel(10)->minutes();
        $this->fakeQuotes('bitcoin');
        $this->assertTrue($markets->isDue('crypto'));
        $this->assertFalse($markets->isDue('metals'));
        $this->assertSame('partial', $markets->refresh('crypto')['status']);
        $quotes = collect($markets->snapshot()['quotes'])->keyBy('asset');
        $this->assertSame($original->price, $quotes['bitcoin']->price);
        $this->assertSame($original->fetchedAt, $quotes['bitcoin']->fetchedAt);
        $this->assertSame('delayed', $quotes['bitcoin']->status);
        $this->assertSame('current', $quotes['ethereum']->status);
        $this->assertSame(1, app(MarketDataCache::class)->read('crypto')['invalid_quotes']);
    }

    public function test_failed_refresh_preserves_last_snapshot_and_labels_age_honestly(): void
    {
        $this->fakeQuotes();
        $markets = app(MarketDataService::class);
        $markets->refresh('crypto');
        $fetchedAt = $markets->snapshot()['quotes'][0]->fetchedAt;
        $this->travel(11)->minutes();
        $this->providerStatus = 500;
        $this->assertSame('http_error', $markets->refresh('crypto')['status']);
        $quote = $markets->snapshot()['quotes'][0];
        $this->assertSame(91000.0, $quote->price);
        $this->assertSame($fetchedAt, $quote->fetchedAt);
        $this->assertSame('delayed', $quote->status);
        $this->travel(20)->minutes();
        $this->assertSame('stale', $markets->snapshot()['quotes'][0]->status);
        $this->fakeQuotes();
        $this->assertSame('refreshed', $markets->refresh('crypto')['status']);
        $this->assertSame('current', $markets->snapshot()['quotes'][0]->status);
    }

    public function test_provider_source_age_and_out_of_order_responses_cannot_make_old_data_fresh(): void
    {
        $this->fakeQuotes(timestamp: now()->subHours(2)->timestamp);
        $markets = app(MarketDataService::class);
        $markets->refresh('crypto');
        $this->assertSame('stale', $markets->snapshot()['quotes'][0]->status);
        $originalTimestamp = $markets->snapshot()['quotes'][0]->providerTimestamp;
        $this->travel(10)->minutes();
        $this->fakeQuotes(timestamp: $originalTimestamp - 60);
        $this->assertSame('out_of_order', $markets->refresh('crypto')['status']);
        $this->assertSame($originalTimestamp, $markets->snapshot()['quotes'][0]->providerTimestamp);
    }

    public function test_database_atomic_lock_blocks_duplicate_refreshes(): void
    {
        config(['markets.cache_store' => 'database']);
        $this->fakeQuotes();
        $cache = app(MarketDataCache::class);
        $lock = $cache->lock('crypto');
        $this->assertTrue($lock->get());
        try {
            $this->assertFalse($cache->lock('crypto')->get());
            $this->assertSame('locked', app(MarketDataService::class)->refresh('crypto')['status']);
            Http::assertNothingSent();
        } finally {
            $lock->release();
        }
        $this->assertSame('refreshed', app(MarketDataService::class)->refresh('crypto')['status']);
        Http::assertSentCount(1);
    }

    #[DataProvider('retryAfterFormats')]
    public function test_retry_after_prevents_requests_until_the_provider_cooldown_expires(bool $dateFormat): void
    {
        $retryAt = now()->addHours(2);
        $header = $dateFormat ? $retryAt->format(DATE_RFC7231) : '7200';
        $this->fakeQuotes();
        $this->providerStatus = 429;
        $this->retryAfter = $header;
        $markets = app(MarketDataService::class);
        $this->assertSame('rate_limited', $markets->refresh('metals')['status']);
        $this->assertSame($retryAt->timestamp, app(MarketDataCache::class)->read('metals')['blocked_until']);
        Http::assertSentCount(1);
        $this->travel(90)->minutes();
        $this->assertSame('not_due', $markets->refresh('metals')['status']);
        Http::assertSentCount(1);
        $this->travel(31)->minutes();
        $this->fakeQuotes();
        $this->assertSame('refreshed', $markets->refresh('metals')['status']);
    }

    public static function retryAfterFormats(): array
    {
        return ['seconds' => [false], 'HTTP date' => [true]];
    }

    public function test_authentication_errors_are_cooled_down_and_console_output_contains_no_private_response(): void
    {
        Http::fake(['*' => Http::response(['error' => 'SECRET-provider-payload-not-a-real-crypto-key'], 401)]);
        $this->assertSame(1, Artisan::call('markets:refresh', ['--group' => 'crypto']));
        $output = Artisan::output();
        $this->assertStringContainsString('authentication_failed', $output);
        $this->assertStringNotContainsString('SECRET', $output);
        $this->assertStringNotContainsString('not-a-real-crypto-key', $output);
        $this->travel(30)->minutes();
        $this->assertSame('not_due', app(MarketDataService::class)->refresh('crypto')['status']);
        Http::assertSentCount(1);
    }

    public function test_rights_confirmation_and_production_demo_license_guard_fail_closed(): void
    {
        config(['markets.rights_confirmed' => false]);
        $markets = app(MarketDataService::class);
        $this->assertSame('rights_not_confirmed', $markets->refresh('crypto')['status']);
        $this->assertSame('unavailable', $markets->snapshot()['quotes'][0]->status);
        config(['markets.rights_confirmed' => true]);
        $this->app->instance('env', 'production');
        $this->assertSame('license_required', $markets->refresh('crypto')['status']);
        Http::assertNothingSent();
    }

    public function test_invalid_mode_or_tier_cannot_enable_requests_or_demo_fallback(): void
    {
        $markets = app(MarketDataService::class);
        config(['markets.mode' => 'typo']);
        $this->assertSame('invalid_configuration', $markets->refresh('crypto')['status']);
        $this->assertNull($markets->snapshot()['quotes'][0]->price);
        config(['markets.mode' => 'live', 'markets.coingecko.tier' => 'unknown']);
        $this->assertSame('license_required', $markets->refresh('crypto')['status']);
        Http::assertNothingSent();
    }

    public function test_failed_refresh_execution_keeps_its_pre_request_cost_reservation(): void
    {
        $provider = Mockery::mock(CryptoMarketProvider::class);
        $provider->shouldReceive('fetch')->once()->andThrow(new RuntimeException('Private provider diagnostic'));
        $this->app->instance(CryptoMarketProvider::class, $provider);
        $markets = app(MarketDataService::class);
        $this->assertSame('cache_or_refresh_unavailable', $markets->refresh('crypto')['status']);
        $this->assertFalse($markets->isDue('crypto'));
        $this->assertSame('not_due', $markets->refresh('crypto')['status']);
        $this->assertSame(now()->addMinutes(10)->timestamp, app(MarketDataCache::class)->read('crypto')['next_attempt_at']);
        Http::assertNothingSent();
    }

    public function test_production_requires_a_persistent_shared_cache_driver(): void
    {
        config(['markets.coingecko.tier' => 'pro', 'markets.cache_store' => 'array']);
        $this->app->instance('env', 'production');
        $markets = app(MarketDataService::class);
        $this->assertSame('invalid_cache_store', $markets->refresh('crypto')['status']);
        $this->assertSame('invalid_cache_store', $markets->refresh('metals')['status']);
        $this->assertNull($markets->snapshot()['quotes'][0]->price);
        Http::assertNothingSent();
    }

    public function test_queued_refreshes_are_unique_and_job_payloads_do_not_contain_credentials(): void
    {
        Queue::fake();
        $this->assertSame(0, Artisan::call('markets:refresh', ['--group' => 'crypto', '--queued' => true]));
        $this->assertSame(0, Artisan::call('markets:refresh', ['--group' => 'crypto', '--queued' => true]));
        Queue::assertPushed(RefreshMarketData::class, 1);
        Queue::assertPushed(RefreshMarketData::class, fn ($job) => $job->group === 'crypto'
            && ! str_contains(serialize($job), 'not-a-real-crypto-key'));
        Http::assertNothingSent();
    }

    public function test_scheduler_only_dispatches_due_live_groups_and_respects_configured_intervals(): void
    {
        config(['markets.crypto_refresh_minutes' => 17, 'markets.metals_refresh_minutes' => 45]);
        $events = collect(app(Schedule::class)->events())->filter(fn ($event) => str_starts_with($event->description ?? '', 'markets:'))->values();
        $this->assertCount(2, $events);
        foreach ($events as $event) {
            $this->assertSame('* * * * *', $event->expression);
            $this->assertTrue($event->filtersPass($this->app));
        }
        config(['markets.mode' => 'demo']);
        foreach ($events as $event) {
            $this->assertFalse($event->filtersPass($this->app));
        }
        config(['markets.mode' => 'live']);
        $this->fakeQuotes();
        $markets = app(MarketDataService::class);
        $markets->refresh('crypto');
        $markets->refresh('metals');
        $this->travel(16)->minutes();
        $this->assertFalse($markets->isDue('crypto'));
        $this->travel(1)->minutes();
        $this->assertTrue($markets->isDue('crypto'));
        $this->assertFalse($markets->isDue('metals'));
    }

    public function test_cache_failure_degrades_to_unavailable_and_never_contacts_a_provider(): void
    {
        $cache = Mockery::mock(MarketDataCache::class);
        $cache->shouldReceive('read')->andThrow(new RuntimeException('Sensitive backend diagnostic'));
        $cache->shouldReceive('lock')->andThrow(new RuntimeException('Sensitive backend diagnostic'));
        $this->app->instance(MarketDataCache::class, $cache);
        $markets = app(MarketDataService::class);
        $this->assertSame('cache_or_refresh_unavailable', $markets->refresh('crypto')['status']);
        foreach ($markets->snapshot()['quotes'] as $quote) {
            $this->assertSame('unavailable', $quote->status);
            $this->assertNull($quote->price);
        }
        Http::assertNothingSent();
    }

    public function test_monthly_usage_counters_roll_over_without_losing_last_quotes(): void
    {
        $this->fakeQuotes();
        $markets = app(MarketDataService::class);
        $markets->refresh('crypto');
        $this->assertSame(1, app(MarketDataCache::class)->read('crypto')['requests']);
        $this->travelTo(now()->addMonthNoOverflow()->startOfMonth()->addHour());
        $state = app(MarketDataCache::class)->read('crypto');
        $this->assertSame(0, $state['requests']);
        $this->assertCount(20, $state['quotes']);
        $markets->refresh('crypto');
        $this->assertSame(1, app(MarketDataCache::class)->read('crypto')['requests']);
    }

    public function test_manual_demo_refresh_and_invalid_groups_never_call_providers(): void
    {
        config(['markets.mode' => 'demo']);
        $this->assertSame(0, Artisan::call('markets:refresh'));
        $this->assertStringContainsString('demo (0 quotes updated)', Artisan::output());
        $this->assertSame(2, Artisan::call('markets:refresh', ['--group' => 'untrusted']));
        Http::assertNothingSent();
    }

    public function test_cached_provider_data_is_encrypted_and_invalid_ciphertext_is_not_rendered(): void
    {
        $this->fakeQuotes();
        $markets = app(MarketDataService::class);
        $markets->refresh('crypto');
        $key = app(MarketDataCache::class)->key('crypto');
        $stored = Cache::store('array')->get($key);
        $this->assertIsString($stored);
        $this->assertStringNotContainsString('91000', $stored);
        $this->assertStringNotContainsString('not-a-real-crypto-key', $stored);
        $this->assertSame(91000.0, $markets->snapshot()['quotes'][0]->price);
        Cache::store('array')->forever($key, 'untrusted-invalid-ciphertext');
        $this->assertNull($markets->snapshot()['quotes'][0]->price);
        $this->assertSame('unavailable', $markets->snapshot()['quotes'][0]->status);
        Http::assertSentCount(1);
    }

    #[DataProvider('locales')]
    public function test_demo_and_live_empty_cache_are_honestly_labeled_without_visitor_api_calls(string $locale): void
    {
        config(['markets.mode' => 'demo']);
        $this->get('/'.$locale)->assertOk()
            ->assertSee(trans('ui.demo', [], $locale))
            ->assertSee(Number::format(97482.60, 2, locale: $locale))
            ->assertSee(trans('market.usd_troy_ounce', [], $locale));
        config(['markets.mode' => 'live']);
        $this->get('/'.$locale)->assertOk()
            ->assertSee(trans('market.unavailable', [], $locale))
            ->assertDontSee(Number::format(97482.60, 2, locale: $locale))
            ->assertDontSee(trans('ui.demo_prices_note', [], $locale));
        Http::assertNothingSent();
    }

    #[DataProvider('locales')]
    public function test_cached_quotes_are_shared_by_public_pages_with_attribution_and_no_extra_requests(string $locale): void
    {
        $this->fakeQuotes();
        $markets = app(MarketDataService::class);
        $markets->refresh('crypto');
        $markets->refresh('metals');
        $article = Article::factory()->published()->create();
        ArticleTranslation::factory()->for($article)->create(['locale' => $locale, 'slug' => 'market-data-reader', 'title' => 'Stored article headline']);
        foreach (['/'.$locale, '/'.$locale.'/news', '/'.$locale.'/news/market-data-reader'] as $path) {
            $this->get($path)->assertOk()
                ->assertSee(Number::format(91000, 2, locale: $locale))
                ->assertSee(trans('market.last_updated', [], $locale))
                ->assertSee('href="https://www.coingecko.com/en/api"', false)
                ->assertSee('href="https://goldapi.dev"', false)
                ->assertSee(trans('market.usd_troy_ounce', [], $locale))
                ->assertSee(trans('market.change_unavailable', [], $locale))
                ->assertDontSee('not-a-real-crypto-key')
                ->assertDontSee('not-a-real-metals-key')
                ->assertDontSee('class="market-spark', false);
        }
        Http::assertSentCount(6);
    }

    public static function locales(): array
    {
        return ['English' => ['en'], 'Russian' => ['ru'], 'French' => ['fr'], 'German' => ['de'], 'Spanish' => ['es'], 'Italian' => ['it'], 'Japanese' => ['ja'], 'Chinese' => ['zh']];
    }
}
