<?php

namespace Database\Factories;

use App\CandidateStatus;
use Illuminate\Database\Eloquent\Factories\Factory;

class NewsCandidateFactory extends Factory
{
    public function definition(): array
    {
        return ['headline' => fake()->sentence(10), 'original_url' => 'https://publisher.com/news/'.fake()->uuid(),
            'url_hash' => hash('sha256', fake()->unique()->uuid()), 'headline_hash' => hash('sha256', fake()->unique()->uuid()),
            'content_fingerprint' => hash('sha256', fake()->unique()->uuid()), 'language' => 'en',
            'published_at' => now()->subHour(), 'discovered_at' => now(), 'classification' => [],
            'status' => CandidateStatus::New];
    }

    public function approvedForDraft(): static
    {
        return $this->state(fn (array $attributes): array => ['status' => CandidateStatus::ApprovedForDraft]);
    }
}
