<?php

namespace Database\Factories;

use App\Models\NewsletterCampaign;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<NewsletterCampaign> */
class NewsletterCampaignFactory extends Factory
{
    public function definition(): array
    {
        return ['locale' => 'en', 'frequency' => 'daily', 'period_start' => now()->utc()->startOfDay()->subDay(), 'period_end' => now()->utc()->startOfDay(),
            'article_ids' => [], 'translation_hashes' => [], 'status' => 'draft', 'audience_cursor' => 0];
    }
}
