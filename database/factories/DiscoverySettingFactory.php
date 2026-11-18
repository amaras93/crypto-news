<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

class DiscoverySettingFactory extends Factory
{
    public function definition(): array
    {
        return ['id' => 1, 'enabled' => false, 'max_candidates_per_run' => 25, 'max_sources_per_run' => 3, 'default_polling_minutes' => 60];
    }
}
