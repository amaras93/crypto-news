<?php

namespace Database\Factories;

use App\Models\User;
use App\Models\WatchlistItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<WatchlistItem> */
class WatchlistItemFactory extends Factory
{
    public function definition(): array
    {
        return ['user_id' => User::factory(), 'asset' => 'bitcoin', 'position' => 0];
    }
}
