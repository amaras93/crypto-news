<?php

namespace Database\Factories;

use App\Models\NewsCandidate;
use App\Models\NewsSource;
use Illuminate\Database\Eloquent\Factories\Factory;

class CandidateSourceReferenceFactory extends Factory
{
    public function definition(): array
    {
        return ['news_candidate_id' => NewsCandidate::factory(), 'news_source_id' => NewsSource::factory(),
            'external_id' => fake()->uuid(), 'external_hash' => hash('sha256', fake()->unique()->uuid()),
            'headline' => fake()->sentence(), 'original_url' => 'https://publisher.com/news/item',
            'language' => 'en', 'first_seen_at' => now(), 'last_seen_at' => now()];
    }
}
