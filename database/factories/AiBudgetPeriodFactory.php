<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

class AiBudgetPeriodFactory extends Factory
{
    public function definition(): array
    {
        return ['period_key' => 'day:'.now()->utc()->toDateString()];
    }
}
