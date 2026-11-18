<?php

namespace Database\Factories;

use App\Models\PriceAlert;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<PriceAlert> */
class PriceAlertFactory extends Factory
{
    public function definition(): array
    {
        return ['user_id' => User::factory(), 'asset' => 'bitcoin', 'currency' => 'USD', 'unit' => 'coin', 'target_price' => '100.0000000000', 'direction' => 'above', 'enabled' => true, 'in_app' => true, 'email' => false, 'version' => 1];
    }
}
