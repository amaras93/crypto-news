<?php

namespace Tests;

use App\AiRunStatus;
use App\Models\AiGenerationRun;
use App\Models\AiPipelineSetting;
use App\Models\NewsCandidate;
use App\Models\User;
use App\Support\AiDemoFixtures;
use App\Support\AiDrafting;
use App\Support\AiEditorialReviewService;
use App\Support\AiSemanticVerification;
use App\Support\FakeAiDraftProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

abstract class AiTestCase extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected NewsCandidate $candidate;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Http::preventStrayRequests();
        $fixture = app(AiDemoFixtures::class)->create();
        $this->admin = $fixture['user'];
        $this->candidate = $fixture['candidate'];
    }

    protected function live(): void
    {
        $this->candidate->forceFill(['is_simulated' => false])->save();
        $this->candidate->references()->each(fn ($reference) => $reference->source->forceFill(['is_simulated' => false])->save());
        config(['ai.emergency_stop' => false, 'ai.candidate_processing_enabled' => true, 'ai.generation_enabled' => true,
            'ai.translation_enabled' => true, 'ai.provider' => 'openai', 'ai.model' => 'test-model', 'ai.openai_key' => 'offline-test-credential',
            'ai.daily_limit_microusd' => 10000000, 'ai.monthly_limit_microusd' => 100000000, 'ai.max_articles_per_day' => 10,
            'ai.max_translation_languages' => 7, 'ai.pricing' => ['model' => 'test-model', 'verified_at' => now()->utc()->toDateString(),
                'input' => 750000, 'cached' => 75000, 'write' => 0, 'output' => 4500000]]);
        AiPipelineSetting::updateOrCreate(['id' => 1], [
            'emergency_stop' => false, 'candidate_processing_enabled' => true, 'generation_enabled' => true, 'translation_enabled' => true,
            'provider' => 'openai', 'model' => 'test-model', 'daily_limit_microusd' => 10000000, 'monthly_limit_microusd' => 100000000,
            'max_articles_per_day' => 10, 'max_translation_languages' => 7, 'max_concurrent_jobs' => 1, 'author_id' => $this->admin->id,
        ]);
    }

    protected function pending(bool $simulated = true): AiGenerationRun
    {
        return app(AiDrafting::class)->generate($this->admin, $this->candidate, simulated: $simulated, allowEditorRegeneration: true, dispatch: false);
    }

    protected function complete(AiGenerationRun $run): AiGenerationRun
    {
        $pipeline = app(AiDrafting::class);
        $pipeline->process($run->id, false);
        $this->assertSame(AiRunStatus::Received, $run->fresh()->status, $run->fresh()->error_code ?? '');
        $pipeline->validate($run->id, false);
        $this->assertSame(AiRunStatus::Validated, $run->fresh()->status, json_encode($run->fresh()->quality_report));
        $pipeline->store($run->id);
        $this->assertSame(AiRunStatus::Stored, $run->fresh()->status, $run->fresh()->error_code ?? '');

        return $run->fresh(['article.translations', 'article.aiRun', 'candidate']);
    }

    protected function approveEnglish(AiGenerationRun $run): void
    {
        app(AiEditorialReviewService::class)->review($this->admin, $run->article, 'en', true, true,
            'Checked every factual claim, entity, source permission and attribution against the original source evidence.');
    }

    protected function response(AiGenerationRun $run, ?array $document = null, ?array $usage = null): array
    {
        return ['id' => 'resp_offline_test', 'status' => 'completed',
            'output' => [['type' => 'message', 'content' => [['type' => 'output_text',
                'text' => json_encode($document ?? app(FakeAiDraftProvider::class)->fixture($run), JSON_UNESCAPED_UNICODE)]]]],
            'usage' => $usage ?? ['input_tokens' => 800, 'input_tokens_details' => ['cached_tokens' => 200], 'output_tokens' => 400]];
    }

    protected function verifiedDocument(AiGenerationRun $run): array
    {
        $target = $run->article->translation($run->locale)->aiRun->output_document;
        $claims = collect($run->evidence_snapshot['claims'])->keyBy('id');
        $assessments = [];
        foreach (app(AiSemanticVerification::class)->assertions($target) as $assertion) {
            $id = $assertion['claim_ids'][0];
            $assessments[] = ['field' => $assertion['field'], 'verdict' => 'supported',
                'evidence' => [['claim_id' => $id, 'quote' => $claims[$id]['text']]], 'reason' => 'Supported by the supplied primary source.'];
        }

        return ['version' => 'verification-v2', 'supported' => true, 'faithful_translation' => true,
            'original' => true, 'correct_language' => true, 'assessments' => $assessments, 'issues' => []];
    }

    protected function fakeProvider(array $body, int $status = 200, array $headers = []): void
    {
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(['api.openai.com/*' => Http::response($body, $status, $headers)]);
    }
}
