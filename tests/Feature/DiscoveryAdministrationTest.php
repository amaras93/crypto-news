<?php

namespace Tests\Feature;

use App\CandidateStatus;
use App\Filament\Pages\AiNewsControlCenter;
use App\Filament\Resources\IngestionRuns\Pages\ManageIngestionRuns;
use App\Filament\Resources\NewsCandidates\Pages\ManageNewsCandidates;
use App\Filament\Resources\NewsSources\Pages\ManageNewsSources;
use App\IngestionStatus;
use App\Models\DiscoverySetting;
use App\Models\IngestionRun;
use App\Models\NewsCandidate;
use App\Models\NewsSource;
use App\Models\User;
use App\Support\AiDrafting;
use App\Support\CandidateReviewService;
use App\Support\DiscoverySettingsManager;
use App\Support\FeedDnsResolver;
use App\Support\FeedFailure;
use App\Support\NewsDiscovery;
use App\Support\NewsSourceManager;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

class DiscoveryAdministrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Http::preventStrayRequests();
        Queue::fake();
        $this->mock(FeedDnsResolver::class)->shouldReceive('resolve')->andReturn(['93.184.216.34']);
    }

    private function sourceData(): array
    {
        return ['name' => 'Approved fixture publisher', 'website' => 'https://publisher.com/', 'feed_url' => 'https://publisher.com/official/rss',
            'source_type' => 'rss', 'language' => 'en', 'category_id' => null, 'topic' => 'bitcoin', 'priority' => 3,
            'enabled' => false, 'summary_permitted' => false, 'licensing_notes' => 'TEST ONLY: official metadata permission reviewed.',
            'polling_minutes' => 60, 'item_limit' => 25, 'approval_confirmed' => true];
    }

    public function test_administrator_resources_render_and_editors_have_explicit_review_only_access(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin);
        foreach (['/admin/news-sources', '/admin/news-candidates', '/admin/ingestion-runs', '/admin/ai-news-control-center'] as $url) {
            $this->get($url)->assertOk();
        }
        $editor = User::factory()->editor()->create();
        $this->actingAs($editor)->get('/admin/news-candidates')->assertOk();
        $this->get('/admin/ingestion-runs')->assertOk();
        $this->get('/admin/ai-news-control-center')->assertOk()->assertDontSee('Save discovery settings');
        $this->get('/admin/news-sources')->assertForbidden();
        $this->actingAs(User::factory()->create());
        foreach (['/admin/news-sources', '/admin/news-candidates', '/admin/ingestion-runs', '/admin/ai-news-control-center'] as $url) {
            $this->get($url)->assertForbidden();
        }
        Http::assertNothingSent();
    }

    public function test_native_source_create_edit_and_disable_use_authorized_validated_service(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin);
        Livewire::test(ManageNewsSources::class)->callAction('create', data: $this->sourceData())->assertHasNoErrors();
        $source = NewsSource::sole();
        $this->assertSame($admin->id, $source->approved_by);
        $this->assertFalse($source->enabled);
        Livewire::test(ManageNewsSources::class)->callTableAction('edit', $source, data: array_replace($this->sourceData(), ['enabled' => true]))->assertHasNoErrors();
        $this->assertTrue($source->fresh()->enabled);
        $this->assertSame(2, $source->fresh()->version);
        Livewire::test(ManageNewsSources::class)->callTableAction('edit', $source, data: array_replace($this->sourceData(), ['approval_confirmed' => false]))->assertHasNoErrors();
        $this->assertFalse($source->fresh()->enabled);
        $this->assertNull($source->fresh()->approved_at);
        Http::assertNothingSent();
    }

    public function test_source_validation_rejects_duplicate_endpoints_and_unapproved_enablement(): void
    {
        $admin = User::factory()->admin()->create();
        $service = app(NewsSourceManager::class);
        $service->save($admin, $this->sourceData());
        try {
            $service->save($admin, $this->sourceData());
            $this->fail('Duplicate endpoint accepted');
        } catch (ValidationException $error) {
            $this->assertArrayHasKey('feed_url', $error->errors());
        }
        try {
            $service->save($admin, array_replace($this->sourceData(), ['feed_url' => 'https://publisher.com/another', 'enabled' => true, 'approval_confirmed' => false]));
            $this->fail('Unapproved source enabled');
        } catch (ValidationException $error) {
            $this->assertArrayHasKey('approval_confirmed', $error->errors());
        }
        $this->assertDatabaseCount('news_sources', 1);
    }

    public function test_source_changes_invalidate_queued_configurations_and_no_publication_occurs(): void
    {
        $admin = User::factory()->admin()->create();
        $source = app(NewsSourceManager::class)->save($admin, array_replace($this->sourceData(), ['enabled' => true]));
        DiscoverySetting::factory()->create(['enabled' => true]);
        app(NewsDiscovery::class)->queueDue($admin);
        $run = IngestionRun::sole();
        app(NewsSourceManager::class)->save($admin, array_replace($this->sourceData(), ['enabled' => false]), $source);
        app(NewsDiscovery::class)->process($run->id, $run->token);
        $this->assertSame(IngestionStatus::Skipped, $run->fresh()->status);
        $this->assertDatabaseCount('articles', 0);
        Http::assertNothingSent();
    }

    public function test_editors_can_approve_for_future_draft_without_article_or_publication_permission(): void
    {
        $editor = User::factory()->editor()->create();
        $candidate = NewsCandidate::factory()->create();
        $this->actingAs($editor);
        Livewire::test(ManageNewsCandidates::class)->callTableAction('approve_for_draft', $candidate, data: ['notes' => 'Reviewed source context.'])->assertHasNoErrors();
        $this->assertSame(CandidateStatus::ApprovedForDraft, $candidate->fresh()->status);
        $this->assertSame($editor->id, $candidate->fresh()->reviewed_by);
        $this->assertDatabaseCount('candidate_reviews', 1);
        app(CandidateReviewService::class)->transition($editor, $candidate, CandidateStatus::ApprovedForDraft);
        $this->assertDatabaseCount('candidate_reviews', 1);
        $this->assertDatabaseCount('articles', 0);
        $this->get('/admin/users')->assertForbidden();
    }

    public function test_duplicate_overrides_require_notes_and_duplicate_links_cannot_cycle(): void
    {
        $editor = User::factory()->editor()->create();
        $original = NewsCandidate::factory()->create();
        $duplicate = NewsCandidate::factory()->create(['duplicate_of_id' => $original->id, 'status' => CandidateStatus::Duplicate]);
        $service = app(CandidateReviewService::class);
        try {
            $service->transition($editor, $duplicate, CandidateStatus::ApprovedForDraft);
            $this->fail('Unexplained duplicate override accepted');
        } catch (ValidationException $error) {
            $this->assertArrayHasKey('notes', $error->errors());
        }
        $service->transition($editor, $duplicate, CandidateStatus::ApprovedForDraft, 'Independent story confirmed by editor.');
        try {
            $service->transition($editor, $original, CandidateStatus::Duplicate, null, $duplicate->id);
            $this->fail('Forward duplicate cycle permitted');
        } catch (ValidationException $error) {
            $this->assertArrayHasKey('duplicate_of_id', $error->errors());
        }
    }

    public function test_reader_cannot_review_candidates_or_editor_manage_sources(): void
    {
        $candidate = NewsCandidate::factory()->create();
        try {
            app(CandidateReviewService::class)->transition(User::factory()->create(), $candidate, CandidateStatus::ApprovedForDraft);
            $this->fail('Reader reviewed candidate');
        } catch (AuthorizationException) {
            $this->assertSame(CandidateStatus::New, $candidate->fresh()->status);
        }
        $this->expectException(AuthorizationException::class);
        app(NewsSourceManager::class)->save(User::factory()->editor()->create(), $this->sourceData());
    }

    public function test_control_center_settings_manual_run_and_generation_safety(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin);
        NewsSource::factory()->create();
        Livewire::test(AiNewsControlCenter::class)->fillForm(['enabled' => true, 'max_candidates_per_run' => 2, 'max_sources_per_run' => 1, 'default_polling_minutes' => 120])
            ->set('data.generation_enabled', true)->call('save')->assertHasNoErrors()->call('discover')->assertHasNoErrors();
        $this->assertTrue(DiscoverySetting::find(1)->enabled);
        $this->assertSame(2, IngestionRun::sole()->item_limit);
        $this->assertFalse(config('ai.generation_enabled'));
        $this->assertFalse(app(AiDrafting::class)->isOperational());
        Http::assertNothingSent();
    }

    public function test_editor_cannot_invoke_hidden_control_center_write_methods(): void
    {
        $this->actingAs(User::factory()->editor()->create());
        Livewire::test(AiNewsControlCenter::class)->call('save')->assertForbidden();
        Livewire::test(AiNewsControlCenter::class)->call('discover')->assertForbidden();
        $this->assertDatabaseCount('discovery_settings', 0);
    }

    public function test_settings_service_rejects_generation_or_publish_flags(): void
    {
        $this->expectException(ValidationException::class);
        app(DiscoverySettingsManager::class)->save(User::factory()->admin()->create(), [
            'enabled' => false, 'max_candidates_per_run' => 25, 'max_sources_per_run' => 3,
            'default_polling_minutes' => 60, 'generation_enabled' => true, 'automatic_publishing' => true,
        ]);
    }

    public function test_untrusted_admin_display_fields_are_escaped_and_unsafe_links_are_not_clickable(): void
    {
        $candidate = NewsCandidate::factory()->create(['headline' => '<script>legacyAttack()</script>', 'original_url' => 'javascript:alert(1)']);
        $this->actingAs(User::factory()->admin()->create());
        Livewire::test(ManageNewsCandidates::class)->callTableAction('view', $candidate)->assertHasNoErrors()
            ->assertDontSeeHtml('<script>legacyAttack()</script>')->assertDontSeeHtml('href="javascript:alert(1)"');
        Http::assertNothingSent();
    }

    public function test_control_center_statistics_are_real_and_views_do_not_seed_settings(): void
    {
        $admin = User::factory()->admin()->create();
        NewsSource::factory()->create();
        NewsCandidate::factory()->create();
        NewsCandidate::factory()->create(['status' => CandidateStatus::Rejected]);
        $this->actingAs($admin);
        $component = Livewire::test(AiNewsControlCenter::class);
        $stats = $component->instance()->statistics();
        $this->assertSame(1, $stats['Enabled sources']);
        $this->assertSame(1, $stats['Rejected']);
        $this->assertSame(1, $stats['Pending review']);
        $this->assertDatabaseCount('discovery_settings', 0);
    }

    public function test_ingestion_resource_shows_sanitized_errors_and_blocks_editor_retries(): void
    {
        $run = IngestionRun::factory()->create(['status' => IngestionStatus::Failed, 'error_code' => 'network_error']);
        $this->actingAs(User::factory()->editor()->create());
        Livewire::test(ManageIngestionRuns::class)->assertCanSeeTableRecords([$run])->callTableAction('view', $run)->assertSee('network_error');
        $this->expectException(AuthorizationException::class);
        app(NewsDiscovery::class)->retry(auth()->user(), $run);
    }

    public function test_candidate_listing_queries_do_not_grow_with_record_count(): void
    {
        $this->actingAs(User::factory()->admin()->create());
        NewsCandidate::factory()->create();
        DB::enableQueryLog();
        Livewire::test(ManageNewsCandidates::class);
        $small = count(DB::getQueryLog());
        DB::disableQueryLog();
        NewsCandidate::factory()->count(8)->create();
        DB::flushQueryLog();
        DB::enableQueryLog();
        Livewire::test(ManageNewsCandidates::class);
        $large = count(DB::getQueryLog());
        DB::disableQueryLog();
        $this->assertLessThanOrEqual($small + 1, $large);
    }

    public function test_source_security_and_duplicate_review_errors_are_visible_on_native_form_fields(): void
    {
        $this->actingAs(User::factory()->admin()->create());
        Livewire::test(ManageNewsSources::class)->callAction('create', data: array_replace($this->sourceData(), ['feed_url' => 'https://127.0.0.1/rss']))
            ->assertHasFormErrors(['feed_url']);
        $original = NewsCandidate::factory()->create();
        $duplicate = NewsCandidate::factory()->create(['status' => CandidateStatus::Duplicate, 'duplicate_of_id' => $original->id]);
        Livewire::test(ManageNewsCandidates::class)->callTableAction('approve_for_draft', $duplicate, data: ['notes' => ''])
            ->assertHasFormErrors(['notes']);
        $this->assertDatabaseCount('news_sources', 0);
    }

    public function test_disabling_an_unhealthy_source_does_not_require_network_or_dns(): void
    {
        $admin = User::factory()->admin()->create();
        $source = NewsSource::factory()->create();
        $this->mock(FeedDnsResolver::class)->shouldReceive('resolve')->andThrow(new FeedFailure('dns_unavailable'));
        $this->actingAs($admin);
        Livewire::test(ManageNewsSources::class)->callTableAction('disable', $source)->assertHasNoErrors();
        $this->assertFalse($source->fresh()->enabled);
        Http::assertNothingSent();
    }
}
