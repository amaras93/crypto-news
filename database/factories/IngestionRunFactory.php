<?php

namespace Database\Factories;

use App\IngestionStatus;
use App\Models\NewsSource;
use Illuminate\Database\Eloquent\Factories\Factory;

class IngestionRunFactory extends Factory
{
    public function definition(): array
    {
        return ['news_source_id' => NewsSource::factory(), 'token' => fake()->uuid(), 'source_version' => 1,
            'status' => IngestionStatus::Queued, 'item_limit' => 25, 'queued_at' => now(), 'attempts' => 1];
    }
}
