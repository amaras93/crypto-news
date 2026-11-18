<?php

namespace Database\Factories;

use App\AiRunStatus;
use App\Models\AiGenerationRun;
use App\Models\NewsCandidate;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<AiGenerationRun> */
class AiGenerationRunFactory extends Factory
{
    public function definition(): array
    {
        return ['request_key' => Str::uuid()->toString(), 'work_hash' => hash('sha256', fake()->uuid()),
            'news_candidate_id' => NewsCandidate::factory()->approvedForDraft()->state(['is_simulated' => true]),
            'requested_by' => User::factory()->admin(),
            'billing_user_id' => fn (array $attributes): int => $attributes['requested_by'],
            'author_id' => fn (array $attributes): int => $attributes['requested_by'],
            'task' => 'english', 'locale' => 'en', 'provider' => 'fake', 'model' => 'offline-fixture-v1', 'is_simulated' => true,
            'status' => AiRunStatus::Queued, 'evidence_digest' => hash('sha256', 'incomplete-test-evidence'),
            'evidence_snapshot' => ['claims' => [], 'is_simulated' => true]];
    }
}
