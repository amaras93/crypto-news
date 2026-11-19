<?php

namespace Tests\Feature;

use App\AiRunStatus;
use App\ArticleStatus;
use App\Jobs\GenerateAiDraft;
use App\Jobs\StoreAiDraft;
use App\Jobs\ValidateAiDraft;
use App\Models\AiBudgetPeriod;
use App\Models\AiGenerationRun;
use App\Models\AiPipelineSetting;
use App\Models\Article;
use App\Models\Category;
use App\Models\CategoryTranslation;
use App\Models\EditorialEvent;
use App\Support\AiDemoFixtures;
use App\Support\AiDrafting;
use App\Support\AiOutputSchema;
use App\Support\AiPricing;
use App\Support\AiQuality;
use App\Support\AiRecovery;
use App\Support\AiSemanticVerification;
use App\Support\AiSettings;
use App\Support\FakeAiDraftProvider;
use App\Support\OpenAiDraftProvider;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\AiTestCase;

class AiAutonomousRecoveryTest extends AiTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->live();
        $category = Category::factory()->create();
        foreach (array_keys(config('news.locales')) as $locale) {
            CategoryTranslation::factory()->for($category)->create(['locale' => $locale]);
        }
        $this->candidate->update(['category_id' => $category->id, 'automation_status' => 'evidence_verified']);
        config(['ai.automatic_publishing' => true, 'ai.scheduled_generation_enabled' => true,
            'ai.max_articles_per_day' => 1, 'ai.max_generation_attempts_per_day' => 6]);
        AiPipelineSetting::find(1)->update(['automatic_publishing' => true, 'scheduled_generation_enabled' => true,
            'max_articles_per_day' => 1, 'max_generation_attempts_per_day' => 6,
            'enabled_category_ids' => [$category->id], 'updated_by' => $this->admin->id]);
    }

    private function mockStages(): void
    {
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(['api.openai.com/*' => function (Request $request) {
            $run = AiGenerationRun::where('status', AiRunStatus::Requesting)->latest('id')->firstOrFail();
            $document = $run->task === 'verification' ? $this->verifiedDocument($run) : app(FakeAiDraftProvider::class)->fixture($run);

            return Http::response($this->response($run, $document));
        }]);
    }

    private function english(): AiGenerationRun
    {
        $this->mockStages();

        return $this->complete(app(AiDrafting::class)->generate($this->admin, $this->candidate, dispatch: false, automated: true));
    }

    private function verifyEnglish(AiGenerationRun $english): AiGenerationRun
    {
        $verification = app(AiDrafting::class)->verify($this->admin, $english->article, 'en');
        $this->mockStages();

        return $this->complete($verification);
    }

    private function drain(): Article
    {
        $this->mockStages();
        for ($stage = 0; $stage < 40; $stage++) {
            $this->artisan('ai:dispatch')->assertSuccessful();
            foreach (AiGenerationRun::where('status', AiRunStatus::Queued)->get() as $run) {
                $this->complete($run);
            }
            $article = Article::first();
            if ($article?->automation_status === 'published') {
                return $article->fresh(['translations']);
            }
            $this->travel(121)->seconds();
        }
        $this->fail('Workflow did not publish within its bounded stages.');
    }

    public function test_actual_false_negative_fixture_preserves_the_distinction_between_release_and_meeting_dates(): void
    {
        $fixture = json_decode(file_get_contents(base_path('tests/Fixtures/rejected-federal-reserve-semantic.json')), true, flags: JSON_THROW_ON_ERROR);
        $this->assertFalse($fixture['output']['supported']);
        $this->assertStringContainsString('October 27–28, 2026', $fixture['input']['claims'][0]['text']);
        $this->assertStringContainsString('Wednesday', $fixture['input']['claims'][0]['text']);
        $this->assertSame('2026-11-18T19:00:00+00:00', $fixture['input']['claims'][0]['references'][0]['source_timestamp']);
        $run = new AiGenerationRun(['task' => 'verification', 'locale' => 'en', 'evidence_snapshot' => $fixture['evidence']]);
        $run->schema_version = 'verification-v2';
        $assertions = app(AiSemanticVerification::class)->assertions($fixture['document']);
        $run->input_payload = ['assertions' => $assertions];
        $document = ['version' => 'verification-v2', 'supported' => true, 'faithful_translation' => true,
            'original' => true, 'correct_language' => true, 'issues' => [], 'assessments' => []];
        $claims = collect($fixture['evidence']['claims'])->keyBy('id');
        foreach ($assertions as $assertion) {
            $id = $assertion['claim_ids'][0];
            $document['assessments'][] = ['field' => $assertion['field'], 'verdict' => 'supported',
                'evidence' => [['claim_id' => $id, 'quote' => $claims[$id]['text']]], 'reason' => 'Explicitly stated by the primary source.'];
        }
        $document = app(AiOutputSchema::class)->validate($document, $run);
        $this->assertSame([], app(AiSemanticVerification::class)->report($document, $run)['critical']);
        $instructions = app(OpenAiDraftProvider::class)->instructions($run);
        $this->assertStringContainsString('November release can describe an October meeting', $instructions);
        $this->assertStringContainsString('exact verbatim quote', $instructions);
        Http::assertNothingSent();
    }

    #[DataProvider('blockingVerdicts')]
    public function test_contradicted_absent_and_uncertain_facts_never_pass_even_with_true_overall_booleans(string $verdict): void
    {
        $english = $this->english();
        $verification = app(AiDrafting::class)->verify($this->admin, $english->article, 'en');
        $doc = $this->verifiedDocument($verification);
        $doc['assessments'][0]['verdict'] = $verdict;
        $this->fakeProvider($this->response($verification, $doc));
        app(AiDrafting::class)->process($verification->id, false);
        app(AiDrafting::class)->validate($verification->id, false);
        $this->assertSame(AiRunStatus::QualityBlocked, $verification->fresh()->status);
        $this->assertContains('semantic_'.$verdict, $verification->fresh()->quality_report['critical']);
        if ($verdict !== 'uncertain') {
            $this->assertNull(app(AiRecovery::class)->plan($verification->fresh()));
            $this->artisan('ai:dispatch')->assertSuccessful();
            $this->assertSame('held', $english->article->fresh()->automation_status);
        }
        $this->assertSame(0, Article::published()->count());
    }

    public static function blockingVerdicts(): array
    {
        return [['contradicted'], ['absent'], ['uncertain']];
    }

    #[DataProvider('invalidMappings')]
    public function test_missing_fields_fabricated_quotes_and_wrong_claim_links_fail_closed(string $kind): void
    {
        $english = $this->english();
        $verification = app(AiDrafting::class)->verify($this->admin, $english->article, 'en');
        $doc = $this->verifiedDocument($verification);
        if ($kind === 'missing_field') {
            array_pop($doc['assessments']);
        } elseif ($kind === 'quote') {
            $doc['assessments'][0]['evidence'][0]['quote'] = 'This invented passage does not appear in the primary source.';
        } else {
            $doc['assessments'][count($doc['assessments']) - 1]['evidence'][0] = [
                'claim_id' => $verification->evidence_snapshot['claims'][0]['id'],
                'quote' => $verification->evidence_snapshot['claims'][0]['text'],
            ];
        }
        $this->fakeProvider($this->response($verification, $doc));
        app(AiDrafting::class)->process($verification->id, false);
        app(AiDrafting::class)->validate($verification->id, false);
        $this->assertSame(AiRunStatus::QualityBlocked, $verification->fresh()->status);
        $this->assertSame(0, Article::published()->count());
    }

    public static function invalidMappings(): array
    {
        return [['missing_field'], ['quote'], ['paragraph_claim']];
    }

    public function test_scheduler_corrects_english_provenance_after_backoff_and_publishes_all_languages(): void
    {
        $root = app(AiDrafting::class)->generate($this->admin, $this->candidate, dispatch: false, automated: true);
        $doc = app(FakeAiDraftProvider::class)->fixture($root);
        $doc['headline'] .= ' '.$root->evidence_snapshot['claims'][0]['id'];
        $this->fakeProvider($this->response($root, $doc));
        app(AiDrafting::class)->process($root->id, false);
        app(AiDrafting::class)->validate($root->id, false);
        $this->artisan('ai:dispatch')->assertSuccessful();
        $this->assertSame(0, $root->children()->count());
        $this->assertNotNull($root->fresh()->retry_at);
        $article = $this->drain();
        $this->assertSame(ArticleStatus::Published, $article->status);
        $this->assertSame(8, $article->translations->count());
        $this->assertSame(8, $article->translations->where('ai_review_status', 'automated')->count());
        $this->assertSame(1, $root->children()->count());
        $this->assertSame($doc, $root->fresh()->output_document);
        $this->assertSame(1, $root->fresh()->attempt);
        $this->assertSame(2, AiBudgetPeriod::where('period_key', 'like', 'day:%')->sole()->actual_articles);
        $this->assertSame(1, EditorialEvent::where('action', 'automatic_published')->count());
    }

    public function test_failed_translation_is_corrected_without_regenerating_english_or_successful_languages(): void
    {
        $english = $this->english();
        $this->verifyEnglish($english);
        $ru = app(AiDrafting::class)->translate($this->admin, $english->article->fresh(), ['ru'], dispatch: false)[0];
        $doc = app(FakeAiDraftProvider::class)->fixture($ru);
        $doc['excerpt'] .= ' 99999';
        $this->fakeProvider($this->response($ru, $doc));
        app(AiDrafting::class)->process($ru->id, false);
        app(AiDrafting::class)->validate($ru->id, false);
        $this->assertSame(AiRunStatus::QualityBlocked, $ru->fresh()->status);
        $englishDigest = $english->article->translation('en')->contentDigest();
        $article = $this->drain();
        $this->assertSame($englishDigest, $article->translation('en')->contentDigest());
        $this->assertSame(1, AiGenerationRun::where('task', 'english')->count());
        $this->assertSame(2, AiGenerationRun::where('task', 'translation')->where('locale', 'ru')->count());
        $this->assertSame(1, $ru->children()->count());
        $this->assertSame(ArticleStatus::Published, $article->status);
    }

    public function test_ambiguous_verification_retries_only_verification_with_backoff(): void
    {
        $english = $this->english();
        $verification = app(AiDrafting::class)->verify($this->admin, $english->article, 'en');
        $doc = $this->verifiedDocument($verification);
        $doc['supported'] = false;
        $doc['assessments'][0]['verdict'] = 'uncertain';
        $doc['issues'] = ['Evidence mapping is ambiguous.'];
        $this->fakeProvider($this->response($verification, $doc));
        app(AiDrafting::class)->process($verification->id, false);
        app(AiDrafting::class)->validate($verification->id, false);
        $article = $this->drain();
        $this->assertSame(1, AiGenerationRun::where('task', 'english')->count());
        $this->assertSame(2, AiGenerationRun::where('task', 'verification')->where('locale', 'en')->count());
        $this->assertSame(ArticleStatus::Published, $article->status);
    }

    public function test_legacy_false_negative_is_reverified_with_new_schema_without_erasing_its_result(): void
    {
        $english = $this->english();
        $verification = app(AiDrafting::class)->verify($this->admin, $english->article, 'en');
        $verification->forceFill(['schema_version' => 'verification-v1'])->save();
        $doc = ['version' => 'verification-v1', 'supported' => false, 'faithful_translation' => true,
            'original' => true, 'correct_language' => true, 'issues' => ['Unsupported date; fixture mirrors the false negative.']];
        $this->fakeProvider($this->response($verification, $doc));
        app(AiDrafting::class)->process($verification->id, false);
        app(AiDrafting::class)->validate($verification->id, false);
        $english->article->forceFill(['automation_status' => 'held', 'automation_error' => 'semantic_verification_failed'])->save();
        $article = $this->drain();
        $this->assertSame($doc, $verification->fresh()->output_document);
        $this->assertSame(1, $verification->fresh()->attempt);
        $this->assertSame('verification-v2', $verification->children()->sole()->schema_version);
        $this->assertSame(1, AiGenerationRun::where('task', 'english')->count());
        $this->assertSame(ArticleStatus::Published, $article->status);
    }

    public function test_received_stage_resumes_after_restart_without_repeating_a_paid_request(): void
    {
        $run = app(AiDrafting::class)->generate($this->admin, $this->candidate, dispatch: false, automated: true);
        $this->fakeProvider($this->response($run));
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
        $this->assertDatabaseCount('article_translations', 1);
    }

    public function test_budget_exhaustion_prevents_scheduler_from_creating_or_sending_work(): void
    {
        config(['ai.daily_limit_microusd' => 1]);
        $this->artisan('ai:dispatch')->assertSuccessful();
        $this->assertDatabaseCount('ai_generation_runs', 0);
        Http::assertNothingSent();
        $this->assertFalse(app(AiSettings::class)->current()['emergency_stop']);
    }

    public function test_six_attempts_are_separate_from_one_publication_and_history_remains_unchanged(): void
    {
        for ($index = 0; $index < 6; $index++) {
            $run = app(AiDrafting::class)->generate($this->admin, $this->candidate, dispatch: false, automated: true);
            $doc = app(FakeAiDraftProvider::class)->fixture($run);
            $doc['excerpt'] .= ' 99999';
            $this->fakeProvider($this->response($run, $doc));
            app(AiDrafting::class)->process($run->id, false);
            app(AiDrafting::class)->validate($run->id, false);
        }
        $this->assertSame(6, AiBudgetPeriod::where('period_key', 'like', 'day:%')->sole()->actual_articles);
        $this->assertSame(0, EditorialEvent::where('action', 'automatic_published')->count());
        $last = app(AiDrafting::class)->generate($this->admin, $this->candidate, dispatch: false, automated: true);
        app(AiDrafting::class)->process($last->id, false);
        $this->assertSame('budget_limit_or_allowance_exhausted', $last->fresh()->error_code);
        $this->assertSame(0, $last->fresh()->request_count);
        $this->assertDatabaseCount('ai_spend_reservations', 6);
        $this->assertSame(6, AiGenerationRun::sum('request_count'));
    }

    public function test_unknown_timeout_cost_is_reserved_and_never_automatically_reissued(): void
    {
        $run = app(AiDrafting::class)->generate($this->admin, $this->candidate, dispatch: false, automated: true);
        Http::fake(['*' => Http::failedConnection()]);
        app(AiDrafting::class)->process($run->id, false);
        $this->artisan('ai:dispatch')->assertSuccessful();
        $this->travel(600)->seconds();
        $this->artisan('ai:dispatch')->assertSuccessful();
        $this->assertSame(AiRunStatus::Uncertain, $run->fresh()->status);
        $this->assertSame(0, $run->children()->count());
        $this->assertSame('uncertain', $run->fresh()->reservation->status);
        $this->assertGreaterThan(0, AiBudgetPeriod::first()->reserved_microusd);
        Http::assertSentCount(1);
    }

    public function test_genuine_contradiction_holds_one_candidate_and_scheduler_selects_another(): void
    {
        $english = $this->english();
        $verification = app(AiDrafting::class)->verify($this->admin, $english->article, 'en');
        $doc = $this->verifiedDocument($verification);
        $doc['supported'] = false;
        $doc['assessments'][0]['verdict'] = 'contradicted';
        $doc['issues'] = ['The asserted event conflicts with the supplied evidence.'];
        $this->fakeProvider($this->response($verification, $doc));
        app(AiDrafting::class)->process($verification->id, false);
        app(AiDrafting::class)->validate($verification->id, false);
        $fixture = app(AiDemoFixtures::class)->create();
        $next = $fixture['candidate'];
        $next->forceFill(['is_simulated' => false, 'category_id' => $this->candidate->category_id, 'automation_status' => 'evidence_verified'])->save();
        $next->references()->first()->source->forceFill(['is_simulated' => false])->save();
        $this->artisan('ai:dispatch')->assertSuccessful();
        $this->assertSame('held', $english->article->fresh()->automation_status);
        $this->assertSame('held', $this->candidate->fresh()->automation_status);
        $this->assertSame(1, AiGenerationRun::where('news_candidate_id', $next->id)->where('task', 'english')->count());
        $this->assertFalse(app(AiSettings::class)->current()['emergency_stop']);
        $this->assertSame(0, Article::published()->count());
    }

    public function test_semantic_translation_failure_corrects_only_that_saved_language(): void
    {
        $english = $this->english();
        $this->verifyEnglish($english);
        $ru = app(AiDrafting::class)->translate($this->admin, $english->article->fresh(), ['ru'], dispatch: false)[0];
        $this->mockStages();
        $this->complete($ru);
        $verification = app(AiDrafting::class)->verify($this->admin, $english->article->fresh(), 'ru');
        $doc = $this->verifiedDocument($verification);
        $doc['faithful_translation'] = false;
        $doc['issues'] = ['A qualification must be restored in this language.'];
        $this->fakeProvider($this->response($verification, $doc));
        app(AiDrafting::class)->process($verification->id, false);
        app(AiDrafting::class)->validate($verification->id, false);
        $article = $this->drain();
        $this->assertSame(ArticleStatus::Published, $article->status);
        $this->assertSame(1, AiGenerationRun::where('task', 'english')->count());
        $this->assertSame(2, AiGenerationRun::where('task', 'translation')->where('locale', 'ru')->count());
        $this->assertSame(2, AiGenerationRun::where('task', 'verification')->where('locale', 'ru')->count());
        $this->assertSame('translation', $verification->children()->sole()->task);
    }

    public function test_invalid_generated_schema_is_repaired_through_a_new_accounted_attempt(): void
    {
        $root = app(AiDrafting::class)->generate($this->admin, $this->candidate, dispatch: false, automated: true);
        $doc = app(FakeAiDraftProvider::class)->fixture($root);
        $doc['slug'] = 'INVALID SLUG';
        $this->fakeProvider($this->response($root, $doc));
        app(AiDrafting::class)->process($root->id, false);
        app(AiDrafting::class)->validate($root->id, false);
        $this->assertSame(AiRunStatus::Failed, $root->fresh()->status);
        $this->assertSame('settled', $root->fresh()->reservation->status);
        $article = $this->drain();
        $this->assertSame(ArticleStatus::Published, $article->status);
        $this->assertSame(1, $root->children()->count());
        $this->assertSame($doc, $root->fresh()->output_document);
    }

    public function test_persistent_correction_failures_end_after_two_followups_without_publication(): void
    {
        $run = app(AiDrafting::class)->generate($this->admin, $this->candidate, dispatch: false, automated: true);
        for ($attempt = 1; $attempt <= 3; $attempt++) {
            $doc = app(FakeAiDraftProvider::class)->fixture($run);
            $doc['excerpt'] .= ' 99999';
            $this->fakeProvider($this->response($run, $doc));
            app(AiDrafting::class)->process($run->id, false);
            app(AiDrafting::class)->validate($run->id, false);
            $this->artisan('ai:dispatch')->assertSuccessful();
            $this->travel(300)->seconds();
            $this->artisan('ai:dispatch')->assertSuccessful();
            if ($attempt < 3) {
                $this->assertSame(1, $run->children()->count());
                $run = $run->children()->sole();
            }
        }
        $this->assertSame(0, $run->children()->count());
        $this->assertSame(3, AiGenerationRun::where('task', 'english')->count());
        $this->assertSame('held', $this->candidate->fresh()->automation_status);
        $this->assertSame(0, Article::published()->count());
    }

    public function test_worker_death_before_sending_is_safely_retried_after_backoff(): void
    {
        $root = app(AiDrafting::class)->generate($this->admin, $this->candidate, dispatch: false, automated: true);
        (new GenerateAiDraft($root->id))->failed(new \RuntimeException('worker interrupted before request'));
        $this->assertSame(AiRunStatus::Failed, $root->fresh()->status);
        $this->assertNotNull($root->fresh()->retry_at);
        $this->assertNull($root->fresh()->request_sent_at);
        $this->travel(61)->seconds();
        $this->artisan('ai:dispatch')->assertSuccessful();
        $this->assertSame(1, $root->children()->count());
        $this->mockStages();
        $this->complete($root->children()->sole());
        Http::assertSentCount(1);
        $this->assertSame(1, AiBudgetPeriod::where('period_key', 'like', 'day:%')->sole()->actual_articles);
    }

    public function test_paused_or_human_edited_articles_are_never_resumed_by_recovery(): void
    {
        $english = $this->english();
        $verification = app(AiDrafting::class)->verify($this->admin, $english->article, 'en');
        $doc = $this->verifiedDocument($verification);
        $doc['assessments'][0]['verdict'] = 'uncertain';
        $this->fakeProvider($this->response($verification, $doc));
        app(AiDrafting::class)->process($verification->id, false);
        app(AiDrafting::class)->validate($verification->id, false);
        $english->article->forceFill(['automation_status' => 'paused'])->save();
        $this->artisan('ai:dispatch')->assertSuccessful();
        $this->travel(600)->seconds();
        $this->artisan('ai:dispatch')->assertSuccessful();
        $this->assertSame(0, $verification->children()->count());
        $this->assertSame('paused', $english->article->fresh()->automation_status);
        Http::assertSentCount(1);
    }

    public function test_exact_unexplained_verifier_failure_is_not_accepted_as_a_success(): void
    {
        $fixture = json_decode(file_get_contents(base_path('tests/Fixtures/rejected-federal-reserve-verifier-consistency.json')), true, flags: JSON_THROW_ON_ERROR);
        $run = new AiGenerationRun(['task' => 'verification', 'locale' => 'ru', 'evidence_snapshot' => $fixture['evidence']]);
        $run->schema_version = 'verification-v2';
        $run->input_payload = ['assertions' => $fixture['assertions']];
        $report = app(AiSemanticVerification::class)->report($fixture['output'], $run);
        $this->assertFalse($fixture['output']['original']);
        $this->assertSame([], $fixture['output']['issues']);
        $this->assertSame(['supported'], array_values(array_unique(array_column($fixture['output']['assessments'], 'verdict'))));
        $this->assertContains('semantic_report_inconsistent', $report['critical']);
        $this->assertContains('semantic_verification_failed', $report['critical']);
        Http::assertNothingSent();
    }

    public function test_inconsistent_verifier_retries_its_own_stage_after_translation_corrections_are_exhausted(): void
    {
        $english = $this->english();
        $this->verifyEnglish($english);
        $translation = app(AiDrafting::class)->translate($this->admin, $english->article->fresh(), ['ru'], dispatch: false)[0];
        for ($attempt = 1; $attempt <= 2; $attempt++) {
            $document = app(FakeAiDraftProvider::class)->fixture($translation);
            $document['excerpt'] .= ' 99999';
            $this->fakeProvider($this->response($translation, $document));
            app(AiDrafting::class)->process($translation->id, false);
            app(AiDrafting::class)->validate($translation->id, false);
            $this->artisan('ai:dispatch')->assertSuccessful();
            $this->travel(300)->seconds();
            $this->artisan('ai:dispatch')->assertSuccessful();
            $translation = $translation->children()->sole();
        }
        $this->mockStages();
        $this->complete($translation);
        $verification = app(AiDrafting::class)->verify($this->admin, $english->article->fresh(), 'ru');
        $document = $this->verifiedDocument($verification);
        $document['original'] = false;
        $this->fakeProvider($this->response($verification, $document));
        app(AiDrafting::class)->process($verification->id, false);
        app(AiDrafting::class)->validate($verification->id, false);
        $this->assertSame(AiRunStatus::QualityBlocked, $verification->fresh()->status);
        $this->assertSame('verification_correction', app(AiRecovery::class)->plan($verification->fresh()));
        $this->assertSame(3, AiGenerationRun::where('task', 'translation')->where('locale', 'ru')->count());
        $report = $verification->fresh()->quality_report;
        $report['recovery'] = ['state' => 'held', 'reason' => 'critical_quality_failure'];
        $verification->forceFill(['quality_report' => $report])->save();
        $english->article->forceFill(['automation_status' => 'held', 'automation_error' => 'critical_quality_failure'])->save();
        $article = $this->drain();
        $this->assertSame(ArticleStatus::Published, $article->status);
        $this->assertSame(1, AiGenerationRun::where('task', 'english')->count());
        $this->assertSame(3, AiGenerationRun::where('task', 'translation')->where('locale', 'ru')->count());
        $this->assertSame(2, AiGenerationRun::where('task', 'verification')->where('locale', 'ru')->count());
        $this->assertSame('verification', $verification->children()->sole()->task);
        $this->assertSame($document, $verification->fresh()->output_document);
    }

    public function test_actual_verification_recovery_fits_the_existing_input_budget_without_duplicate_evidence_reports(): void
    {
        $fixture = json_decode(file_get_contents(base_path('tests/Fixtures/rejected-federal-reserve-verifier-consistency.json')), true, flags: JSON_THROW_ON_ERROR);
        $run = new AiGenerationRun(['task' => 'verification', 'locale' => 'ru', 'evidence_snapshot' => $fixture['evidence']]);
        $run->schema_version = 'verification-v2';
        $payload = $fixture['input'];
        $payload['correction'] = ['failed_task' => 'verification', 'failed_locale' => 'ru',
            'diagnostics' => app(AiSemanticVerification::class)->failureContext($fixture['output'], ['critical' => ['semantic_verification_failed']])];
        $estimate = app(AiPricing::class)->reservation($payload, app(AiOutputSchema::class)->jsonSchema($run),
            ['input' => 750000, 'cached' => 75000, 'write' => 0, 'output' => 4500000], app(OpenAiDraftProvider::class)->instructions($run));
        $this->assertGreaterThan(0, $estimate);
        $this->assertLessThan(1000000, $estimate);
        $this->assertFalse($payload['correction']['diagnostics']['checks']['original']);
        $this->assertSame([], $payload['correction']['diagnostics']['checks']['issues']);
        $this->assertSame([], $payload['correction']['diagnostics']['failed_fields']);
        Http::assertNothingSent();
    }

    public function test_translation_warning_remains_blocking_and_is_corrected_without_repeating_english(): void
    {
        $english = $this->english();
        $this->verifyEnglish($english);
        $translation = app(AiDrafting::class)->translate($this->admin, $english->article->fresh(), ['ru'], dispatch: false)[0];
        $document = app(FakeAiDraftProvider::class)->fixture($translation);
        $document['unsupported_claim_warnings'] = ['An official evidenced name was retained in the translation.'];
        $this->fakeProvider($this->response($translation, $document));
        app(AiDrafting::class)->process($translation->id, false);
        app(AiDrafting::class)->validate($translation->id, false);
        $this->assertSame(AiRunStatus::QualityBlocked, $translation->fresh()->status);
        $this->assertSame(['model_reported_unsupported_claims'], $translation->fresh()->quality_report['critical']);
        $report = $translation->fresh()->quality_report;
        $report['recovery'] = ['state' => 'held', 'reason' => 'critical_quality_failure'];
        $translation->forceFill(['quality_report' => $report])->save();
        $english->article->forceFill(['automation_status' => 'held', 'automation_error' => 'critical_quality_failure'])->save();
        $article = $this->drain();
        $this->assertSame(ArticleStatus::Published, $article->status);
        $this->assertSame(1, AiGenerationRun::where('task', 'english')->count());
        $this->assertSame(2, AiGenerationRun::where('task', 'translation')->where('locale', 'ru')->count());
        $this->assertSame($document, $translation->fresh()->output_document);
        $this->assertSame(8, $article->translations()->where('ai_review_status', 'automated')->count());
    }

    public function test_actual_official_name_warning_is_not_silently_cleared_or_accepted(): void
    {
        $fixture = json_decode(file_get_contents(base_path('tests/Fixtures/rejected-federal-reserve-editorial-warning.json')), true, flags: JSON_THROW_ON_ERROR);
        $run = new AiGenerationRun(['task' => 'translation', 'locale' => 'ru', 'evidence_snapshot' => $fixture['evidence']]);
        $run->schema_version = 'translation-v2';
        $run->input_payload = $fixture['input'];
        $run->is_automated = true;
        $report = app(AiQuality::class)->document($fixture['output'], $run);
        $this->assertSame(['model_reported_unsupported_claims'], $report['critical']);
        $this->assertStringContainsString('Regulation W', $fixture['output']['unsupported_claim_warnings'][0]);
        Http::assertNothingSent();
    }
}
