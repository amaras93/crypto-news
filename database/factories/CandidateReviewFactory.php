<?php

namespace Database\Factories;

use App\Models\NewsCandidate;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class CandidateReviewFactory extends Factory
{
    public function definition(): array
    {
        return ['news_candidate_id' => NewsCandidate::factory(), 'user_id' => User::factory()->editor(),
            'previous_status' => 'new', 'new_status' => 'approved_for_draft', 'notes' => 'Test-only review.'];
    }
}
