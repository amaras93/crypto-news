<?php

namespace Database\Seeders;

use App\Models\AiPipelineSetting;
use Illuminate\Database\Seeder;

class AiPipelineSettingSeeder extends Seeder
{
    /** Initialize safe controls without changing an existing operator configuration. */
    public function run(): void
    {
        AiPipelineSetting::firstOrCreate(['id' => 1], ['emergency_stop' => true, 'automatic_publishing' => false,
            'daily_limit_microusd' => min(1000000, config('ai.daily_limit_microusd')),
            'monthly_limit_microusd' => min(10000000, config('ai.monthly_limit_microusd')),
            'max_articles_per_day' => min(1, config('ai.max_articles_per_day')), 'max_concurrent_jobs' => 1,
            'max_translation_languages' => 7, 'enabled_category_ids' => []]);
    }
}
