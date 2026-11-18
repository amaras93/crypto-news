<?php

namespace Tests\Feature;

use App\AiRunStatus;
use App\Jobs\GenerateAiDraft;
use App\Jobs\StoreAiDraft;
use App\Jobs\ValidateAiDraft;
use App\Models\AiBudgetPeriod;
use App\Models\AiPipelineSetting;
use App\Support\AiDemoFixtures;
use App\Support\AiDrafting;
use App\Support\AiFailure;
use App\Support\AiSettings;
use App\Support\NewsDiscovery;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\AiTestCase;

class AiRecoveryTest extends AiTestCase
{
    public function test_atomic_run_lock_prevents_a_duplicate_worker_from_reserving_or_sending(): void
    {
        $this->live();
        $run = $this->pending(false);
        $lock = app(NewsDiscovery::class)->cache()->lock('ai:run:'.$run->id, 90);
        $this->assertTrue($lock->get());
        app(AiDrafting::class)->process($run->id, false);
        $this->assertSame(AiRunStatus::Queued, $run->fresh()->status);
        $this->assertDatabaseCount('ai_spend_reservations', 0);
        Http::assertNothingSent();
        $lock->release();
    }

    public function test_shared_concurrency_slot_contention_leaves_work_pending_without_spending(): void
    {
        $this->live();
        $run = $this->pending(false);
        $lock = app(NewsDiscovery::class)->cache()->lock('ai:slot:0', 90);
        $this->assertTrue($lock->get());
        app(AiDrafting::class)->process($run->id, false);
        $this->assertSame(AiRunStatus::Queued, $run->fresh()->status);
        $this->assertDatabaseCount('ai_spend_reservations', 0);
        Http::assertNothingSent();
        $lock->release();
        $this->fakeProvider($this->response($run));
        $this->complete($run);
        Http::assertSentCount(1);
    }

    public function test_outstanding_request_state_also_enforces_the_current_lowered_concurrency_limit(): void
    {
        $first = $this->pending();
        $first->forceFill(['status' => AiRunStatus::Requesting])->save();
        $other = app(AiDemoFixtures::class)->create();
        $next = app(AiDrafting::class)->generate($other['user'], $other['candidate'], simulated: true, dispatch: false);
        app(AiDrafting::class)->process($next->id, false);
        $this->assertSame(AiRunStatus::Queued, $next->fresh()->status);
        $this->assertDatabaseCount('articles', 0);
        Http::assertNothingSent();
    }

    public function test_network_request_runs_outside_a_database_transaction(): void
    {
        $this->live();
        $run = $this->pending(false);
        $baselineTransactionLevel = DB::transactionLevel();
        Http::fake(function () use ($run, $baselineTransactionLevel) {
            $this->assertSame($baselineTransactionLevel, DB::transactionLevel());
            $this->assertSame(AiRunStatus::Requesting, $run->fresh()->status);
            $this->assertNotNull($run->fresh()->request_sent_at);

            return Http::response($this->response($run));
        });
        $this->complete($run);
    }

    public function test_pipeline_jobs_are_separate_unique_bounded_and_idempotent(): void
    {
        $run = app(AiDrafting::class)->generate($this->admin, $this->candidate, simulated: true);
        Queue::assertPushed(GenerateAiDraft::class, 1);
        app(AiDrafting::class)->process($run->id);
        Queue::assertPushed(ValidateAiDraft::class, 1);
        app(AiDrafting::class)->validate($run->id);
        Queue::assertPushed(StoreAiDraft::class, 1);
        app(AiDrafting::class)->store($run->id);
        foreach ([new GenerateAiDraft($run->id), new ValidateAiDraft($run->id), new StoreAiDraft($run->id)] as $job) {
            $this->assertSame(1, $job->tries);
            $this->assertLessThan(90, $job->timeout);
            $this->assertSame('ai', $job->queue);
            $job->handle(app(AiDrafting::class));
        }
        $this->assertDatabaseCount('articles', 1);
        $this->assertDatabaseCount('article_translations', 1);
    }

    public function test_received_response_survives_worker_failure_and_can_resume_without_another_call(): void
    {
        $this->live();
        $run = $this->pending(false);
        $this->fakeProvider($this->response($run));
        app(AiDrafting::class)->process($run->id, false);
        (new GenerateAiDraft($run->id))->failed(new \RuntimeException('worker interrupted'));
        $this->assertSame(AiRunStatus::Received, $run->fresh()->status);
        app(AiDrafting::class)->validate($run->id, false);
        app(AiDrafting::class)->store($run->id);
        $this->assertSame(AiRunStatus::Stored, $run->fresh()->status);
        Http::assertSentCount(1);
    }

    public function test_emergency_stop_allows_a_received_response_to_finish_private_validation_and_storage(): void
    {
        $this->live();
        $run = $this->pending(false);
        $this->fakeProvider($this->response($run));
        app(AiDrafting::class)->process($run->id, false);
        app(AiSettings::class)->stop($this->admin);
        app(AiDrafting::class)->validate($run->id, false);
        app(AiDrafting::class)->store($run->id);
        $this->assertSame(AiRunStatus::Stored, $run->fresh()->status);
        $this->assertNull($run->fresh()->article->published_at);
        Http::assertSentCount(1);
    }

    public function test_rate_limited_retries_honor_retry_after_backoff_and_attempt_ceiling(): void
    {
        $this->live();
        $pipeline = app(AiDrafting::class);
        $run = $this->pending(false);
        for ($attempt = 1; $attempt <= 3; $attempt++) {
            $this->fakeProvider(['error' => ['message' => 'fixture rate limit']], 429, ['Retry-After' => '120']);
            $pipeline->process($run->id, false);
            $this->assertSame(AiRunStatus::Failed, $run->fresh()->status);
            $this->assertSame('released', $run->fresh()->reservation->status);
            Http::assertSentCount(1);
            try {
                $pipeline->retry($this->admin, $run, false);
                $this->fail('Retry permitted before Retry-After');
            } catch (AiFailure $failure) {
                $this->assertSame('retry_not_safe_or_due', $failure->reason);
            }
            $this->travel(241)->seconds();
            if ($attempt < 3) {
                $old = $run;
                $run = $pipeline->retry($this->admin, $old, false);
                $this->assertSame($attempt + 1, $run->attempt);
                $this->assertSame($old->id, $run->parent_run_id);
                try {
                    $pipeline->retry($this->admin, $old, false);
                    $this->fail('Duplicate retry created');
                } catch (AiFailure) {
                    $this->assertDatabaseCount('ai_generation_runs', $attempt + 1);
                }
            }
        }
        try {
            $pipeline->retry($this->admin, $run, false);
            $this->fail('Fourth paid attempt allowed');
        } catch (AiFailure $failure) {
            $this->assertSame('retry_not_safe_or_due', $failure->reason);
        }
        $this->assertSame(0, AiBudgetPeriod::first()->reserved_microusd);
    }

    public function test_retry_after_http_date_is_respected(): void
    {
        $this->live();
        $run = $this->pending(false);
        $this->fakeProvider([], 429, ['Retry-After' => now()->addMinutes(10)->toRfc7231String()]);
        app(AiDrafting::class)->process($run->id, false);
        $this->assertGreaterThanOrEqual(now()->addMinutes(9)->timestamp, $run->fresh()->retry_at->timestamp);
    }

    public function test_daily_article_allowance_counts_one_english_article_and_not_seven_translations(): void
    {
        $this->live();
        config(['ai.max_articles_per_day' => 1]);
        AiPipelineSetting::find(1)->update(['max_articles_per_day' => 1]);
        $run = $this->pending(false);
        $this->fakeProvider($this->response($run));
        $run = $this->complete($run);
        $this->approveEnglish($run);
        foreach (app(AiDrafting::class)->translate($this->admin, $run->article, ['ru', 'fr', 'de', 'es', 'it', 'ja', 'zh'], dispatch: false) as $next) {
            $this->fakeProvider($this->response($next));
            $this->complete($next);
            $this->assertFalse($next->fresh()->reservation->counts_article);
        }
        $day = AiBudgetPeriod::where('period_key', 'day:'.now()->utc()->toDateString())->first();
        $this->assertSame(1, $day->actual_articles);
        $this->assertSame(8 * 2265, $day->actual_microusd);
        $this->assertSame(0, $day->reserved_microusd);
        $this->assertCount(8, $run->article->fresh(['translations'])->translations);
    }

    public function test_scheduling_requires_both_explicit_flags_and_never_creates_publication_jobs(): void
    {
        $this->live();
        AiPipelineSetting::find(1)->update(['scheduled_generation_enabled' => true, 'updated_by' => $this->admin->id]);
        $this->artisan('ai:dispatch')->assertSuccessful();
        $this->assertDatabaseCount('ai_generation_runs', 0);
        config(['ai.scheduled_generation_enabled' => true]);
        $this->artisan('ai:dispatch')->assertSuccessful();
        $this->assertDatabaseCount('ai_generation_runs', 1);
        $this->artisan('ai:dispatch')->assertSuccessful();
        $this->assertDatabaseCount('ai_generation_runs', 1);
        $this->assertDatabaseCount('articles', 0);
        Queue::assertPushed(GenerateAiDraft::class);
        Http::assertNothingSent();
    }

    public function test_crashed_request_is_marked_uncertain_instead_of_being_reissued_by_the_scheduler(): void
    {
        $this->live();
        $run = $this->pending(false);
        Http::fake(['*' => Http::failedConnection()]);
        app(AiDrafting::class)->process($run->id, false);
        $run->refresh()->forceFill(['status' => AiRunStatus::Requesting, 'completed_at' => null, 'updated_at' => now()->subMinutes(6)])->save();
        $this->artisan('ai:dispatch')->assertSuccessful();
        $this->assertSame(AiRunStatus::Uncertain, $run->fresh()->status);
        $this->assertSame('uncertain', $run->fresh()->reservation->status);
        Http::assertSentCount(1);
    }

    public function test_real_provider_can_never_use_simulated_source_evidence(): void
    {
        $this->live();
        $this->candidate->forceFill(['is_simulated' => true])->save();
        $this->expectException(AiFailure::class);
        $this->pending(false);
    }

    public function test_extreme_retry_after_is_not_clamped_to_an_earlier_automatic_retry(): void
    {
        $this->live();
        $run = $this->pending(false);
        $this->fakeProvider([], 429, ['Retry-After' => '999999999999999999']);
        app(AiDrafting::class)->process($run->id, false);
        $this->assertSame('provider_retry_requires_manual_review', $run->fresh()->error_code);
        $this->assertNull($run->fresh()->retry_at);
        $this->assertSame('released', $run->fresh()->reservation->status);
    }

    public function test_fake_generation_is_unavailable_in_production(): void
    {
        app()->instance('env', 'production');
        $this->expectException(AiFailure::class);
        $this->pending();
    }
}
