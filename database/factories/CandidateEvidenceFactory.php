<?php

namespace Database\Factories;

use App\Models\CandidateEvidence;
use App\Models\CandidateSourceReference;
use App\Models\NewsCandidate;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<CandidateEvidence> */
class CandidateEvidenceFactory extends Factory
{
    public function definition(): array
    {
        return ['claim_key' => Str::uuid()->toString(), 'news_candidate_id' => NewsCandidate::factory(),
            'claim' => 'This fictional test claim represents an editor-entered factual assertion and must be supported by separately approved evidence before drafting.',
            'reference_ids' => fn (array $attributes): array => [CandidateSourceReference::factory()->create(['news_candidate_id' => $attributes['news_candidate_id']])->id],
            'entities' => ['Fictional test entity'], 'figures' => [], 'material' => true,
            'review_notes' => 'Fictional testing assertion; production eligibility must be checked separately.',
            'verified_by' => User::factory()->editor(), 'verified_at' => now()];
    }
}
