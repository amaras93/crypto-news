<?php

namespace Database\Factories;

use App\Models\AdDeliveryTotal;
use App\Models\AdPlacement;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<AdDeliveryTotal> */
class AdDeliveryTotalFactory extends Factory
{
    public function definition(): array
    {
        return ['aggregate_key' => hash('sha256', fake()->uuid()), 'day' => now()->utc()->toDateString(), 'ad_placement_id' => AdPlacement::factory(),
            'ad_campaign_id' => null, 'locale' => 'en', 'requests' => 0, 'eligible_deliveries' => 0];
    }
}
