<?php

namespace Database\Factories;

use App\Models\EngagementSetting;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<EngagementSetting> */
class EngagementSettingFactory extends Factory
{
    public function definition(): array
    {
        return ['id' => 1, 'price_alerts_enabled' => false, 'newsletters_enabled' => false, 'real_email_enabled' => false,
            'daily_at' => '08:00', 'weekly_day' => 1, 'weekly_at' => '09:00', 'emails_per_minute' => 60, 'batch_size' => 100];
    }
}
