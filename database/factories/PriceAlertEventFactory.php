<?php

namespace Database\Factories;

use App\Models\PriceAlert;
use App\Models\PriceAlertEvent;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<PriceAlertEvent> */
class PriceAlertEventFactory extends Factory
{
    public function definition(): array
    {
        return ['public_id' => Str::uuid()->toString(), 'price_alert_id' => PriceAlert::factory(),
            'user_id' => fn (array $attributes): int => PriceAlert::findOrFail($attributes['price_alert_id'])->user_id,
            'alert_version' => 1, 'observed_timestamp' => now()->timestamp, 'asset' => 'bitcoin', 'currency' => 'USD', 'unit' => 'coin',
            'direction' => 'above', 'target_price' => '100.0000000000', 'previous_price' => '90.0000000000', 'observed_price' => '110.0000000000', 'provider' => 'coingecko'];
    }
}
