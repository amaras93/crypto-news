<?php

namespace Database\Factories;

use App\Models\AdvertisingAudit;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<AdvertisingAudit> */
class AdvertisingAuditFactory extends Factory
{
    public function definition(): array
    {
        return ['actor_id' => User::factory()->admin(), 'action' => 'fixture.review', 'subject_type' => 'campaign', 'subject_id' => null, 'details' => ['fixture' => true]];
    }
}
