<?php

namespace Database\Factories;

use App\Models\AiPipelineSetting;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<AiPipelineSetting> */
class AiPipelineSettingFactory extends Factory
{
    public function definition(): array
    {
        return ['id' => 1, 'candidate_processing_enabled' => false, 'generation_enabled' => false,
            'translation_enabled' => false, 'scheduled_generation_enabled' => false, 'emergency_stop' => true,
            'provider' => 'openai', 'model' => '', 'daily_limit_microusd' => 0, 'monthly_limit_microusd' => 0,
            'max_articles_per_day' => 0, 'max_concurrent_jobs' => 1, 'max_translation_languages' => 0];
    }
}
