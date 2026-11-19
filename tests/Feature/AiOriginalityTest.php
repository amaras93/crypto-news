<?php

namespace Tests\Feature;

use App\AiRunStatus;
use App\Models\AiBudgetPeriod;
use App\Models\AiGenerationRun;
use App\Models\AiPipelineSetting;
use App\Models\Article;
use App\Models\Category;
use App\Support\AiDrafting;
use App\Support\AiFailure;
use App\Support\AiOutputSchema;
use App\Support\AiQuality;
use App\Support\FakeAiDraftProvider;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\AiTestCase;

class AiOriginalityTest extends AiTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->live();
        $category = Category::factory()->create();
        $this->candidate->update(['category_id' => $category->id, 'topic' => 'technology']);
        config(['ai.automatic_publishing' => true, 'ai.max_articles_per_day' => 2]);
        AiPipelineSetting::find(1)->update(['automatic_publishing' => true, 'max_articles_per_day' => 2,
            'enabled_category_ids' => [$category->id], 'updated_by' => $this->admin->id]);
    }

    private function fixture(): array
    {
        return json_decode(file_get_contents(base_path('tests/Fixtures/rejected-federal-reserve-originality.json')), true, flags: JSON_THROW_ON_ERROR);
    }

    private function copiedRun(?string $otherFailure = null): AiGenerationRun
    {
        $run = app(AiDrafting::class)->generate($this->admin, $this->candidate, dispatch: false, automated: true);
        $document = app(FakeAiDraftProvider::class)->fixture($run);
        $document['sections'][0]['paragraphs'][0]['text'] = $run->evidence_snapshot['claims'][0]['text'];
        if ($otherFailure === 'number') {
            $document['excerpt'] .= ' Price reached 99999.';
        } elseif ($otherFailure === 'entity') {
            $document['entities'][] = 'Invented Corporation';
        } elseif ($otherFailure === 'id') {
            $document['headline'] .= ' '.$run->evidence_snapshot['claims'][0]['id'];
        }
        $this->fakeProvider($this->response($run, $document));
        app(AiDrafting::class)->process($run->id, false);
        app(AiDrafting::class)->validate($run->id, false);
        $this->assertSame(AiRunStatus::QualityBlocked, $run->fresh()->status);

        return $run->fresh();
    }

    public function test_the_exact_rejected_opening_is_detected_with_field_level_diagnostics(): void
    {
        $fixture = $this->fixture();
        $result = app(AiQuality::class)->originalityPreflight($fixture['document'], $fixture['evidence']);
        $this->assertSame(12, $result['threshold_words']);
        $this->assertContains(['field' => 'sections.0.paragraphs.0.text',
            'phrase' => 'the federal reserve on wednesday released the minutes of the federal open'], $result['overlaps']);
        $this->assertContains('excessive_source_similarity', app(AiQuality::class)->text($fixture['document']['sections'][0]['paragraphs'][0]['text'], $fixture['evidence'])['critical']);
        Http::assertNothingSent();
    }

    public function test_the_existing_twelve_word_threshold_is_not_relaxed(): void
    {
        $words = 'alpha beta gamma delta epsilon zeta eta theta iota kappa lambda mu';
        $evidence = ['claims' => [['text' => $words]]];
        $this->assertSame([], app(AiQuality::class)->sourceOverlaps('alpha beta gamma delta epsilon zeta eta theta iota kappa lambda', $evidence));
        $this->assertSame([$words], app(AiQuality::class)->sourceOverlaps($words, $evidence));
    }

    public function test_a_short_original_alternative_preserves_the_same_facts_and_citations(): void
    {
        $fixture = $this->fixture();
        $run = new AiGenerationRun(['evidence_snapshot' => $fixture['evidence'], 'task' => 'english', 'locale' => 'en']);
        $run->is_automated = true;
        $doc = $fixture['document'];
        $doc['headline'] = 'Federal Reserve publishes October meeting minutes';
        $doc['excerpt'] = 'According to the Federal Reserve, records from its October 27–28, 2026 meeting are available. The release explains the publication timetable.';
        $doc['seo_title'] = 'Federal Reserve releases records of October meeting';
        $doc['seo_description'] = 'The Federal Reserve announced the release of October 27–28, 2026 meeting records and explained the timetable for publishing minutes.';
        $doc['takeaways'] = ['The Federal Reserve announced the release on Wednesday.', 'The records cover the October 27–28, 2026 meeting.', 'Minutes usually follow a policy decision by three weeks.'];
        $doc['sections'][0]['heading'] = 'Release and publication timetable';
        $doc['sections'][0]['paragraphs'][0]['text'] = 'Records for the October 27–28, 2026 meeting are available, according to the Federal Reserve. Its Wednesday announcement identifies the meeting as a Federal Open Market Committee session.';
        $doc['sections'][0]['paragraphs'][1]['text'] = 'The release explains the publication schedule: minutes usually follow a policy decision by three weeks. It also says the account of economic and financial conditions uses only what the Committee knew when it met.';
        $doc['editorial_notes'] = [];
        $schema = app(AiOutputSchema::class);
        $doc = $schema->validate($doc, $run);
        $report = app(AiQuality::class)->document($doc, $run);
        $this->assertSame([], $report['critical']);
        $this->assertSame([], $report['originality']['overlaps']);
        $this->assertStringContainsString('href="'.$fixture['evidence']['claims'][0]['references'][0]['url'].'"', $schema->body($doc, $fixture['evidence']));
        $brief = app(AiQuality::class)->writingBrief($fixture['evidence']);
        $this->assertSame([60, 110], $brief['target_body_words']);
        $this->assertSame(2, $brief['target_paragraphs']);
        $this->assertSame(1, $brief['target_sections']);
        Http::assertNothingSent();
    }

    public function test_one_correction_uses_the_existing_queue_and_ledger_and_keeps_all_provenance(): void
    {
        $root = $this->copiedRun();
        $this->assertSame('originality_correction_available', $root->error_code);
        $child = app(AiDrafting::class)->correctOriginality($this->admin, $root, false);
        $this->assertSame($root->id, $child->parent_run_id);
        $this->assertSame(2, $child->attempt);
        $this->assertSame($root->evidence_digest, $child->evidence_digest);
        $this->fakeProvider($this->response($child));
        $child = $this->complete($child);
        $this->assertSame($root->output_document, $child->input_payload['originality_correction']['rejected_document']);
        $this->assertNotEmpty($child->input_payload['originality_correction']['overlaps']);
        $this->assertTrue($child->reservation->counts_article);
        $this->assertSame(2, AiBudgetPeriod::where('period_key', 'like', 'day:%')->sole()->actual_articles);
        $this->assertSame(AiRunStatus::QualityBlocked, $root->fresh()->status);
        $this->assertSame('processing', $child->article->automation_status);
        $this->assertStringContainsString('https://example.org/', $child->article->translation('en')->body);
        $this->assertSame(0, Article::published()->count());
    }

    public function test_replaying_a_correction_cannot_create_a_second_child(): void
    {
        $root = $this->copiedRun();
        app(AiDrafting::class)->correctOriginality($this->admin, $root, false);
        try {
            app(AiDrafting::class)->correctOriginality($this->admin, $root, false);
            $this->fail('A second correction was created.');
        } catch (AiFailure $failure) {
            $this->assertSame('originality_correction_not_allowed', $failure->reason);
        }
        $this->assertSame(1, $root->children()->count());
        Http::assertSentCount(1);
    }

    public function test_an_unsuccessful_correction_is_held_without_another_correction(): void
    {
        $root = $this->copiedRun();
        $child = app(AiDrafting::class)->correctOriginality($this->admin, $root, false);
        $this->fakeProvider($this->response($child, $root->output_document));
        app(AiDrafting::class)->process($child->id, false);
        app(AiDrafting::class)->validate($child->id, false);
        $this->assertSame(AiRunStatus::QualityBlocked, $child->fresh()->status);
        $this->assertSame('critical_quality_failure', $child->fresh()->error_code);
        $this->artisan('ai:dispatch')->assertSuccessful();
        $this->assertDatabaseCount('ai_generation_runs', 2);
        $this->assertSame('held', $this->candidate->fresh()->automation_status);
        $this->assertDatabaseCount('articles', 0);
        Http::assertSentCount(1);
    }

    #[DataProvider('budgetFailures')]
    public function test_correction_cannot_bypass_generation_daily_or_monthly_spending_limits(string $kind): void
    {
        $root = $this->copiedRun();
        if ($kind === 'articles') {
            config(['ai.max_generation_attempts_per_day' => 1]);
            AiPipelineSetting::find(1)->update(['max_generation_attempts_per_day' => 1]);
        } else {
            config(['ai.'.$kind.'_limit_microusd' => 1]);
        }
        $this->artisan('ai:dispatch')->assertSuccessful();
        $this->assertSame('originality_correction_budget_unavailable', $root->fresh()->error_code);
        $this->assertSame('held', $this->candidate->fresh()->automation_status);
        $this->assertSame(1, AiBudgetPeriod::where('period_key', 'like', 'day:%')->sole()->actual_articles);
        $this->assertDatabaseCount('ai_generation_runs', 1);
        $this->assertDatabaseCount('ai_spend_reservations', 1);
        Http::assertSentCount(1);
    }

    public static function budgetFailures(): array
    {
        return [['articles'], ['daily'], ['monthly']];
    }

    #[DataProvider('otherFailures')]
    public function test_mixed_factual_or_provenance_failures_never_qualify_for_correction(string $kind): void
    {
        $root = $this->copiedRun($kind);
        $this->assertSame('critical_quality_failure', $root->error_code);
        $this->artisan('ai:dispatch')->assertSuccessful();
        $this->assertSame(0, $root->children()->count());
        Http::assertSentCount(1);
    }

    public static function otherFailures(): array
    {
        return [['number'], ['entity'], ['id']];
    }

    public function test_emergency_stop_blocks_correction_before_any_new_request_or_reservation(): void
    {
        $root = $this->copiedRun();
        config(['ai.emergency_stop' => true]);
        $this->expectException(AiFailure::class);
        try {
            app(AiDrafting::class)->correctOriginality($this->admin, $root, false);
        } finally {
            $this->assertDatabaseCount('ai_generation_runs', 1);
            $this->assertDatabaseCount('ai_spend_reservations', 1);
            Http::assertSentCount(1);
        }
    }

    public function test_revoked_source_permission_blocks_correction_before_a_request(): void
    {
        $root = $this->copiedRun();
        $this->candidate->references()->first()->source->forceFill(['ai_usage_allowed' => false])->save();
        $this->expectExceptionMessage('source_not_eligible_for_ai');
        try {
            app(AiDrafting::class)->correctOriginality($this->admin, $root, false);
        } finally {
            $this->assertDatabaseCount('ai_generation_runs', 1);
            Http::assertSentCount(1);
        }
    }

    public function test_historical_held_runs_are_not_automatically_retried_by_the_new_dispatcher(): void
    {
        $root = $this->copiedRun();
        $root->forceFill(['error_code' => 'critical_quality_failure'])->save();
        $this->artisan('ai:dispatch')->assertSuccessful();
        $this->assertDatabaseCount('ai_generation_runs', 1);
        Http::assertSentCount(1);
    }

    public function test_stale_evidence_cannot_be_corrected_on_a_later_day(): void
    {
        $root = $this->copiedRun();
        $this->travel(8)->days();
        $this->expectExceptionMessage('outdated_or_undated_evidence');
        try {
            app(AiDrafting::class)->correctOriginality($this->admin, $root, false);
        } finally {
            $this->assertDatabaseCount('ai_generation_runs', 1);
            Http::assertSentCount(1);
        }
    }

    public function test_a_correction_with_an_unknown_provider_outcome_cannot_retry(): void
    {
        $root = $this->copiedRun();
        $child = app(AiDrafting::class)->correctOriginality($this->admin, $root, false);
        $this->fakeProvider([], 503);
        app(AiDrafting::class)->process($child->id, false);
        $this->assertSame(AiRunStatus::Uncertain, $child->fresh()->status);
        $this->assertSame('uncertain', $child->fresh()->reservation->status);
        $this->assertNull($child->fresh()->retry_at);
        $this->artisan('ai:dispatch')->assertSuccessful();
        $this->assertDatabaseCount('ai_generation_runs', 2);
        Http::assertSentCount(1);
    }

    public function test_rate_limiting_of_a_correction_does_not_start_an_extra_retry_chain(): void
    {
        $root = $this->copiedRun();
        $child = app(AiDrafting::class)->correctOriginality($this->admin, $root, false);
        $this->fakeProvider([], 429, ['Retry-After' => '60']);
        app(AiDrafting::class)->process($child->id, false);
        $this->assertSame(AiRunStatus::Failed, $child->fresh()->status);
        $this->assertNull($child->fresh()->retry_at);
        $this->artisan('ai:dispatch')->assertSuccessful();
        $this->assertDatabaseCount('ai_generation_runs', 2);
        $this->assertSame('held', $this->candidate->fresh()->automation_status);
        Http::assertSentCount(1);
    }
}
