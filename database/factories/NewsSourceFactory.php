<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class NewsSourceFactory extends Factory
{
    public function definition(): array
    {
        return ['name' => 'Approved test publisher', 'website' => 'https://publisher.com/',
            'feed_url' => 'https://publisher.com/feed/'.fake()->uuid(), 'feed_hash' => hash('sha256', fake()->unique()->uuid()),
            'source_type' => 'rss', 'language' => 'en', 'priority' => 3, 'enabled' => true,
            'summary_permitted' => false, 'licensing_notes' => 'TEST ONLY: metadata permitted by fictional fixture.',
            'approved_by' => User::factory()->admin(), 'approved_at' => now(), 'polling_minutes' => 60, 'item_limit' => 25, 'version' => 1];
    }
}
