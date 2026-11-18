<?php

namespace Database\Factories;

use App\Models\Advertiser;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Advertiser> */
class AdvertiserFactory extends Factory
{
    public function definition(): array
    {
        return ['business_name' => fake()->company(), 'public_brand_name' => fake()->company(), 'status' => 'pending', 'contact_information' => null, 'internal_notes' => null, 'website' => null];
    }
}
