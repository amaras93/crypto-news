<?php

namespace Database\Seeders;

use App\Models\EngagementSetting;
use Illuminate\Database\Seeder;

class EngagementSettingSeeder extends Seeder
{
    /** Initialize stopped controls; never subscribe or modify an existing reader. */
    public function run(): void
    {
        EngagementSetting::firstOrCreate(['id' => 1], ['price_alerts_enabled' => false, 'newsletters_enabled' => false, 'real_email_enabled' => false]);
    }
}
