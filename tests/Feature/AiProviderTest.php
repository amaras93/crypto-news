<?php

namespace Tests\Feature;

use App\AiRunStatus;
use App\Models\AiBudgetPeriod;
use App\Models\AiPipelineSetting;
use App\Models\User;
use App\Support\AiDrafting;
use App\Support\AiFailure;
use App\Support\AiPricing;
use App\Support\AiSettings;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\AiTestCase;

class AiProviderTest extends AiTestCase
{
    public function test_safe_defaults_and_environment_database_switches_both_prevent_paid_requests(): void
    {
        $this->assertFalse(app(AiDrafting::class)->isOperational());
        $this->assertFalse(config('ai.automatic_publishing'));
        foreach (['candidate_processing_enabled', 'generation_enabled', 'translation_enabled'] as $flag) {
            $this->live();
            config(['ai.'.$flag => false]);
            $task = $flag === 'translation_enabled' ? 'translation' : 'english';
            $this->assertFalse(app(AiSettings::class)->enabledFor($task));
            config(['ai.'.$flag => true]);
            AiPipelineSetting::find(1)->update([$flag => false]);
            $this->assertFalse(app(AiSettings::class)->enabledFor($task));
        }
        Http::assertNothingSent();
    }

    public function test_server_and_database_emergency_stops_are_authoritative(): void
    {
        $this->live();
        $run = $this->pending(false);
        app(AiSettings::class)->stop($this->admin);
        app(AiDrafting::class)->process($run->id, false);
        $this->assertSame('generation_stopped', $run->fresh()->error_code);
        $this->assertDatabaseCount('ai_spend_reservations', 0);
        Http::assertNothingSent();
    }

    public function test_missing_credentials_block_before_a_run_or_reservation(): void
    {
        $this->live();
        config(['ai.openai_key' => '']);
        try {
            $this->pending(false);
            $this->fail('Missing credentials accepted');
        } catch (AiFailure $failure) {
            $this->assertSame('missing_credentials', $failure->reason);
        }
        $this->assertDatabaseCount('ai_generation_runs', 0);
        Http::assertNothingSent();
    }

    public function test_responses_api_uses_strict_schema_fixed_endpoint_minimal_evidence_and_no_hidden_retry(): void
    {
        $this->live();
        $run = $this->pending(false);
        Http::fake(['api.openai.com/*' => Http::response($this->response($run))]);
        $this->complete($run);
        Http::assertSentCount(1);
        Http::assertSent(function (Request $request): bool {
            $payload = $request->data();
            $encoded = json_encode($payload);
            $this->assertSame('https://api.openai.com/v1/responses', $request->url());
            $this->assertTrue($request->hasHeader('Authorization', 'Bearer offline-test-credential'));
            $this->assertSame('test-model', $payload['model']);
            $this->assertFalse($payload['store']);
            $this->assertFalse($payload['background']);
            $this->assertSame([], $payload['tools']);
            $this->assertSame('json_schema', $payload['text']['format']['type']);
            $this->assertTrue($payload['text']['format']['strict']);
            $this->assertFalse($payload['text']['format']['schema']['additionalProperties']);
            $this->assertStringNotContainsString($this->admin->email, $encoded);
            $this->assertStringNotContainsString('review_notes', $encoded);
            $this->assertStringNotContainsString('licensing_notes', $encoded);
            $this->assertStringNotContainsString('password', $payload['input'][1]['content']);

            return true;
        });
    }

    public static function httpFailures(): array
    {
        return [[400, 'provider_configuration_rejected', false], [401, 'invalid_credentials', false],
            [403, 'provider_configuration_rejected', false], [404, 'provider_configuration_rejected', false],
            [422, 'provider_configuration_rejected', false], [429, 'provider_rate_limited', false],
            [500, 'provider_outcome_unknown', true], [503, 'provider_outcome_unknown', true]];
    }

    #[DataProvider('httpFailures')]
    public function test_provider_failures_release_or_hold_budget_without_exposing_raw_errors(int $status, string $code, bool $uncertain): void
    {
        $this->live();
        $run = $this->pending(false);
        Http::fake(['*' => Http::response(['error' => ['message' => 'SECRET raw provider body']], $status, ['Retry-After' => '120'])]);
        app(AiDrafting::class)->process($run->id, false);
        $run->refresh();
        $this->assertSame($code, $run->error_code);
        $this->assertSame($uncertain ? AiRunStatus::Uncertain : AiRunStatus::Failed, $run->status);
        $this->assertSame($uncertain ? 'uncertain' : 'released', $run->reservation->status);
        $this->assertNull($run->output_document);
        if ($status === 429) {
            $this->assertGreaterThanOrEqual(now()->addSeconds(119)->timestamp, $run->retry_at->timestamp);
        }
        if ($uncertain) {
            $this->assertGreaterThan(0, AiBudgetPeriod::where('period_key', 'day:'.now()->utc()->toDateString())->value('reserved_microusd'));
        }
        Http::assertSentCount(1);
    }

    public static function responseFailures(): array
    {
        return [['refusal', 'provider_refusal'], ['incomplete', 'incomplete_response'], ['bad-json', 'invalid_structured_output'],
            ['wrong-language', 'invalid_structured_output'], ['extra-key', 'invalid_structured_output'], ['markup', 'invalid_structured_output']];
    }

    #[DataProvider('responseFailures')]
    public function test_refusals_incomplete_and_untrusted_documents_never_create_an_article(string $kind, string $code): void
    {
        $this->live();
        $run = $this->pending(false);
        $body = $this->response($run);
        if ($kind === 'refusal') {
            $body['output'][0]['content'] = [['type' => 'refusal', 'refusal' => 'No']];
        } elseif ($kind === 'incomplete') {
            $body['status'] = 'incomplete';
        } elseif ($kind === 'bad-json') {
            $body['output'][0]['content'][0]['text'] = '{broken';
        } else {
            $doc = json_decode($body['output'][0]['content'][0]['text'], true);
            if ($kind === 'wrong-language') {
                $doc['language'] = 'ru';
            }
            if ($kind === 'extra-key') {
                $doc['unexpected'] = 'not allowed';
            }
            if ($kind === 'markup') {
                $doc['sections'][0]['paragraphs'][0]['text'] = '<script>alert(1)</script>';
            }
            $body['output'][0]['content'][0]['text'] = json_encode($doc);
        }
        Http::fake(['*' => Http::response($body)]);
        $pipeline = app(AiDrafting::class);
        $pipeline->process($run->id, false);
        $pipeline->validate($run->id, false);
        $pipeline->store($run->id);
        $this->assertSame(AiRunStatus::Failed, $run->fresh()->status);
        $this->assertSame($code, $run->fresh()->error_code);
        $this->assertSame('settled', $run->fresh()->reservation->status);
        $this->assertDatabaseCount('articles', 0);
        Http::assertSentCount(1);
    }

    public function test_timeout_and_missing_usage_remain_uncertain_and_cannot_retry(): void
    {
        $this->live();
        $run = $this->pending(false);
        Http::fake(['*' => Http::failedConnection()]);
        app(AiDrafting::class)->process($run->id, false);
        $this->assertSame(AiRunStatus::Uncertain, $run->fresh()->status);
        app(AiDrafting::class)->process($run->id, false);
        $this->expectException(AiFailure::class);
        app(AiDrafting::class)->retry($this->admin, $run);
    }

    public function test_unreliable_usage_holds_a_reservation_instead_of_inventing_zero_cost(): void
    {
        $this->live();
        $run = $this->pending(false);
        $body = $this->response($run);
        unset($body['usage']);
        Http::fake(['*' => Http::response($body)]);
        app(AiDrafting::class)->process($run->id, false);
        $this->assertSame(AiRunStatus::Uncertain, $run->fresh()->status);
        $this->assertNull($run->fresh()->actual_microusd);
        $this->assertDatabaseCount('articles', 0);
    }

    public function test_unverified_prices_and_exhausted_budget_block_requests_before_http(): void
    {
        $this->live();
        config(['ai.pricing.verified_at' => now()->subDays(31)->toDateString()]);
        $run = $this->pending(false);
        app(AiDrafting::class)->process($run->id, false);
        $this->assertSame('pricing_not_verified', $run->fresh()->error_code);
        config(['ai.pricing.verified_at' => now()->utc()->toDateString(), 'ai.daily_limit_microusd' => 1]);
        $next = $this->pending(false);
        app(AiDrafting::class)->process($next->id, false);
        $this->assertSame(AiRunStatus::Failed, $next->fresh()->status);
        $this->assertDatabaseCount('ai_spend_reservations', 0);
        Http::assertNothingSent();
    }

    public function test_cached_and_cache_write_accounting_is_exact_and_inconsistent_usage_is_not_accepted(): void
    {
        $pricing = app(AiPricing::class);
        $rates = ['input' => 1000000, 'cached' => 100000, 'write' => 2000000, 'output' => 3000000];
        $this->assertSame(591, $pricing->cost(['input_tokens' => 100, 'cached_input_tokens' => 10, 'cache_write_tokens' => 20, 'output_tokens' => 160], $rates));
        $this->expectException(AiFailure::class);
        $pricing->cost(['input_tokens' => 100, 'cached_input_tokens' => 101, 'output_tokens' => 1], $rates);
    }

    public function test_another_current_administrator_can_reconcile_after_the_billing_administrator_is_demoted(): void
    {
        $this->live();
        $run = $this->pending(false);
        Http::fake(['*' => Http::failedConnection()]);
        app(AiDrafting::class)->process($run->id, false);
        $replacement = User::factory()->admin()->create();
        $this->admin->forceFill(['is_admin' => false])->save();
        app(AiDrafting::class)->reconcile($replacement, $run, ['input_tokens' => 800, 'cached_input_tokens' => 200,
            'cache_write_tokens' => 0, 'output_tokens' => 400], 'Verified provider billing after the authorizing administrator lost their administrative role.');
        $this->assertSame('settled', $run->fresh()->reservation->status);
        $this->assertSame($replacement->id, $run->fresh()->quality_report['reconciliation']['by']);
        $this->assertSame($this->admin->id, $run->fresh()->billing_user_id);
    }

    public function test_administrator_can_reconcile_verified_usage_but_no_automatic_retry_is_enabled(): void
    {
        $this->live();
        $run = $this->pending(false);
        Http::fake(['*' => Http::failedConnection()]);
        app(AiDrafting::class)->process($run->id, false);
        app(AiDrafting::class)->reconcile($this->admin, $run, ['input_tokens' => 800, 'cached_input_tokens' => 200,
            'cache_write_tokens' => 0, 'output_tokens' => 400], 'Manually verified exact token usage against the provider billing records for this request.');
        $run->refresh();
        $this->assertSame(2265, $run->actual_microusd);
        $this->assertSame('settled', $run->reservation->status);
        $this->assertNull($run->retry_at);
        $this->assertNull($run->active_key);
        $this->assertSame($this->admin->id, $run->quality_report['reconciliation']['by']);
    }
}
