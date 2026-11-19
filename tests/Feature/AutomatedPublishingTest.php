<?php

namespace Tests\Feature;

use App\AiRunStatus;
use App\ArticleStatus;
use App\CandidateStatus;
use App\Filament\Pages\AiNewsControlCenter;
use App\Jobs\StoreAiDraft;
use App\Jobs\ValidateAiDraft;
use App\Jobs\VerifyNewsCandidate;
use App\Models\AiBudgetPeriod;
use App\Models\AiGenerationRun;
use App\Models\AiPipelineSetting;
use App\Models\Article;
use App\Models\CandidateEvidence;
use App\Models\Category;
use App\Models\CategoryTranslation;
use App\Models\DiscoverySetting;
use App\Models\EditorialEvent;
use App\Models\NewsCandidate;
use App\Support\AiAutomation;
use App\Support\AiDrafting;
use App\Support\AiEditorialReviewService;
use App\Support\AiEvidence;
use App\Support\AiFailure;
use App\Support\AiSettings;
use App\Support\ArticleEditor;
use App\Support\ArticlePublication;
use App\Support\FakeAiDraftProvider;
use App\Support\FeedDnsResolver;
use App\Support\NewsDiscovery;
use App\Support\PublicUrl;
use Filament\Facades\Filament;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\AiTestCase;

class AutomatedPublishingTest extends AiTestCase
{
    private Category $category;

    private array $claims;

    protected function setUp(): void
    {
        parent::setUp();
        $this->live();
        $this->category = Category::factory()->create();
        foreach (array_keys(config('news.locales')) as $locale) {
            CategoryTranslation::factory()->for($this->category)->create(['locale' => $locale, 'slug' => 'technology', 'name' => 'Technology']);
        }
        $this->candidate->update(['category_id' => $this->category->id, 'topic' => 'technology']);
        $this->claims = CandidateEvidence::where('news_candidate_id', $this->candidate->id)->pluck('claim')->all();
        config(['ai.automatic_publishing' => true, 'ai.scheduled_generation_enabled' => true,
            'ai.daily_limit_microusd' => 1000000, 'ai.monthly_limit_microusd' => 10000000,
            'ai.max_articles_per_day' => 1, 'app.url' => 'https://cryptonews.test']);
        AiPipelineSetting::findOrFail(1)->update(['automatic_publishing' => true, 'scheduled_generation_enabled' => true,
            'updated_by' => $this->admin->id, 'enabled_category_ids' => [$this->category->id],
            'daily_limit_microusd' => 1000000, 'monthly_limit_microusd' => 10000000, 'max_articles_per_day' => 1]);
        $this->candidate->references()->first()->source->forceFill(['primary_material_permitted' => true,
            'category_id' => $this->category->id, 'topic' => 'technology'])->save();
        $this->mock(FeedDnsResolver::class)->shouldReceive('resolve')->andReturn(['93.184.216.34']);
    }

    private function mockResponses(): void
    {
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(['api.openai.com/*' => function (Request $request) {
            $run = AiGenerationRun::where('status', AiRunStatus::Requesting)->latest('id')->firstOrFail();
            $document = $run->task === 'verification' ? ['version' => 'verification-v1', 'supported' => true,
                'faithful_translation' => true, 'original' => true, 'correct_language' => true, 'issues' => []]
                : app(FakeAiDraftProvider::class)->fixture($run);
            if ($run->task !== 'verification') {
                foreach ($document['sections'] as &$section) {
                    foreach ($section['paragraphs'] as &$paragraph) {
                        $paragraph['claim_ids'] = array_column($run->evidence_snapshot['claims'], 'id');
                    }
                }
            }

            return Http::response($this->response($run, $document));
        }]);
    }

    private function html(): string
    {
        $reference = $this->candidate->references()->firstOrFail();

        return '<html><head><meta property="og:title" content="'.e($reference->headline).'">'
            .'<meta property="article:published_time" content="'.$reference->source_published_at->toIso8601String().'"></head><body><article>'
            .implode('', array_map(fn (string $claim): string => '<p>'.e($claim).'</p>', $this->claims)).'</article></body></html>';
    }

    private function draft(): AiGenerationRun
    {
        $this->mockResponses();

        return $this->complete(app(AiDrafting::class)->generate($this->admin, $this->candidate, dispatch: false, automated: true));
    }

    private function drain(): Article
    {
        for ($stage = 0; $stage < 35; $stage++) {
            $this->artisan('ai:dispatch')->assertSuccessful();
            foreach (AiGenerationRun::where('status', AiRunStatus::Queued)->get() as $run) {
                $this->complete($run);
            }
            $article = Article::first();
            if ($article && in_array($article->automation_status, ['published', 'held'], true)) {
                return $article->fresh(['translations', 'aiRun']);
            }
        }
        $this->fail('Automatic workflow failed to reach a terminal state.');
    }

    public function test_a_target_with_leaked_provenance_is_rejected_before_a_paid_verification_request(): void
    {
        $article = $this->draft()->article;
        $translation = $article->translation('en');
        $translation->forceFill(['body' => $translation->body.'<p>'.$article->aiRun->evidence_snapshot['claims'][0]['id'].'</p>'])->save();
        $run = app(AiDrafting::class)->verify($this->admin, $article->fresh(), 'en');
        app(AiDrafting::class)->process($run->id, false);
        $this->assertSame(AiRunStatus::Failed, $run->fresh()->status);
        $this->assertSame('verification_preflight_failed', $run->fresh()->error_code);
        $this->assertSame(0, $run->fresh()->request_count);
        $this->assertNull($run->fresh()->reservation_id);
        Http::assertSentCount(1);
        $this->assertSame(0, Article::published()->count());
    }

    public function test_a_quality_blocked_english_candidate_is_held_without_automatic_regeneration(): void
    {
        $run = app(AiDrafting::class)->generate($this->admin, $this->candidate, dispatch: false, automated: true);
        $document = app(FakeAiDraftProvider::class)->fixture($run);
        $document['headline'] .= ' '.$run->evidence_snapshot['claims'][0]['id'];
        Http::fake(['api.openai.com/*' => Http::response($this->response($run, $document))]);
        app(AiDrafting::class)->process($run->id, false);
        app(AiDrafting::class)->validate($run->id, false);
        $this->assertSame(AiRunStatus::QualityBlocked, $run->fresh()->status);
        $this->assertSame('held', $this->candidate->fresh()->automation_status);
        $this->assertSame('critical_quality_failure', $this->candidate->fresh()->automation_error);
        $this->artisan('ai:dispatch')->assertSuccessful();
        Http::assertSentCount(1);
        $this->assertDatabaseCount('ai_generation_runs', 1);
        $this->assertDatabaseCount('articles', 0);
    }

    public function test_a_corrected_english_draft_still_requires_all_eight_quality_and_semantic_checks(): void
    {
        config(['ai.max_articles_per_day' => 2]);
        AiPipelineSetting::find(1)->update(['max_articles_per_day' => 2]);
        $root = app(AiDrafting::class)->generate($this->admin, $this->candidate, dispatch: false, automated: true);
        $document = app(FakeAiDraftProvider::class)->fixture($root);
        $document['sections'][0]['paragraphs'][0]['text'] = $root->evidence_snapshot['claims'][0]['text'];
        Http::fake(['api.openai.com/*' => Http::response($this->response($root, $document))]);
        app(AiDrafting::class)->process($root->id, false);
        app(AiDrafting::class)->validate($root->id, false);
        $this->assertSame('originality_correction_available', $root->fresh()->error_code);
        $this->mockResponses();
        $article = $this->drain();
        $this->assertSame('published', $article->automation_status);
        $this->assertSame(8, $article->translations()->count());
        $this->assertSame(8, $article->translations()->where('ai_review_status', 'automated')->count());
        $this->assertSame(8, AiGenerationRun::where('task', 'verification')->where('status', AiRunStatus::Stored)->count());
        $this->assertSame(17, AiGenerationRun::count());
        $this->assertSame(1, $root->children()->count());
        $this->assertSame($root->children()->sole()->id, $article->ai_generation_run_id);
        $this->assertSame(2, AiBudgetPeriod::where('period_key', 'like', 'day:%')->sole()->actual_articles);
        $this->assertSame(AiRunStatus::QualityBlocked, $root->fresh()->status);
    }

    public function test_discovery_to_publication_completes_eight_languages_without_human_review(): void
    {
        DiscoverySetting::factory()->create(['enabled' => true]);
        $source = $this->candidate->references()->first()->source;
        $headline = 'Sandbox Exchange technology documentation announces newsroom training exercise';
        $url = 'https://example.org/news/technology-announcement';
        $published = now()->subHour()->toIso8601String();
        $rss = '<rss version="2.0"><channel><title>Test primary publisher</title><item><guid>primary-announcement</guid><title>'.$headline.'</title><link>'.$url.'</link><pubDate>'.$published.'</pubDate></item></channel></rss>';
        Http::fake([$source->feed_url => Http::response($rss, 200, ['Content-Type' => 'application/rss+xml'])]);
        $this->assertSame(1, app(NewsDiscovery::class)->queueDue(sourceId: $source->id));
        $ingestion = $source->runs()->latest('id')->firstOrFail();
        app(NewsDiscovery::class)->process($ingestion->id, $ingestion->token);
        $this->candidate = NewsCandidate::where('original_url', $url)->firstOrFail();
        $this->artisan('ai:dispatch')->assertSuccessful();
        Queue::assertPushed(VerifyNewsCandidate::class);
        Http::fake([$url => Http::response($this->html(), 200, ['Content-Type' => 'text/html'])]);
        app(AiAutomation::class)->verifyCandidate($this->candidate->id);
        $this->assertSame('evidence_verified', $this->candidate->fresh()->automation_status);
        foreach (CandidateEvidence::where('news_candidate_id', $this->candidate->id)->get() as $claim) {
            $this->assertSame('automated_primary', $claim->verification_method);
            $this->assertNull($claim->verified_by);
            $this->assertNull($claim->verified_at);
            $this->assertNotNull($claim->retrieval_metadata['document_sha256']);
        }
        $this->mockResponses();
        $article = $this->drain();
        $this->assertSame(ArticleStatus::Published, $article->status, $article->automation_error ?? '');
        $this->assertCount(8, $article->translations);
        $this->assertSame(8, $article->translations->where('ai_review_status', 'automated')->count());
        $this->assertSame(0, $article->translations->whereNotNull('ai_reviewed_by')->count());
        $this->assertDatabaseCount('ai_editorial_reviews', 0);
        $this->assertNull($article->reviewer_id);
        $this->assertSame(7, AiGenerationRun::where('task', 'translation')->count());
        $this->assertSame(8, AiGenerationRun::where('task', 'verification')->count());
        $this->assertSame(16, AiGenerationRun::where('status', AiRunStatus::Stored)->count());
        $this->assertSame(0, AiBudgetPeriod::sum('reserved_microusd'));
        $this->assertGreaterThan(0, AiBudgetPeriod::sum('actual_microusd'));
        $this->artisan('ai:dispatch')->assertSuccessful();
        $this->assertDatabaseCount('articles', 1);
        $this->assertDatabaseCount('ai_generation_runs', 16);
        $this->assertDatabaseHas('editorial_events', ['article_id' => $article->id, 'action' => 'automatic_published']);
    }

    public function test_published_languages_appear_in_home_list_category_search_sitemap_and_seo(): void
    {
        $this->draft();
        $article = $this->drain();
        $this->assertSame(ArticleStatus::Published, $article->status, $article->automation_error ?? '');
        $sitemap = $this->get('/sitemap-articles-1.xml')->assertOk()->streamedContent();
        foreach ($article->translations as $translation) {
            $path = '/'.$translation->locale;
            $url = app(PublicUrl::class)->article($translation);
            $this->get($path)->assertOk()->assertSee($translation->title);
            $this->get($path.'/news')->assertOk()->assertSee($translation->title);
            $this->get($path.'/news?category='.$article->category_id)->assertOk()->assertSee($translation->title);
            $this->get($path.'/news?q=Sandbox')->assertOk()->assertSee($translation->title);
            $response = $this->get($path.'/news/'.$translation->slug)->assertOk()->assertSee('rel="canonical" href="'.$url.'"', false);
            $document = new \DOMDocument;
            $previous = libxml_use_internal_errors(true);
            $document->loadHTML($response->getContent());
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
            $xpath = new \DOMXPath($document);
            $this->assertSame(1, $xpath->query('//h1')->length);
            $this->assertGreaterThanOrEqual(2, $xpath->query('//*[@id="article-content"]/h2')->length);
            $this->assertSame(0, $xpath->query('//*[@id="article-content"]//h1 | //*[@id="article-content"]//h4')->length);
            $this->assertSame($translation->meta_title.' — CryptoNews', $xpath->evaluate('string(//title)'));
            $this->assertSame(Str::limit($translation->meta_description, 160), $xpath->evaluate('string(//meta[@name="description"]/@content)'));
            $this->assertSame($translation->meta_title, $xpath->evaluate('string(//meta[@property="og:title"]/@content)'));
            $this->assertSame($translation->meta_title, $xpath->evaluate('string(//meta[@name="twitter:title"]/@content)'));
            $this->assertSame($url, $xpath->evaluate('string(//meta[@property="og:url"]/@content)'));
            $this->assertSame(9, $xpath->query('//head/link[@hreflang]')->length);
            $this->assertSame(0, $xpath->query('//meta[@name="robots" and contains(@content,"noindex")]')->length);
            $this->assertNotSame('', $xpath->evaluate('string(//figure[contains(@class,"article-hero-image")]/img/@alt)'));
            $schema = json_decode($xpath->query('//script[@type="application/ld+json"]')->item(0)->textContent, true, flags: JSON_THROW_ON_ERROR);
            $this->assertSame('NewsArticle', $schema['@type']);
            $this->assertSame($url, $schema['mainEntityOfPage']['@id']);
            $this->assertSame(config('news.language_tags.'.$translation->locale), $schema['inLanguage']);
            $this->assertSame($article->published_at->toIso8601String(), $schema['datePublished']);
            $this->assertSame($schema['datePublished'], $schema['dateModified']);
            $verification = AiGenerationRun::where('article_id', $article->id)->where('task', 'verification')->where('locale', $translation->locale)->sole();
            $this->assertSame($translation->slug, $verification->input_payload['target']['slug']);
            foreach ($article->translations as $alternate) {
                $response->assertSee(app(PublicUrl::class)->article($alternate), false);
                $this->assertSame(app(PublicUrl::class)->article($alternate), $xpath->evaluate('string(//head/link[@hreflang="'.config('news.language_tags.'.$alternate->locale).'"]/@href)'));
            }
            $this->assertStringContainsString($url, $sitemap);
        }
    }

    public function test_bad_automated_seo_is_blocked_before_semantic_verification_or_publication(): void
    {
        $run = app(AiDrafting::class)->generate($this->admin, $this->candidate, dispatch: false, automated: true);
        $document = app(FakeAiDraftProvider::class)->fixture($run);
        $document['seo_title'] = 'Sandbox Exchange documentation Sandbox Exchange documentation Sandbox Exchange documentation';
        $this->fakeProvider($this->response($run, $document));
        app(AiDrafting::class)->process($run->id, false);
        app(AiDrafting::class)->validate($run->id, false);
        $this->assertSame(AiRunStatus::QualityBlocked, $run->fresh()->status);
        $this->assertContains('seo_repeated_keywords', $run->fresh()->quality_report['critical']);
        $this->assertDatabaseCount('articles', 0);
        $this->assertSame(0, AiGenerationRun::where('task', 'verification')->count());
    }

    public function test_unpublishing_and_editing_pause_automation_and_remove_public_visibility(): void
    {
        $this->draft();
        $article = $this->drain();
        app(ArticlePublication::class)->transition($this->admin, $article, 'unpublish');
        $this->artisan('ai:dispatch')->assertSuccessful();
        $this->assertSame('paused', $article->fresh()->automation_status);
        $this->assertSame(0, Article::published()->count());
        $this->get('/en/news/'.$article->translation('en')->slug)->assertNotFound();
        $this->assertStringNotContainsString($article->translation('en')->slug, $this->get('/sitemap-articles-1.xml')->streamedContent());
        $editor = app(ArticleEditor::class);
        $data = $editor->formData($article);
        $data['translations']['en']['title'] = 'Administrator corrected headline';
        $editor->save($this->admin, $data, $article);
        $this->assertSame('paused', $article->fresh()->automation_status);
        $this->artisan('ai:dispatch')->assertSuccessful();
        $this->assertSame(0, Article::published()->count());
    }

    public function test_missing_evidence_duplicate_stale_and_disabled_categories_never_reach_paid_generation(): void
    {
        CandidateEvidence::where('news_candidate_id', $this->candidate->id)->delete();
        $this->candidate->forceFill(['status' => CandidateStatus::New])->save();
        $source = $this->candidate->references()->first()->source;
        $source->forceFill(['primary_material_permitted' => false])->save();
        app(AiAutomation::class)->verifyCandidate($this->candidate->id);
        $this->assertSame('held', $this->candidate->fresh()->automation_status);
        $this->assertSame('permitted_primary_evidence_unavailable', $this->candidate->fresh()->automation_error);
        $duplicate = NewsCandidate::factory()->create(['category_id' => $this->category->id, 'duplicate_of_id' => $this->candidate->id]);
        app(AiAutomation::class)->verifyCandidate($duplicate->id);
        $this->assertSame('held', $duplicate->fresh()->automation_status);
        $this->candidate->forceFill(['automation_status' => null, 'published_at' => now()->subDays(20)])->save();
        app(AiAutomation::class)->verifyCandidate($this->candidate->id);
        $this->assertSame('candidate_not_eligible_for_automation', $this->candidate->fresh()->automation_error);
        AiPipelineSetting::find(1)->update(['enabled_category_ids' => []]);
        $this->artisan('ai:dispatch')->assertSuccessful();
        $this->assertDatabaseCount('ai_generation_runs', 0);
        Http::assertNothingSent();
    }

    public function test_changed_evidence_and_revoked_permissions_invalidate_automated_verification(): void
    {
        $html = $this->html();
        CandidateEvidence::where('news_candidate_id', $this->candidate->id)->delete();
        $this->candidate->forceFill(['status' => CandidateStatus::New])->save();
        Http::fake([$this->candidate->original_url => Http::response($html, 200, ['Content-Type' => 'text/html'])]);
        app(AiAutomation::class)->verifyCandidate($this->candidate->id);
        $claim = CandidateEvidence::where('news_candidate_id', $this->candidate->id)->firstOrFail();
        $claim->update(['claim' => $claim->claim.' A fabricated addition.']);
        $this->expectException(AiFailure::class);
        app(AiEvidence::class)->snapshot($this->candidate);
    }

    public function test_emergency_stop_prevents_pending_requests_and_publication(): void
    {
        $run = $this->draft();
        app(AiAutomation::class)->advance($run->article, $this->admin);
        $verification = AiGenerationRun::where('task', 'verification')->sole();
        app(AiSettings::class)->stop($this->admin);
        app(AiDrafting::class)->process($verification->id, false);
        $this->assertSame(AiRunStatus::Failed, $verification->fresh()->status);
        $this->assertNull($verification->fresh()->request_sent_at);
        $this->artisan('ai:dispatch')->assertSuccessful();
        $this->assertSame(0, Article::published()->count());
        Http::assertSentCount(1);
    }

    public function test_budget_exhaustion_and_provider_failure_hold_articles_with_no_partial_publication(): void
    {
        $run = $this->draft();
        app(AiAutomation::class)->advance($run->article, $this->admin);
        $verification = AiGenerationRun::where('task', 'verification')->sole();
        config(['ai.daily_limit_microusd' => 1]);
        app(AiDrafting::class)->process($verification->id, false);
        $this->assertSame('budget_limit_or_allowance_exhausted', $verification->fresh()->error_code);
        app(AiAutomation::class)->advance($run->article->fresh(), $this->admin);
        $this->assertSame('held', $run->article->fresh()->automation_status);
        $this->assertSame(0, Article::published()->count());
        Http::assertSentCount(1);
    }

    public function test_translation_failure_and_semantic_rejection_hold_incomplete_articles(): void
    {
        $run = $this->draft();
        app(AiAutomation::class)->advance($run->article, $this->admin);
        $this->complete(AiGenerationRun::where('task', 'verification')->sole());
        app(AiAutomation::class)->advance($run->article->fresh(), $this->admin);
        $translation = AiGenerationRun::where('task', 'translation')->sole();
        $this->fakeProvider([], 401);
        app(AiDrafting::class)->process($translation->id, false);
        app(AiAutomation::class)->advance($run->article->fresh(), $this->admin);
        $this->assertSame('invalid_credentials', $translation->fresh()->error_code);
        $this->assertSame('held', $run->article->fresh()->automation_status);
        $this->assertSame(1, $run->article->translations()->count());
        $this->assertSame(0, Article::published()->count());
    }

    public function test_semantic_verification_rejects_unsupported_claims_even_when_screening_passed(): void
    {
        $run = $this->draft();
        app(AiAutomation::class)->advance($run->article, $this->admin);
        $verification = AiGenerationRun::where('task', 'verification')->sole();
        $this->fakeProvider($this->response($verification, ['version' => 'verification-v1', 'supported' => false,
            'faithful_translation' => true, 'original' => true, 'correct_language' => true, 'issues' => ['Unsupported event assertion.']]));
        app(AiDrafting::class)->process($verification->id, false);
        app(AiDrafting::class)->validate($verification->id, false);
        app(AiAutomation::class)->advance($run->article->fresh(), $this->admin);
        $this->assertSame(AiRunStatus::QualityBlocked, $verification->fresh()->status);
        $this->assertSame('held', $run->article->fresh()->automation_status);
        $this->assertDatabaseCount('ai_editorial_reviews', 0);
        $this->assertSame(0, Article::published()->count());
    }

    public function test_safe_retries_are_automatic_bounded_and_idempotent(): void
    {
        $run = app(AiDrafting::class)->generate($this->admin, $this->candidate, dispatch: false, automated: true);
        $this->fakeProvider([], 429, ['Retry-After' => '60']);
        app(AiDrafting::class)->process($run->id, false);
        $this->assertSame(AiRunStatus::Failed, $run->fresh()->status);
        $this->assertSame('released', $run->fresh()->reservation->status);
        $this->artisan('ai:dispatch')->assertSuccessful();
        $this->assertDatabaseCount('ai_generation_runs', 1);
        $this->travel(61)->seconds();
        $this->artisan('ai:dispatch')->assertSuccessful();
        $this->artisan('ai:dispatch')->assertSuccessful();
        $retry = AiGenerationRun::where('parent_run_id', $run->id)->sole();
        $this->assertSame(2, $retry->attempt);
        $this->mockResponses();
        $this->complete($retry);
        $article = $this->drain();
        $this->assertSame(ArticleStatus::Published, $article->status, $article->automation_error ?? '');
        $this->assertDatabaseCount('articles', 1);
    }

    public function test_uncertain_provider_costs_remain_reserved_and_never_retry_automatically(): void
    {
        $run = app(AiDrafting::class)->generate($this->admin, $this->candidate, dispatch: false, automated: true);
        $this->fakeProvider([], 500);
        app(AiDrafting::class)->process($run->id, false);
        $this->assertSame(AiRunStatus::Uncertain, $run->fresh()->status);
        $this->assertSame('uncertain', $run->fresh()->reservation->status);
        $this->assertGreaterThan(0, AiBudgetPeriod::sum('reserved_microusd'));
        $this->travel(10)->minutes();
        $this->artisan('ai:dispatch')->assertSuccessful();
        $this->assertDatabaseCount('ai_generation_runs', 1);
        Http::assertSentCount(1);
    }

    public function test_daily_publication_limit_holds_a_prepared_article_until_operator_attention(): void
    {
        $run = $this->draft();
        EditorialEvent::create(['article_id' => $run->article_id, 'user_id' => $this->admin->id, 'action' => 'automatic_published']);
        $article = $this->drain();
        $this->assertSame('held', $article->automation_status);
        $this->assertSame('daily_publication_limit', $article->automation_error);
        $this->assertSame(0, Article::published()->count());
    }

    public function test_native_controls_save_automatic_mode_and_categories_but_server_caps_still_apply(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs($this->admin);
        $state = app(AiSettings::class)->current();
        $state['max_articles_per_day'] = 3;
        $state['daily_limit_microusd'] = 9000000;
        Livewire::test(AiNewsControlCenter::class)->fillForm($state, 'aiForm')->call('saveAi')->assertHasNoErrors();
        $this->assertTrue(AiPipelineSetting::find(1)->automatic_publishing);
        $this->assertSame(1, app(AiSettings::class)->limits()['articles']);
        $this->assertSame(1000000, app(AiSettings::class)->limits()['daily']);
        config(['ai.automatic_publishing' => false]);
        $this->assertFalse(app(AiSettings::class)->automatic());
        Http::assertNothingSent();
    }

    public static function invalidPrimaryMaterials(): array
    {
        return [['missing_body'], ['wrong_title'], ['wrong_date'], ['missing_date'], ['redirect'], ['wrong_type'], ['too_large']];
    }

    #[DataProvider('invalidPrimaryMaterials')]
    public function test_inadequate_or_unsafe_primary_material_is_held_before_any_ai_call(string $problem): void
    {
        $html = $this->html();
        CandidateEvidence::where('news_candidate_id', $this->candidate->id)->delete();
        $this->candidate->forceFill(['status' => CandidateStatus::New])->save();
        $reference = $this->candidate->references()->first();
        $html = match ($problem) {
            'missing_body' => '<html><head><meta property="og:title" content="'.e($reference->headline).'"></head></html>',
            'wrong_title' => str_replace(e($reference->headline), 'An unrelated official announcement', $html),
            'wrong_date' => str_replace($reference->source_published_at->toIso8601String(), now()->subDays(3)->toIso8601String(), $html),
            'missing_date' => preg_replace('/<meta property="article:published_time"[^>]+>/', '', $html),
            'too_large' => str_repeat('x', config('discovery.max_feed_bytes') + 1),
            default => $html,
        };
        Http::fake([$reference->original_url => Http::response($html, $problem === 'redirect' ? 302 : 200,
            ['Content-Type' => $problem === 'wrong_type' ? 'application/json' : 'text/html', 'Location' => 'https://127.0.0.1/secret'])]);
        app(AiAutomation::class)->verifyCandidate($this->candidate->id);
        $this->assertSame('held', $this->candidate->fresh()->automation_status);
        $this->assertDatabaseCount('ai_generation_runs', 0);
        $this->assertDatabaseCount('articles', 0);
        Http::assertSentCount(1);
    }

    public function test_admission_and_run_locks_prevent_duplicate_provider_requests(): void
    {
        $run = app(AiDrafting::class)->generate($this->admin, $this->candidate, dispatch: false, automated: true);
        $lock = app(NewsDiscovery::class)->cache()->lock('ai:run:'.$run->id, 90);
        $this->assertTrue($lock->get());
        $this->mockResponses();
        app(AiDrafting::class)->process($run->id, false);
        Http::assertNothingSent();
        $lock->release();
        $slot = app(NewsDiscovery::class)->cache()->lock('ai:slot:0', 90);
        $this->assertTrue($slot->get());
        app(AiDrafting::class)->process($run->id, false);
        Http::assertNothingSent();
        $slot->release();
        $this->complete($run);
        app(AiDrafting::class)->process($run->id, false);
        app(AiDrafting::class)->store($run->id);
        Http::assertSentCount(1);
        $this->assertDatabaseCount('articles', 1);
    }

    public function test_received_automatic_stage_recovers_after_worker_interruption_without_another_paid_call(): void
    {
        $run = app(AiDrafting::class)->generate($this->admin, $this->candidate, dispatch: false, automated: true);
        $this->mockResponses();
        app(AiDrafting::class)->process($run->id, false);
        $this->artisan('ai:dispatch')->assertSuccessful();
        Queue::assertPushed(ValidateAiDraft::class);
        app(AiDrafting::class)->validate($run->id, false);
        $this->artisan('ai:dispatch')->assertSuccessful();
        Queue::assertPushed(StoreAiDraft::class);
        app(AiDrafting::class)->store($run->id);
        app(AiDrafting::class)->store($run->id);
        Http::assertSentCount(1);
        $this->assertDatabaseCount('articles', 1);
        $this->assertSame('processing', $run->fresh()->article->automation_status);
    }

    public function test_safe_retries_stop_at_three_attempts(): void
    {
        $run = app(AiDrafting::class)->generate($this->admin, $this->candidate, dispatch: false, automated: true);
        $this->fakeProvider([], 429, ['Retry-After' => '60']);
        for ($attempt = 1; $attempt <= 3; $attempt++) {
            app(AiDrafting::class)->process($run->id, false);
            $this->travel(300)->seconds();
            $this->artisan('ai:dispatch')->assertSuccessful();
            if ($attempt < 3) {
                $run = AiGenerationRun::where('parent_run_id', $run->id)->sole();
            }
        }
        $this->assertDatabaseCount('ai_generation_runs', 3);
        $this->assertSame(0, Article::published()->count());
        Http::assertSentCount(3);
    }

    public function test_source_revocation_blocks_publication_after_all_languages_were_verified(): void
    {
        $run = $this->draft();
        for ($stage = 0; $stage < 15; $stage++) {
            $this->artisan('ai:dispatch')->assertSuccessful();
            $this->complete(AiGenerationRun::where('status', AiRunStatus::Queued)->sole());
        }
        $source = $this->candidate->references()->first()->source;
        $source->forceFill(['ai_usage_allowed' => false])->save();
        $this->artisan('ai:dispatch')->assertSuccessful();
        $this->assertSame('held', $run->article->fresh()->automation_status);
        $this->assertSame(0, Article::published()->count());
    }

    public function test_monthly_budget_and_daily_article_limit_cannot_be_bypassed_in_automatic_mode(): void
    {
        config(['ai.monthly_limit_microusd' => 1]);
        $run = app(AiDrafting::class)->generate($this->admin, $this->candidate, dispatch: false, automated: true);
        app(AiDrafting::class)->process($run->id, false);
        $this->assertSame('budget_limit_or_allowance_exhausted', $run->fresh()->error_code);
        Http::assertNothingSent();
        config(['ai.monthly_limit_microusd' => 10000000]);
        $run = $this->draft();
        $this->assertSame(1, AiBudgetPeriod::where('period_key', 'like', 'day:%')->sole()->actual_articles);
        $other = NewsCandidate::factory()->approvedForDraft()->create(['category_id' => $this->category->id]);
        foreach (CandidateEvidence::where('news_candidate_id', $this->candidate->id)->get() as $claim) {
            $reference = $this->candidate->references()->first()->replicate();
            $reference->fill(['news_candidate_id' => $other->id, 'external_hash' => hash('sha256', $other->id.':'.$claim->id)]);
            $reference->save();
            $copy = $claim->replicate();
            $copy->fill(['news_candidate_id' => $other->id, 'reference_ids' => [$reference->id], 'claim_key' => Str::uuid()->toString()])->save();
        }
        $second = app(AiDrafting::class)->generate($this->admin, $other, dispatch: false, automated: true);
        app(AiDrafting::class)->process($second->id, false);
        $this->assertSame('budget_limit_or_allowance_exhausted', $second->fresh()->error_code);
        Http::assertSentCount(1);
    }

    public function test_human_review_can_take_over_an_automatic_draft_and_use_the_optional_manual_workflow(): void
    {
        $run = $this->draft();
        app(AiEditorialReviewService::class)->review($this->admin, $run->article, 'en', true, true,
            'Verified the original evidence, all article claims and source attribution for optional manual handling.');
        $this->assertSame('paused', $run->article->fresh()->automation_status);
        $this->assertSame('approved', $run->article->fresh(['translations'])->translation('en')->ai_review_status);
        config(['ai.automatic_publishing' => false]);
        $translation = app(AiDrafting::class)->translate($this->admin, $run->article, ['fr'], dispatch: false)[0];
        $this->assertFalse($translation->is_automated);
        $this->complete($translation);
        $this->assertSame(0, Article::published()->count());
        $this->artisan('ai:dispatch')->assertSuccessful();
        $this->assertSame('paused', $run->article->fresh()->automation_status);
    }
}
