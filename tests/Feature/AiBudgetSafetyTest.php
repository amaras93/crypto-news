<?php

namespace Tests\Feature;

use App\Models\AiBudgetPeriod;
use App\Models\NewsCandidate;
use App\Models\User;
use App\Support\AiBudgetLedger;
use App\Support\AiDrafting;
use App\Support\AiDraftProvider;
use App\Support\AiFailure;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class AiBudgetSafetyTest extends TestCase
{
    use RefreshDatabase;

    private function allowAccounting(): void
    {
        config(['ai.emergency_stop' => false, 'ai.daily_limit_microusd' => 1000,
            'ai.monthly_limit_microusd' => 5000, 'ai.max_articles_per_day' => 2]);
    }

    public function test_partial_configuration_changes_never_implicitly_activate_paid_generation(): void
    {
        Http::preventStrayRequests();
        $this->assertFalse(config('ai.generation_enabled'));
        $this->assertFalse(config('ai.automatic_publishing'));
        $this->assertTrue(config('ai.emergency_stop'));
        $this->assertFalse(app()->bound(AiDraftProvider::class));
        config(['ai.generation_enabled' => true, 'ai.automatic_publishing' => true, 'ai.emergency_stop' => false]);
        $service = app(AiDrafting::class);
        $this->assertFalse($service->isOperational());
        try {
            $service->generate(User::factory()->admin()->create(), NewsCandidate::factory()->approvedForDraft()->create());
            $this->fail('Generation became operational');
        } catch (AiFailure $error) {
            $this->assertSame('generation_stopped', $error->reason);
        }
        $this->assertDatabaseCount('articles', 0);
        Http::assertNothingSent();
    }

    public function test_default_emergency_stop_blocks_reservations_and_writes_nothing(): void
    {
        $this->expectException(ValidationException::class);
        try {
            app(AiBudgetLedger::class)->reserve(User::factory()->admin()->create(), NewsCandidate::factory()->approvedForDraft()->create(), Str::uuid()->toString(), 100);
        } finally {
            $this->assertDatabaseCount('ai_spend_reservations', 0);
            $this->assertDatabaseCount('ai_budget_periods', 0);
        }
    }

    public function test_reservations_are_atomic_idempotent_and_distinct_from_actual_spending(): void
    {
        $this->allowAccounting();
        $admin = User::factory()->admin()->create();
        $candidate = NewsCandidate::factory()->approvedForDraft()->create();
        $ledger = app(AiBudgetLedger::class);
        $key = Str::uuid()->toString();
        $reservation = $ledger->reserve($admin, $candidate, $key, 600);
        $this->assertSame($reservation->id, $ledger->reserve($admin, $candidate, $key, 600)->id);
        $this->assertDatabaseCount('ai_spend_reservations', 1);
        $this->assertDatabaseCount('ai_budget_periods', 2);
        foreach (AiBudgetPeriod::all() as $period) {
            $this->assertSame(600, $period->reserved_microusd);
            $this->assertSame(0, $period->actual_microusd);
        }
        try {
            $ledger->reserve($admin, $candidate, Str::uuid()->toString(), 600);
            $this->fail('Oversubscribed budget');
        } catch (ValidationException) {
            $this->assertDatabaseCount('ai_spend_reservations', 1);
        }
        $settled = $ledger->settle($admin, $reservation, 400, 100, 50);
        $this->assertSame('settled', $settled->status);
        $ledger->settle($admin, $reservation, 400, 100, 50);
        foreach (AiBudgetPeriod::all() as $period) {
            $this->assertSame(0, $period->reserved_microusd);
            $this->assertSame(400, $period->actual_microusd);
            $this->assertSame(1, $period->actual_articles);
        }
        $this->assertDatabaseCount('articles', 0);
    }

    public function test_release_is_idempotent_and_finalized_accounting_cannot_be_rewritten(): void
    {
        $this->allowAccounting();
        $admin = User::factory()->admin()->create();
        $ledger = app(AiBudgetLedger::class);
        $reservation = $ledger->reserve($admin, NewsCandidate::factory()->approvedForDraft()->create(), Str::uuid()->toString(), 300);
        $reservation->day_key = 'day:forged';
        $ledger->release($admin, $reservation);
        $ledger->release($admin, $reservation);
        $this->assertSame(0, AiBudgetPeriod::sum('reserved_microusd'));
        $this->assertSame(0, AiBudgetPeriod::sum('actual_microusd'));
        $this->assertDatabaseCount('ai_budget_periods', 2);
        $this->expectException(ValidationException::class);
        $ledger->settle($admin, $reservation, 20);
    }

    public function test_actual_overruns_are_recorded_honestly_and_block_further_reservations(): void
    {
        $this->allowAccounting();
        $admin = User::factory()->admin()->create();
        $candidate = NewsCandidate::factory()->approvedForDraft()->create();
        $ledger = app(AiBudgetLedger::class);
        $reservation = $ledger->reserve($admin, $candidate, Str::uuid()->toString(), 500);
        $ledger->settle($admin, $reservation, 1200);
        $this->assertSame(1200, AiBudgetPeriod::where('period_key', 'like', 'day:%')->sole()->actual_microusd);
        $this->expectException(ValidationException::class);
        $ledger->reserve($admin, $candidate, Str::uuid()->toString(), 1);
    }

    public function test_monthly_and_article_limits_cannot_be_bypassed_by_multiple_request_keys(): void
    {
        $this->allowAccounting();
        config(['ai.monthly_limit_microusd' => 200, 'ai.max_articles_per_day' => 1]);
        $admin = User::factory()->admin()->create();
        $candidate = NewsCandidate::factory()->approvedForDraft()->create();
        $ledger = app(AiBudgetLedger::class);
        try {
            $ledger->reserve($admin, $candidate, Str::uuid()->toString(), 300);
            $this->fail('Monthly limit bypassed');
        } catch (ValidationException) {
            $this->assertDatabaseCount('ai_spend_reservations', 0);
        }
        $ledger->reserve($admin, $candidate, Str::uuid()->toString(), 100);
        $this->expectException(ValidationException::class);
        $ledger->reserve($admin, $candidate, Str::uuid()->toString(), 1);
    }

    public function test_the_daily_allowance_resets_only_at_the_next_utc_day_without_erasing_monthly_spend(): void
    {
        $this->allowAccounting();
        config(['ai.max_articles_per_day' => 1]);
        $this->travelTo(CarbonImmutable::parse('2026-11-19T23:59:00Z'));
        $actor = User::factory()->admin()->create();
        $candidate = NewsCandidate::factory()->approvedForDraft()->create();
        $ledger = app(AiBudgetLedger::class);
        $reservation = $ledger->reserve($actor, $candidate, Str::uuid()->toString(), 100);
        $ledger->settle($actor, $reservation, 80);
        try {
            $ledger->reserve($actor, $candidate, Str::uuid()->toString(), 100);
            $this->fail('A second article was admitted before midnight UTC.');
        } catch (ValidationException) {
            $this->assertDatabaseCount('ai_spend_reservations', 1);
        }
        $this->travelTo(CarbonImmutable::parse('2026-11-20T00:00:00Z'));
        $next = $ledger->reserve($actor, $candidate, Str::uuid()->toString(), 100);
        $this->assertSame('day:2026-11-20', $next->day_key);
        $this->assertSame(1, AiBudgetPeriod::where('period_key', 'day:2026-11-19')->sole()->actual_articles);
        $this->assertSame(80, AiBudgetPeriod::where('period_key', 'month:2026-11')->sole()->actual_microusd);
    }

    public function test_readers_and_editors_cannot_reserve_budget(): void
    {
        $this->allowAccounting();
        $candidate = NewsCandidate::factory()->approvedForDraft()->create();
        foreach ([User::factory()->create(), User::factory()->editor()->create()] as $actor) {
            try {
                app(AiBudgetLedger::class)->reserve($actor, $candidate, Str::uuid()->toString(), 100);
                $this->fail('Budget role boundary bypassed');
            } catch (HttpException $error) {
                $this->assertSame(403, $error->getStatusCode());
            }
        }
        $this->assertDatabaseCount('ai_spend_reservations', 0);
    }

    public function test_unapproved_candidates_and_conflicting_idempotency_keys_are_rejected(): void
    {
        $this->allowAccounting();
        $admin = User::factory()->admin()->create();
        $ledger = app(AiBudgetLedger::class);
        try {
            $ledger->reserve($admin, NewsCandidate::factory()->create(), Str::uuid()->toString(), 100);
            $this->fail('Unapproved work reserved');
        } catch (ValidationException) {
            $this->assertDatabaseCount('ai_spend_reservations', 0);
        }
        $key = Str::uuid()->toString();
        $candidate = NewsCandidate::factory()->approvedForDraft()->create();
        $ledger->reserve($admin, $candidate, $key, 100);
        $this->expectException(ValidationException::class);
        $ledger->reserve($admin, $candidate, $key, 200);
    }
}
