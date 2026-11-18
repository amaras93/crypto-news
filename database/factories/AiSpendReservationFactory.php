<?php

namespace Database\Factories;

use App\Models\NewsCandidate;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class AiSpendReservationFactory extends Factory
{
    public function definition(): array
    {
        return ['request_key' => fake()->uuid(), 'news_candidate_id' => NewsCandidate::factory()->approvedForDraft(),
            'user_id' => User::factory()->admin(), 'day_key' => 'day:'.now()->utc()->toDateString(),
            'month_key' => 'month:'.now()->utc()->format('Y-m'), 'provider' => 'test-only', 'model' => 'fictional',
            'estimated_microusd' => 100, 'status' => 'reserved'];
    }
}
