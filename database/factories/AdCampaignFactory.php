<?php

namespace Database\Factories;

use App\Models\AdCampaign;
use App\Models\Advertiser;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<AdCampaign> */
class AdCampaignFactory extends Factory
{
    public function definition(): array
    {
        return ['advertiser_id' => Advertiser::factory(), 'name' => 'Isolated advertising fixture', 'banner_image' => 'advertising/creatives/'.Str::uuid().'.png',
            'creative_hash' => str_repeat('0', 64), 'creative_width' => 728, 'creative_height' => 90, 'destination_url' => 'https://example.com/campaign',
            'languages' => ['en'], 'alt_text' => ['en' => 'Illustrative campaign fixture'], 'starts_at' => now()->subDay(), 'ends_at' => now()->addDay(),
            'status' => 'draft', 'risk_category' => 'general', 'priority' => 0, 'currency' => 'USD'];
    }
}
