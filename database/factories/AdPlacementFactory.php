<?php

namespace Database\Factories;

use App\Models\AdPlacement;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<AdPlacement> */
class AdPlacementFactory extends Factory
{
    public function definition(): array
    {
        $key = fake()->unique()->randomElement(array_keys(config('advertising.placements')));

        return ['key' => $key, 'name' => config('advertising.placements.'.$key.'.name'), 'enabled' => false, 'provider' => 'direct'];
    }
}
