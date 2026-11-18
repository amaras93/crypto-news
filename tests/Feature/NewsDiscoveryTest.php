<?php

namespace Tests\Feature;

use App\CandidateStatus;
use App\IngestionStatus;
use App\Jobs\IngestNewsSource;
use App\Models\DiscoverySetting;
use App\Models\IngestionRun;
use App\Models\NewsCandidate;
use App\Models\NewsSource;
use App\Models\User;
use App\Support\DiscoverySettingsManager;
use App\Support\FeedDnsResolver;
use App\Support\NewsCandidateStore;
use App\Support\NewsDiscovery;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class NewsDiscoveryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-11-17 08:00:00 UTC'));
        Queue::fake();
        Http::preventStrayRequests();
        $this->mock(FeedDnsResolver::class)->shouldReceive('resolve')->andReturn(['93.184.216.34']);
    }

    private function enabled(): void
    {
        DiscoverySetting::factory()->create(['enabled' => true]);
    }

    private function fakeFeed(?string $xml = null): void
    {
        Http::fake(['*' => Http::response($xml ?? file_get_contents(base_path('tests/Fixtures/discovery-rss.xml')), 200, ['Content-Type' => 'application/rss+xml', 'ETag' => '"fixture-1"'])]);
    }

    private function process(NewsSource $source): IngestionRun
    {
        $this->travel(61)->seconds();
        $this->assertSame(1, app(NewsDiscovery::class)->queueDue(sourceId: $source->id));
        $run = $source->runs()->latest('id')->firstOrFail();
        app(NewsDiscovery::class)->process($run->id, $run->token);

        return $run->fresh();
    }

    public function test_safe_defaults_dispatch_nothing_and_never_create_articles(): void
    {
        NewsSource::factory()->create();
        $this->assertFalse(app(DiscoverySettingsManager::class)->enabled());
        $this->assertSame(0, app(NewsDiscovery::class)->queueDue());
        $this->artisan('news:discover')->assertSuccessful();
        $this->artisan('news:status')->expectsOutput('Discovery: disabled')->assertSuccessful();
        Queue::assertNothingPushed();
        Http::assertNothingSent();
        $this->assertDatabaseCount('articles', 0);
        $this->assertDatabaseCount('ingestion_runs', 0);
        $this->assertDatabaseCount('discovery_settings', 0);
    }

    public function test_ingestion_normalizes_candidates_and_does_not_cross_into_editorial_articles(): void
    {
        $this->enabled();
        $source = NewsSource::factory()->create();
        $this->fakeFeed();
        $run = $this->process($source);
        $this->assertSame(IngestionStatus::Succeeded, $run->status);
        $this->assertSame(3, $run->created_count);
        $this->assertDatabaseCount('news_candidates', 3);
        $this->assertDatabaseCount('candidate_source_references', 3);
        $this->assertDatabaseCount('articles', 0);
        $this->assertSame(2, NewsCandidate::where('status', CandidateStatus::New)->count());
        $this->assertSame(1, NewsCandidate::where('status', CandidateStatus::ReviewRequired)->count());
        $this->assertNull(NewsCandidate::first()->summary);
        $this->assertNotNull($source->fresh()->last_success_at);
        $this->assertNull($source->fresh()->active_token);
        Queue::assertPushed(IngestNewsSource::class, 1);
    }

    public function test_repeated_processing_guid_and_normalized_urls_are_idempotent(): void
    {
        $this->enabled();
        $source = NewsSource::factory()->create();
        $this->fakeFeed();
        $run = $this->process($source);
        app(NewsDiscovery::class)->process($run->id, $run->token);
        Http::assertSentCount(1);
        $this->travel(61)->minutes();
        $second = $this->process($source->fresh());
        $this->assertSame(0, $second->created_count);
        $this->assertSame(3, $second->linked_count);
        $this->assertDatabaseCount('news_candidates', 3);
        $this->assertDatabaseCount('candidate_source_references', 3);
    }

    public function test_same_canonical_item_across_sources_keeps_all_source_references(): void
    {
        $this->enabled();
        $one = NewsSource::factory()->create();
        $two = NewsSource::factory()->create(['feed_url' => 'https://feeds.publisher.com/official']);
        $this->fakeFeed();
        $this->process($one);
        $this->process($two);
        $this->assertDatabaseCount('news_candidates', 3);
        $this->assertDatabaseCount('candidate_source_references', 6);
        $this->assertSame(2, NewsCandidate::first()->references()->count());
    }

    public function test_normalized_headline_matches_flag_duplicates_but_do_not_merge_independent_urls(): void
    {
        $this->enabled();
        $one = NewsSource::factory()->create();
        $two = NewsSource::factory()->create(['feed_url' => 'https://feeds.publisher.com/official']);
        $xml = file_get_contents(base_path('tests/Fixtures/discovery-rss.xml'));
        Http::fakeSequence()->push($xml, 200, ['Content-Type' => 'application/rss+xml'])
            ->push(str_replace('/news/fictional-bitcoin?', '/news/alternate-bitcoin?', $xml), 200, ['Content-Type' => 'application/rss+xml']);
        $this->process($one);
        $this->process($two);
        $this->assertDatabaseCount('news_candidates', 4);
        $duplicate = NewsCandidate::where('status', CandidateStatus::Duplicate)->sole();
        $this->assertNotNull($duplicate->duplicate_of_id);
        $this->assertContains('stable_fingerprint', $duplicate->duplicate_reasons);
        $this->assertDatabaseCount('candidate_source_references', 6);
    }

    public function test_polling_and_dispatch_locks_prevent_queue_flooding(): void
    {
        $this->enabled();
        NewsSource::factory()->count(6)->create();
        $service = app(NewsDiscovery::class);
        $lock = $service->cache()->lock('discovery:dispatcher', 30);
        $lock->get();
        $this->assertSame(0, $service->queueDue());
        $lock->release();
        $this->assertSame(3, $service->queueDue());
        $this->assertSame(0, $service->queueDue());
        $this->travel(61)->seconds();
        $this->assertSame(3, $service->queueDue());
        $this->assertSame(0, $service->queueDue());
        Queue::assertPushed(IngestNewsSource::class, 6);
        $this->assertDatabaseCount('ingestion_runs', 6);
        Http::assertNothingSent();
    }

    public function test_source_locks_prevent_overlapping_fetches(): void
    {
        $this->enabled();
        $source = NewsSource::factory()->create();
        $service = app(NewsDiscovery::class);
        $service->queueDue();
        $run = IngestionRun::sole();
        $lock = $service->cache()->lock('discovery:source:'.$source->id, 90);
        $lock->get();
        $service->process($run->id, $run->token);
        Http::assertNothingSent();
        $lock->release();
        $this->fakeFeed();
        $service->process($run->id, $run->token);
        $this->assertSame(IngestionStatus::Succeeded, $run->fresh()->status);
    }

    public function test_publisher_host_cooldown_applies_across_different_feed_urls(): void
    {
        $this->enabled();
        $one = NewsSource::factory()->create();
        $two = NewsSource::factory()->create();
        $this->fakeFeed();
        $service = app(NewsDiscovery::class);
        $this->assertSame(2, $service->queueDue());
        $firstRun = $one->runs()->sole();
        $secondRun = $two->runs()->sole();
        $service->process($firstRun->id, $firstRun->token);
        $service->process($secondRun->id, $secondRun->token);
        $run = $secondRun->fresh();
        $this->assertSame(IngestionStatus::Failed, $run->status);
        $this->assertSame('host_cooldown', $run->error_code);
        Http::assertSentCount(1);
    }

    public function test_retry_respects_cooldown_caps_attempts_and_rejects_old_queue_tokens(): void
    {
        $this->enabled();
        $source = NewsSource::factory()->create();
        Http::fake(['*' => Http::response('', 500)]);
        $run = $this->process($source);
        $service = app(NewsDiscovery::class);
        $admin = User::factory()->admin()->create();
        $oldToken = $run->token;
        $this->assertFalse($service->retry($admin, $run));
        for ($attempt = 2; $attempt <= 3; $attempt++) {
            $this->travel(61)->minutes();
            $this->assertTrue($service->retry($admin, $run->fresh()));
            $run->refresh();
            $service->process($run->id, $oldToken);
            $this->assertSame(IngestionStatus::Queued, $run->fresh()->status);
            $service->process($run->id, $run->token);
            $run->refresh();
            $this->assertSame($attempt, $run->attempts);
            $this->assertSame(IngestionStatus::Failed, $run->status);
        }
        $this->travel(61)->minutes();
        $this->assertFalse($service->retry($admin, $run));
        $this->assertSame(0, $service->queueDue());
        $this->assertSame(3, $source->fresh()->failure_count);
    }

    public function test_disabling_discovery_after_dispatch_stops_queued_network_requests(): void
    {
        $this->enabled();
        NewsSource::factory()->create();
        $service = app(NewsDiscovery::class);
        $service->queueDue();
        $run = IngestionRun::sole();
        DiscoverySetting::find(1)->update(['enabled' => false]);
        $service->process($run->id, $run->token);
        $this->assertSame(IngestionStatus::Skipped, $run->fresh()->status);
        Http::assertNothingSent();
    }

    public function test_revoked_source_approval_stops_fetches(): void
    {
        $this->enabled();
        $source = NewsSource::factory()->create();
        $service = app(NewsDiscovery::class);
        $service->queueDue();
        $run = IngestionRun::sole();
        $source->approver->forceFill(['is_admin' => false])->save();
        $service->process($run->id, $run->token);
        Http::assertNothingSent();
        $this->assertSame(IngestionStatus::Skipped, $run->fresh()->status);
    }

    public function test_invalid_entries_are_counted_without_storing_unsafe_urls(): void
    {
        $this->enabled();
        $source = NewsSource::factory()->create();
        $xml = str_replace('https://publisher.com/news/fictional-bitcoin?', 'https://127.0.0.1/private?', file_get_contents(base_path('tests/Fixtures/discovery-rss.xml')));
        $this->fakeFeed($xml);
        $run = $this->process($source);
        $this->assertSame(1, $run->invalid_count);
        $this->assertSame(2, $run->created_count);
        $this->assertSame(1, $run->item_errors['unsafe_host']);
    }

    public function test_candidate_storage_failures_roll_back_the_run_without_leaking_error_payloads(): void
    {
        $this->enabled();
        $source = NewsSource::factory()->create();
        $this->fakeFeed();
        $this->mock(NewsCandidateStore::class)->shouldReceive('store')->andThrow(new \RuntimeException("UNTRUSTED secret\nlog injection"));
        $run = $this->process($source);
        $this->assertSame(IngestionStatus::Failed, $run->status);
        $this->assertSame('processing_error', $run->error_code);
        $this->assertDatabaseCount('news_candidates', 0);
        $this->assertDatabaseCount('candidate_source_references', 0);
    }

    public function test_not_modified_responses_are_successful_without_reprocessing(): void
    {
        $this->enabled();
        $source = NewsSource::factory()->create(['etag' => '"prior"']);
        Http::fake(['*' => Http::response('', 304)]);
        $run = $this->process($source);
        $this->assertSame(IngestionStatus::Succeeded, $run->status);
        $this->assertSame(0, $run->items_seen);
        $this->assertSame('"prior"', $source->fresh()->etag);
        $this->assertDatabaseCount('news_candidates', 0);
    }

    public function test_very_long_provider_cooldowns_disable_polling_instead_of_retrying_early(): void
    {
        $this->enabled();
        $source = NewsSource::factory()->create();
        Http::fake(['*' => Http::response('', 429, ['Retry-After' => '864000'])]);
        $run = $this->process($source);
        $this->assertSame('provider_polling_blocked', $run->error_code);
        $this->assertFalse($source->fresh()->enabled);
        $this->travel(2)->days();
        $this->assertSame(0, app(NewsDiscovery::class)->queueDue());
        Http::assertSentCount(1);
    }

    public function test_scheduler_is_conservative_and_disabled_condition_is_effective(): void
    {
        $events = collect(app(Schedule::class)->events());
        $event = $events->first(fn ($event): bool => str_contains($event->command ?? '', 'news:discover'));
        $this->assertNotNull($event);
        $this->assertSame('*/15 * * * *', $event->expression);
        $this->assertTrue($event->withoutOverlapping);
        $this->assertFalse($event->filtersPass($this->app));
        $this->assertSame(1, (new IngestNewsSource(1, 'test'))->tries);
        $this->assertLessThan(90, (new IngestNewsSource(1, 'test'))->timeout);
    }

    public function test_offline_demo_is_deterministic_and_does_not_seed_sources_or_candidates(): void
    {
        $this->artisan('news:demo')->expectsOutput('No Article, candidate, source or AI request was created.')->assertSuccessful();
        Http::assertNothingSent();
        Queue::assertNothingPushed();
        $this->assertDatabaseCount('news_sources', 0);
        $this->assertDatabaseCount('news_candidates', 0);
        $this->assertDatabaseCount('articles', 0);
    }

    public function test_shared_crypto_mentions_do_not_make_independent_stories_duplicates(): void
    {
        $this->enabled();
        $one = NewsSource::factory()->create();
        $two = NewsSource::factory()->create(['feed_url' => 'https://feeds.publisher.com/official']);
        $xml = file_get_contents(base_path('tests/Fixtures/discovery-rss.xml'));
        $different = str_replace([
            'TEST FIXTURE: Bitcoin research examines fictional long term custody trends', '/news/fictional-bitcoin?',
        ], ['TEST FIXTURE: Bitcoin developers publish an independent technical network upgrade proposal', '/news/independent-bitcoin?'], $xml);
        Http::fakeSequence()->push($xml, 200, ['Content-Type' => 'application/rss+xml'])
            ->push($different, 200, ['Content-Type' => 'application/rss+xml']);
        $this->process($one);
        $this->process($two);
        $this->assertDatabaseCount('news_candidates', 4);
        $this->assertSame(0, NewsCandidate::where('status', CandidateStatus::Duplicate)->count());
    }

    public function test_item_limits_apply_to_stored_candidates_and_public_visits_do_not_dispatch_discovery(): void
    {
        $this->enabled();
        $source = NewsSource::factory()->create(['item_limit' => 1]);
        $this->fakeFeed();
        $run = $this->process($source);
        $this->assertSame(1, $run->items_seen);
        $this->assertSame(1, $run->created_count);
        Http::assertSentCount(1);
        Queue::assertPushed(IngestNewsSource::class, 1);
        foreach (array_keys(config('news.locales')) as $locale) {
            $this->get('/'.$locale)->assertOk();
            $this->get('/'.$locale.'/news')->assertOk();
        }
        Http::assertSentCount(1);
        Queue::assertPushed(IngestNewsSource::class, 1);
        $this->assertDatabaseCount('articles', 0);
    }
}
